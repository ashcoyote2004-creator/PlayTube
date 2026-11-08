<?php
// Processing now uses authenticated provider polling, not incoming webhooks.
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(array('ok' => false, 'error' => 'Webhooks are disabled; use video-pipeline.php?action=status'));
