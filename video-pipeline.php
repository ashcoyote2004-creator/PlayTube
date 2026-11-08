<?php
/**
 * Backend endpoint for the Vidara.so (storage) + Mux (processing) pipeline.
 *
 *   POST ?action=upload   multipart: video=<file>, title=<optional>
 *   POST ?action=url      url=<direct file url>, title=<optional>
 *   POST ?action=direct   creates a Mux upload URL for a browser-side upload
 *   GET  ?action=status&id=<id>   refresh and return one pipeline entry
 *
 * An administrator session is required: uploading consumes storage and
 * transcoding minutes, and the provider credentials never leave the server.
 */
require_once('./assets/init.php');

require_once('assets/includes/services/class-vidara-client.php');
require_once('assets/includes/services/class-mux-client.php');
require_once('assets/includes/services/class-video-pipeline.php');

header('Content-Type: application/json');

function pt_pipeline_respond($status, array $payload)
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

if (!IS_LOGGED || !PT_IsAdmin()) {
    pt_pipeline_respond(403, array('ok' => false, 'error' => 'Administrator session required'));
}

header('Cache-Control: no-store');
$action = !empty($_POST['action']) ? $_POST['action'] : (!empty($_GET['action']) ? $_GET['action'] : '');

if ($action !== 'status') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pt_pipeline_respond(405, array('ok' => false, 'error' => 'POST required'));
    }
    if (empty($_POST['hash']) || !PT_CheckMainSession($_POST['hash'])) {
        pt_pipeline_respond(400, array('ok' => false, 'error' => 'Invalid session token'));
    }
    if ($pt->config->upload_system !== 'on' || $pt->user->suspend_upload) {
        pt_pipeline_respond(403, array('ok' => false, 'error' => 'Uploads are disabled for this account'));
    }
}

try {
    $pipeline = new VideoPipeline($db);

    switch ($action) {
        case 'process':
            $uploadedId = !empty($_POST['uploaded_id']) ? (int) $_POST['uploaded_id'] : 0;
            $upload = $db->where('id', $uploadedId)->where('user_id', $pt->user->id)->getOne(T_UPLOADED);
            $root = realpath(__DIR__ . '/upload/videos');
            $path = $upload ? realpath($upload->path) : false;
            if (!$root || !$path || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
                pt_pipeline_respond(404, array('ok' => false, 'error' => 'Uploaded video was not found'));
            }
            $existingId = isset($_SESSION['cloud_video_pipeline'][$uploadedId])
                ? $_SESSION['cloud_video_pipeline'][$uploadedId] : 0;
            if ($existingId) {
                $row = $pipeline->refresh($existingId);
            } else {
                $row = $pipeline->createFromUploadedFile($path, array(
                    'title' => !empty($_POST['title']) ? $_POST['title'] : basename($path),
                    'filename' => basename($path),
                    'user_id' => $pt->user->id,
                ));
                $_SESSION['cloud_video_pipeline'][$uploadedId] = $row['id'];
            }
            pt_pipeline_respond(200, array('ok' => true, 'video' => $pipeline->publicView($row)));

        case 'upload':
            if (empty($_FILES['video']['tmp_name']) || !is_uploaded_file($_FILES['video']['tmp_name'])) {
                throw new RuntimeException('No video file was uploaded');
            }

            $row = $pipeline->createFromUploadedFile($_FILES['video']['tmp_name'], array(
                'title'    => !empty($_POST['title']) ? $_POST['title'] : $_FILES['video']['name'],
                'filename' => $_FILES['video']['name'],
                'user_id'  => $pt->user->id,
            ));

            pt_pipeline_respond(200, array(
                'ok'    => true,
                'video' => $pipeline->publicView($row),
            ));

        case 'url':
            if (empty($_POST['url'])) {
                throw new RuntimeException('A video URL is required');
            }

            $row = $pipeline->createFromUrl($_POST['url'], array(
                'title'   => !empty($_POST['title']) ? $_POST['title'] : '',
                'user_id' => $pt->user->id,
            ));

            pt_pipeline_respond(200, array(
                'ok'    => true,
                'video' => $pipeline->publicView($row),
            ));

        case 'direct':
            $upload = $pipeline->startDirectUpload(array(
                'title'   => !empty($_POST['title']) ? $_POST['title'] : 'Untitled',
                'user_id' => $pt->user->id,
            ));

            pt_pipeline_respond(200, array('ok' => true, 'upload' => $upload));

        case 'status':
            $id = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
            if ($id <= 0) {
                throw new RuntimeException('A pipeline id is required');
            }

            $pipeline->ensureSchema();
            pt_pipeline_respond(200, array(
                'ok'    => true,
                'video' => $pipeline->publicView($pipeline->refresh($id)),
            ));
    }

    pt_pipeline_respond(400, array('ok' => false, 'error' => 'Unknown action'));
} catch (Exception $e) {
    pt_pipeline_respond(422, array('ok' => false, 'error' => $e->getMessage()));
}
