<?php
/**
 * Mux webhook receiver.
 *
 * Register this URL in the Mux dashboard (Settings -> Webhooks) and copy the
 * signing secret into MUX_WEBHOOK_SECRET. Every request is verified against
 * that secret; unsigned or tampered payloads are rejected.
 */
require_once('config.php');
require_once('assets/libs/DB/vendor/autoload.php');
require_once('assets/includes/services/class-mux-client.php');
require_once('assets/includes/services/class-video-pipeline.php');

header('Content-Type: application/json');

$rawBody   = file_get_contents('php://input');
$signature = isset($_SERVER['HTTP_MUX_SIGNATURE']) ? $_SERVER['HTTP_MUX_SIGNATURE'] : '';

if (!MuxClient::verifyWebhookSignature($rawBody, $signature, getenv('MUX_WEBHOOK_SECRET'))) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => 'Invalid webhook signature'));
    exit();
}

$event = json_decode($rawBody, true);

if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Malformed payload'));
    exit();
}

$mysqli = new mysqli($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name);

if ($mysqli->connect_errno) {
    http_response_code(503);
    echo json_encode(array('ok' => false, 'error' => 'Database unavailable'));
    exit();
}

$pipeline = new VideoPipeline(new MysqliDb($mysqli));
$handled  = $pipeline->applyMuxWebhookEvent($event);

echo json_encode(array('ok' => true, 'event' => $handled));
