<?php
// GitHub Actions deploy hook for cheap cPanel hosting.
// It does NOT run deployment directly. It only validates a secret token and writes a flag.

declare(strict_types=1);

$appPath = '/home/tbkd2629/kost-simple-laravel';
$tokenFile = $appPath . '/storage/app/deploy-webhook-token';
$flagFile = $appPath . '/storage/app/deploy.flag';
$logFile = $appPath . '/storage/logs/deploy-hook.log';

function respond(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

function log_line(string $file, string $message): void
{
    $dir = dirname($file);
    if (! is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($file, '[' . date(DATE_ATOM) . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$expectedToken = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
$providedToken = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';

if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
    log_line($logFile, 'Rejected deploy hook: invalid token');
    respond(403, ['ok' => false, 'error' => 'forbidden']);
}

$raw = (string) file_get_contents('php://input');
$payload = json_decode($raw, true);
if (! is_array($payload)) {
    $payload = $_POST;
}

$branch = (string) ($payload['branch'] ?? 'main');
$commit = (string) ($payload['commit'] ?? '');

if ($branch !== 'main') {
    log_line($logFile, "Ignored deploy hook for branch={$branch}");
    respond(202, ['ok' => true, 'queued' => false, 'reason' => 'branch_ignored']);
}

if ($commit !== '' && ! preg_match('/^[a-f0-9]{7,40}$/i', $commit)) {
    respond(422, ['ok' => false, 'error' => 'invalid_commit']);
}

$flagDir = dirname($flagFile);
if (! is_dir($flagDir) && ! mkdir($flagDir, 0755, true) && ! is_dir($flagDir)) {
    respond(500, ['ok' => false, 'error' => 'cannot_create_flag_dir']);
}

$flag = 'branch=' . $branch . PHP_EOL;
if ($commit !== '') {
    $flag .= 'commit=' . $commit . PHP_EOL;
}
$flag .= 'queued_at=' . date(DATE_ATOM) . PHP_EOL;

if (file_put_contents($flagFile, $flag, LOCK_EX) === false) {
    respond(500, ['ok' => false, 'error' => 'cannot_write_flag']);
}

log_line($logFile, "Queued deploy branch={$branch} commit=" . ($commit ?: 'latest'));
respond(202, ['ok' => true, 'queued' => true, 'branch' => $branch, 'commit' => $commit ?: null]);
