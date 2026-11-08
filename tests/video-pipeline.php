<?php
// Standalone regression tests; fake providers and in-memory rows only.
require_once(__DIR__ . '/../assets/includes/services/cloud-upload.php');

define('IS_LOGGED', true);
define('T_UPLOADED', 'uploaded');
function PT_IsAdmin() { return true; }
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
function rejects($callback, $message) {
    try { $callback(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
}

class PipelineTestDb {
    public $rows = array();
    public $mode = 'object';
    private $filters = array();
    public function rawQuery($query) { return array(); }
    public function arrayBuilder() { $this->mode = 'array'; return $this; }
    public function where($key, $value) { $this->filters[$key] = $value; return $this; }
    public function insert($table, $data) {
        check(isset($data['error']), 'A strict SQL insert needs an explicit error value');
        $id = count(isset($this->rows[$table]) ? $this->rows[$table] : array()) + 1;
        $this->rows[$table][$id] = array_merge(array(
            'vidara_filecode' => '', 'vidara_url' => '', 'vidara_status' => '',
            'mux_upload_id' => '', 'mux_asset_id' => '', 'mux_playback_id' => ''
        ), $data, array('id' => $id));
        return $id;
    }
    public function getOne($table) {
        $filters = $this->filters;
        $mode = $this->mode;
        $this->filters = array();
        $this->mode = 'object'; // Matches PlayTube's DB reset behavior.
        foreach (isset($this->rows[$table]) ? $this->rows[$table] : array() as $row) {
            foreach ($filters as $key => $value) {
                if (!isset($row[$key]) || $row[$key] != $value) { continue 2; }
            }
            return $mode === 'array' ? $row : (object) $row;
        }
        return null;
    }
    public function update($table, $data) {
        $row = $this->getOne($table);
        if (!$row) { return false; }
        $id = is_array($row) ? $row['id'] : $row->id;
        $this->rows[$table][$id] = array_merge($this->rows[$table][$id], $data);
        return true;
    }
}
class PipelineTestVidara extends VidaraClient {
    public $uploads = 0;
    public $reads = 0;
    public function __construct() {}
    public function uploadFile($path, $title = null) {
        $this->uploads++;
        return array('filecode' => 'fixture-file', 'url' => 'https://example.invalid/v/fixture');
    }
    public function getFileInfo($code) { $this->reads++; return array('status' => 'active'); }
}
class PipelineTestMux extends MuxClient {
    public $uploads = 0;
    public $reads = 0;
    public $status = 'preparing';
    public $uploadStatus = 'asset_created';
    public $failRead = false;
    public function __construct() {}
    public function createDirectUpload(array $settings = null) {
        return array('upload_id' => 'fixture-upload', 'upload_url' => 'https://example.invalid/upload');
    }
    public function uploadFileToUrl($url, $path) { $this->uploads++; }
    public function getUpload($id) {
        $this->reads++;
        if ($this->failRead) { throw new RuntimeException('Temporary provider outage'); }
        return array('status' => $this->uploadStatus,
            'asset_id' => $this->uploadStatus === 'asset_created' ? 'fixture-asset' : '');
    }
    public function getAsset($id) {
        $this->reads++;
        return array('status' => $this->status, 'playback_id' => $this->status === 'ready' ? 'fixture-playback' : '');
    }
}
class PipelineTestVidaraResponse extends VidaraClient {
    public $response;
    public $filename;
    public function __construct() {}
    public function getUploadServer() { return 'https://example.invalid/upload'; }
    protected function request($method, $url, array $options = array()) {
        $this->filename = $options['post']['file']->getPostFilename();
        return $this->response;
    }
}

$file = tempnam(sys_get_temp_dir(), 'pipeline-');
$fileWithExtension = $file . '.mp4';
rename($file, $fileWithExtension);
file_put_contents($fileWithExtension, "\x00\x00\x00\x18ftypisom\x00\x00\x00\x00isommp42" . str_repeat("\x00", 128));
try {
    $db = new PipelineTestDb();
    $vidara = new PipelineTestVidara();
    $mux = new PipelineTestMux();
    $pipeline = new VideoPipeline($db, $vidara, $mux);
    $row = $pipeline->createFromUploadedFile($fileWithExtension, array('title' => 'Test video', 'user_id' => 42));
    check(is_array($row) && $row['id'] > 0, 'Pipeline must use explicit array rows');
    check($row['mux_status'] === 'preparing' && $row['vidara_filecode'] === 'fixture-file', 'Both providers should be persisted');
    check($vidara->uploads === 1 && $mux->uploads === 1, 'File should reach both providers once');
    $mux->status = 'ready';
    $ready = $pipeline->refresh($row['id']);
    $stored = (new VideoPipeline($db))->find($row['id']);
    check($stored['mux_status'] === 'ready' && $stored['mux_playback_id'] === 'fixture-playback', 'Polling must persist readiness without webhooks');
    check($pipeline->publicView($ready)['processing']['playback_url'] === 'https://stream.mux.com/fixture-playback.m3u8', 'Playback URL must be HLS');
    $reads = $mux->reads + $vidara->reads;
    $pipeline->refresh($row['id']);
    check($reads === $mux->reads + $vidara->reads, 'Completed videos must stop provider reads');
    check($vidara->uploads === 1 && $mux->uploads === 1, 'Polling must not create duplicate uploads');
    echo "PASS upload -> both providers -> polling -> persisted playback; completed reads stop\n";

    $mux->failRead = true;
    $row2 = $pipeline->createFromUploadedFile($fileWithExtension, array('title' => 'Transient test', 'user_id' => 42));
    rejects(function () use ($pipeline, $row2) { $pipeline->refresh($row2['id']); }, 'Transient failures must be visible');
    check($pipeline->find($row2['id'])['mux_status'] !== 'error', 'Transient status failure must not mark the video permanently failed');
    $mux->failRead = false;
    $mux->uploadStatus = 'timed_out';
    check($pipeline->refresh($row2['id'])['mux_status'] === 'error', 'Confirmed upload timeout must be reported');
    echo "PASS transient polling errors and terminal upload errors\n";

    $responseClient = new PipelineTestVidaraResponse();
    foreach (array(
        array('filecode' => 'flat'),
        array('result' => array('filecode' => 'object')),
        array('result' => array(array('filecode' => 'list')))
    ) as $response) {
        $responseClient->response = $response;
        check(!empty($responseClient->uploadFile($fileWithExtension, 'Human title')['filecode']), 'Vidara result must unwrap');
        check(substr($responseClient->filename, -4) === '.mp4', 'Vidara upload filename must retain a video extension');
    }
    rejects(function () use ($pipeline, $fileWithExtension) {
        $pipeline->createFromUploadedFile($fileWithExtension, array('filename' => 'bad.php'));
    }, 'Unsupported extension must be rejected before provider upload');
    echo "PASS Vidara envelopes, filename extension and invalid file rejection\n";

    $original = array();
    foreach (array('VIDARA_API_KEY', 'MUX_TOKEN_ID', 'MUX_SECRET_KEY') as $name) {
        $original[$name] = getenv($name);
        putenv($name . '=isolated-test-placeholder');
    }
    try {
        $db->rows[T_UPLOADED][5] = array('id' => 5, 'user_id' => 42, 'path' => 'upload/videos/test.mp4');
        $_SESSION['cloud_video_pipeline'][5] = $row['id'];
        $view = PT_CloudVideoForPublishing($db, 5, 'upload/videos/test.mp4', 42);
        check($view['processing']['status'] === 'ready', 'Ready owned cloud video should publish');
        rejects(function () use ($db) { PT_CloudVideoForPublishing($db, 5, 'upload/videos/other.mp4', 42); }, 'Forged file path must fail');
        rejects(function () use ($db) { PT_CloudVideoForPublishing($db, 5, 'upload/videos/test.mp4', 99); }, 'Different upload owner must fail');
        $_SESSION['cloud_video_pipeline'][5] = $row2['id'];
        rejects(function () use ($db) { PT_CloudVideoForPublishing($db, 5, 'upload/videos/test.mp4', 42); }, 'Unready pipeline must not publish');
    } finally {
        foreach ($original as $name => $value) { putenv($value === false ? $name : $name . '=' . $value); }
    }
    echo "PASS publishing requires ready playback, owned upload and matching staged path\n";
    check(!method_exists($pipeline, 'applyMuxWebhookEvent') && !method_exists($mux, 'verifyWebhookSignature'), 'No webhook dependency should remain');
    echo "PASS no webhook dependency\n";
} finally {
    unlink($fileWithExtension);
}
