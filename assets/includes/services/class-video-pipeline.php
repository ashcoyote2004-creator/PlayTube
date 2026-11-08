<?php
/**
 * Upload -> storage -> processing workflow.
 *
 *   raw file --upload--> Vidara.so (storage, permanent copy)
 *            \--direct upload--> Mux (transcoding, adaptive streaming)
 *
 * Every step is recorded in the `video_pipeline` table so the workflow can be
 * polled after the request has finished. No webhook or deployed callback URL
 * is required.
 */
class VideoPipeline
{
    const TABLE = 'video_pipeline';

    protected $db;
    protected $vidara;
    protected $mux;

    public function __construct($db = null, VidaraClient $vidara = null, MuxClient $mux = null)
    {
        $this->db     = $db;
        $this->vidara = $vidara;
        $this->mux    = $mux;
    }

    public function vidara()
    {
        if ($this->vidara === null) {
            $this->vidara = new VidaraClient();
        }

        return $this->vidara;
    }

    public function mux()
    {
        if ($this->mux === null) {
            $this->mux = new MuxClient();
        }

        return $this->mux;
    }

    public function ensureSchema()
    {
        return $this->db->rawQuery("
            CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL DEFAULT '0',
                `title` varchar(255) NOT NULL DEFAULT '',
                `storage_provider` varchar(32) NOT NULL DEFAULT 'vidara',
                `vidara_filecode` varchar(64) NOT NULL DEFAULT '',
                `vidara_url` varchar(255) NOT NULL DEFAULT '',
                `vidara_status` varchar(32) NOT NULL DEFAULT '',
                `mux_upload_id` varchar(64) NOT NULL DEFAULT '',
                `mux_asset_id` varchar(64) NOT NULL DEFAULT '',
                `mux_playback_id` varchar(64) NOT NULL DEFAULT '',
                `mux_status` varchar(32) NOT NULL DEFAULT '',
                `error` text NOT NULL,
                `created_at` int(11) NOT NULL DEFAULT '0',
                `updated_at` int(11) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `mux_asset_id` (`mux_asset_id`),
                KEY `vidara_filecode` (`vidara_filecode`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Upload a raw video file: store it on Vidara and hand a copy to Mux for
     * processing. Vidara's API only exposes a watch-page URL (not a direct file
     * URL), so the file is sent to Mux directly instead of being re-fetched.
     */
    public function createFromUploadedFile($filePath, array $meta = array())
    {
        $this->assertVideoFile($filePath, isset($meta['filename']) ? $meta['filename'] : '');

        $title = !empty($meta['title']) ? $meta['title'] : basename($filePath);
        $id    = $this->createRow($title, $meta);

        try {
            $stored = $this->vidara()->uploadFile($filePath, $title);
            $this->updateRow($id, array(
                'vidara_filecode' => $stored['filecode'],
                'vidara_url'      => $stored['url'],
                'vidara_status'   => 'processing',
            ));
        } catch (Exception $e) {
            $this->failRow($id, 'Vidara: ' . $e->getMessage());
            throw $e;
        }

        try {
            $upload = $this->mux()->createDirectUpload();
            $this->updateRow($id, array(
                'mux_upload_id' => $upload['upload_id'],
                'mux_status'    => 'uploading',
            ));

            $this->mux()->uploadFileToUrl($upload['upload_url'], $filePath);

            $this->resolveMuxAsset($id);
        } catch (Exception $e) {
            $this->failRow($id, 'Mux: ' . $e->getMessage());
            throw $e;
        }

        return $this->find($id);
    }

    /**
     * Hand an already hosted, publicly reachable video file to Mux.
     */
    public function createFromUrl($url, array $meta = array())
    {
        if (!preg_match('#^https?://#i', (string) $url)) {
            throw new RuntimeException('Only http(s) video URLs are supported');
        }

        $title = !empty($meta['title']) ? $meta['title'] : basename(parse_url($url, PHP_URL_PATH));
        $id    = $this->createRow($title, $meta);

        try {
            $asset = $this->mux()->createAssetFromUrl($url);
            $this->updateRow($id, array(
                'storage_provider' => 'remote',
                'mux_asset_id'     => $asset['asset_id'],
                'mux_playback_id'  => $asset['playback_id'],
                'mux_status'       => $asset['status'],
            ));
        } catch (Exception $e) {
            $this->failRow($id, 'Mux: ' . $e->getMessage());
            throw $e;
        }

        return $this->find($id);
    }

    /**
     * Create a Mux upload URL for a client-side (browser) upload.
     */
    public function startDirectUpload(array $meta = array())
    {
        $id     = $this->createRow(!empty($meta['title']) ? $meta['title'] : 'Untitled', $meta);
        $upload = $this->mux()->createDirectUpload();

        $this->updateRow($id, array(
            'storage_provider' => 'mux',
            'mux_upload_id'    => $upload['upload_id'],
            'mux_status'       => 'waiting',
        ));

        return array(
            'id'         => (int) $id,
            'upload_url' => $upload['upload_url'],
            'upload_id'  => $upload['upload_id'],
        );
    }

    /**
     * Poll both providers and store their current state.
     */
    public function refresh($id)
    {
        $row = $this->find($id);
        if (empty($row)) {
            throw new RuntimeException('Pipeline entry was not found');
        }

        if ($row['mux_status'] === 'ready' && !empty($row['mux_playback_id'])) {
            return $row; // Finished videos need no further provider requests.
        }

        $updates = array('error' => '');

        if (!empty($row['vidara_filecode']) && $row['vidara_status'] !== 'active') {
            $info = $this->vidara()->getFileInfo($row['vidara_filecode']);
            if (!empty($info['status'])) {
                $updates['vidara_status'] = $info['status'];
            }
        }

        if (empty($row['mux_asset_id']) && !empty($row['mux_upload_id'])) {
            $upload = $this->mux()->getUpload($row['mux_upload_id']);
            if (!empty($upload['asset_id'])) {
                $row['mux_asset_id'] = $upload['asset_id'];
                $updates['mux_asset_id'] = $upload['asset_id'];
            }
            if (in_array($upload['status'], array('cancelled', 'timed_out', 'errored'), true)) {
                $updates['mux_status'] = 'error';
                $updates['error'] = 'Mux upload ' . $upload['status'];
            }
        }

        if (!empty($row['mux_asset_id'])) {
            $asset = $this->mux()->getAsset($row['mux_asset_id']);
            $updates['mux_status'] = $asset['status'];
            if (!empty($asset['playback_id'])) {
                $updates['mux_playback_id'] = $asset['playback_id'];
            }
            if ($asset['status'] === 'errored') {
                $updates['error'] = 'Mux: asset processing failed';
            }
        }

        if (!empty($updates)) {
            $this->updateRow($id, $updates);
        }

        return $this->find($id);
    }

    public function find($id)
    {
        // PlayTube's DB wrapper defaults to objects and resets after each query.
        return $this->db->arrayBuilder()->where('id', (int) $id)->getOne(self::TABLE);
    }

    /**
     * Shape a stored row for an API response: playback and poster URLs are
     * derived here so the browser only ever receives public identifiers.
     */
    public function publicView(array $row)
    {
        if (empty($row)) {
            return null;
        }

        $playbackId = !empty($row['mux_playback_id']) ? $row['mux_playback_id'] : '';

        return array(
            'id'             => (int) $row['id'],
            'title'          => $row['title'],
            'storage'        => array(
                'provider' => $row['storage_provider'],
                'filecode' => $row['vidara_filecode'],
                'url'      => $row['vidara_url'],
                'status'   => $row['vidara_status'],
            ),
            'processing'     => array(
                'asset_id'    => $row['mux_asset_id'],
                'playback_id' => $playbackId,
                'status'      => $row['mux_status'],
                'playback_url' => $playbackId ? MuxClient::playbackUrl($playbackId) : '',
                'thumbnail_url' => $playbackId ? MuxClient::thumbnailUrl($playbackId) : '',
            ),
            'error'          => $row['error'],
            'updated_at'     => (int) $row['updated_at'],
        );
    }

    protected function createRow($title, array $meta)
    {
        $this->ensureSchema();

        $now = time();

        $id = $this->db->insert(self::TABLE, array(
            'user_id'          => isset($meta['user_id']) ? (int) $meta['user_id'] : 0,
            'title'            => mb_substr((string) $title, 0, 255),
            'storage_provider' => 'vidara',
            'mux_status'       => 'pending',
            'error'            => '',
            'created_at'       => $now,
            'updated_at'       => $now,
        ));

        if (!$id) {
            throw new RuntimeException('Unable to save the video processing entry');
        }

        return $id;
    }

    protected function updateRow($id, array $data)
    {
        $data['updated_at'] = time();
        $this->db->where('id', (int) $id)->update(self::TABLE, $data);
    }

    protected function failRow($id, $message)
    {
        $this->updateRow($id, array(
            'error'      => mb_substr($message, 0, 1000),
            'mux_status' => 'error',
        ));
    }

    /**
     * Check once now; subsequent browser polls resolve asynchronous processing.
     */
    protected function resolveMuxAsset($id)
    {
        try {
            $this->refresh($id);
        } catch (Exception $e) {
            // A transient status request must not repeat a completed upload.
        }
    }

    protected function assertVideoFile($filePath, $originalName = '')
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('No uploaded video file was found');
        }

        $allowed   = array('mp4', 'mov', 'm4v', 'mkv', 'webm', 'avi', 'flv', 'wmv', 'mpeg', 'mpg', '3gp');
        $extension = strtolower(pathinfo($originalName !== '' ? $originalName : $filePath, PATHINFO_EXTENSION));

        if ($extension !== '' && !in_array($extension, $allowed, true)) {
            throw new RuntimeException('Unsupported video format');
        }

        // Browsers hand over a temp path without an extension, so the detected
        // content type is checked as well.
        $mimeType = mime_content_type($filePath);
        if ($mimeType && strpos($mimeType, 'video/') !== 0 && $mimeType !== 'application/octet-stream') {
            throw new RuntimeException('Unsupported video format');
        }
    }
}
