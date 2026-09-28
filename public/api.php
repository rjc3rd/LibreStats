<?php
// JSON for the dashboard's live counter: api.php?live&site=example.com (logged-in users only).

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/report.php';
require __DIR__ . '/../lib/collect.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!ls_user()) {
    http_response_code(403);
    exit('{"error":"not logged in"}');
}
session_write_close();
$site = ls_find_site(ls_db(), (string) ($_GET['site'] ?? ''));
if (!$site) {
    http_response_code(404);
    exit('{"error":"no such site"}');
}
echo json_encode(ls_report_live(ls_db(), (int) $site['id']));
