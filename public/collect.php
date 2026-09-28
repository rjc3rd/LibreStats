<?php
// Endpoint for the tracking script. Always answers 204 No Content, whatever happened, so a page
// can learn nothing from it and never waits on it.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/collect.php';

header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
http_response_code(204);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    exit;
}
$body = file_get_contents('php://input', false, null, 0, 4096);
$hit = json_decode((string) $body, true);
if (!is_array($hit)) {
    exit;
}
try {
    ls_collect(ls_db(), $hit, $_SERVER, ls_config());
} catch (Throwable $e) {
    error_log('LibreStats collect: ' . $e->getMessage());
}
