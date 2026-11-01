<?php

declare(strict_types=1);

require __DIR__ . '/../src/helpers/McpQueueStatus.php';
use arjanbrinkman\craftimageenhancer\helpers\McpQueueStatus as Status;

$checks = 0;
$check = static function(bool $value, string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException($label);
    $checks++;
};
$row = ['fail' => false, 'timeUpdated' => null, 'ttr' => 900, 'timePushed' => 900, 'delay' => 0, 'attempt' => 0, 'progress' => 0];
$waiting = Status::snapshot($row, 1000);
$check($waiting['status'] === 'waiting' && Status::execution('processing', $waiting) === 'queued', 'Native waiting state takes precedence over stale checkpoint');
$check(Status::stage('processing', 'queued') === 'queued', 'Legacy polling observes retried queue state');
$delayed = Status::snapshot(array_replace($row, ['delay' => 200]), 1000);
$check($delayed['status'] === 'delayed', 'Delayed jobs remain queued');
$reserved = Status::snapshot(array_replace($row, ['timeUpdated' => 999, 'attempt' => 2, 'progress' => 35]), 1000);
$check($reserved['status'] === 'reserved' && $reserved['attempt'] === 2 && $reserved['progress'] === 0.35 && !$reserved['reservationExpired'], 'Native progress and attempts');
$check(Status::execution('queued', $reserved) === 'running' && Status::stage('queued', 'running') === 'processing', 'Reserved job is running before first checkpoint');
$expired = Status::snapshot(array_replace($row, ['timeUpdated' => 99]), 1000);
$check($expired['status'] === 'reserved' && $expired['reservationExpired'] && Status::execution('processing', $expired) === 'interrupted', 'Expired reservation does not claim healthy execution');
$failed = Status::snapshot(array_replace($row, ['fail' => true, 'timeUpdated' => 99, 'error' => 'SECRET']), 1000);
$check($failed['status'] === 'failed' && !$failed['reservationExpired'] && !str_contains(json_encode($failed), 'SECRET'), 'Failed queue state and sanitized error');
$check(Status::stage('processing', Status::execution('processing', $failed)) === 'queue_failed', 'Legacy polling stops on native failure');
$missing = Status::snapshot(null, 1000);
$check(Status::execution('processing', $missing) === 'interrupted' && Status::stage('processing', 'interrupted') === 'interrupted', 'Missing jobs never imply successful processing');
foreach (['complete', 'attachment_failed', 'attachment_conflict'] as $stage) {
    $check(Status::execution($stage, $missing) === 'finished' && Status::stage($stage, 'finished') === $stage, 'Durable outcome survives queue row removal');
}
foreach (['failed', 'uncertain', 'storage_failed'] as $stage) {
    $check(Status::execution($stage, $missing) === 'failed', 'Durable failure survives purging');
}
$check(Status::execution('queued', ['status' => 'unavailable']) === 'unknown', 'Unavailable queue is not treated as missing or complete');
$check(Status::execution('queued', ['status' => 'not_queued']) === 'not_queued', 'Enqueue failure distinct from row removal');
echo "Passed $checks MCP queue status checks.\n";
