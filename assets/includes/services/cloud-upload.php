<?php
require_once(__DIR__ . '/class-vidara-client.php');
require_once(__DIR__ . '/class-mux-client.php');
require_once(__DIR__ . '/class-video-pipeline.php');

// Keep the existing administrator-only cloud integration and normal uploader
// behavior for other users or installations without these three credentials.
function PT_CloudVideoEnabled()
{
    return IS_LOGGED && PT_IsAdmin()
        && getenv('VIDARA_API_KEY') && getenv('MUX_TOKEN_ID') && getenv('MUX_SECRET_KEY');
}

function PT_CloudVideoForPublishing($db, $uploadedId, $path, $userId)
{
    if (!PT_CloudVideoEnabled()) {
        return null;
    }

    $upload = $db->where('id', (int) $uploadedId)->where('user_id', (int) $userId)->getOne(T_UPLOADED);
    $pipelineId = isset($_SESSION['cloud_video_pipeline'][(int) $uploadedId])
        ? $_SESSION['cloud_video_pipeline'][(int) $uploadedId] : 0;
    if (!$upload || $upload->path !== $path || !$pipelineId) {
        throw new RuntimeException('Please finish the Vidara and Mux upload before publishing');
    }

    $pipeline = new VideoPipeline($db);
    $row = $pipeline->find($pipelineId);
    if (!$row || (int) $row['user_id'] !== (int) $userId
        || $row['mux_status'] !== 'ready' || empty($row['mux_playback_id'])) {
        throw new RuntimeException('Mux is still processing this video. Please wait before publishing');
    }

    return $pipeline->publicView($row);
}
