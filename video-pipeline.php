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

$action = !empty($_POST['action']) ? $_POST['action'] : (!empty($_GET['action']) ? $_GET['action'] : '');

try {
    $pipeline = new VideoPipeline($db);

    switch ($action) {
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

            pt_pipeline_respond(200, array(
                'ok'    => true,
                'video' => $pipeline->publicView($pipeline->refresh($id)),
            ));
    }

    pt_pipeline_respond(400, array('ok' => false, 'error' => 'Unknown action'));
} catch (Exception $e) {
    pt_pipeline_respond(422, array('ok' => false, 'error' => $e->getMessage()));
}
