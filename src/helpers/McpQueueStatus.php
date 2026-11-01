<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use Craft;
use craft\db\Connection;
use craft\db\Query;
use craft\queue\Queue;
use Throwable;

/** Read-only observation of Craft's queue; never expires, reserves, retries or releases jobs. */
class McpQueueStatus
{
    /** Read the configured Craft queue's primary database without deserializing job payloads. */
    public static function read(?int $jobId): array
    {
        $empty = ['status' => 'not_queued', 'progress' => null, 'attempt' => null, 'reservationExpired' => false, 'error' => null];
        if ($jobId === null) {
            return $empty;
        }
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof Queue || !$queue->db instanceof Connection) {
            return array_replace($empty, ['status' => 'unavailable']);
        }
        try {
            // getJobInfo() mutates expired reservations; status() calls absent rows DONE.
            // Read only the native metadata needed to distinguish absence from completion.
            $row = $queue->db->usePrimary(static fn() => (new Query())
                ->select(['fail', 'timeUpdated', 'ttr', 'timePushed', 'delay', 'attempt', 'progress'])
                ->from($queue->tableName)->where(['id' => $jobId])->one($queue->db));
            return self::snapshot($row ?: null, time());
        } catch (Throwable) {
            return array_replace($empty, ['status' => 'unavailable']);
        }
    }

    /** Interpret a native queue row without changing its reservation or exposing raw error text. */
    public static function snapshot(?array $row, int $now): array
    {
        if ($row === null) {
            return ['status' => 'missing', 'progress' => null, 'attempt' => null, 'reservationExpired' => false, 'error' => null];
        }
        $reserved = !empty($row['timeUpdated']);
        $failed = (bool)$row['fail'];
        $delayed = (int)$row['timePushed'] + (int)$row['delay'] > $now;
        return [
            'status' => $failed ? 'failed' : ($reserved ? 'reserved' : ($delayed ? 'delayed' : 'waiting')),
            'progress' => max(0, min(100, (int)$row['progress'])) / 100,
            'attempt' => (int)$row['attempt'],
            'reservationExpired' => !$failed && $reserved && (int)$row['timeUpdated'] < $now - (int)$row['ttr'],
            'error' => $failed ? 'Craft marked this queue job as failed. An operator can inspect its details in the Craft queue manager.' : null,
        ];
    }

    /** Keep durable processing outcomes distinct from native execution state. */
    public static function execution(string $stage, array $queue): string
    {
        if ($queue['status'] === 'failed') {
            return 'failed';
        }
        if ($queue['status'] === 'reserved') {
            return $queue['reservationExpired'] ? 'interrupted' : 'running';
        }
        if (in_array($queue['status'], ['waiting', 'delayed'], true)) {
            return 'queued';
        }
        if (in_array($stage, ['complete', 'attachment_conflict', 'attachment_failed'], true)) {
            return 'finished';
        }
        if (in_array($stage, ['failed', 'storage_failed', 'uncertain'], true)) {
            return 'failed';
        }
        return match ($queue['status']) {
            'missing' => 'interrupted',
            'not_queued' => 'not_queued',
            default => 'unknown',
        };
    }

    /** Prevent legacy polling clients from waiting forever on an abandoned checkpoint. */
    public static function stage(string $checkpoint, string $execution): string
    {
        if (!in_array($checkpoint, ['queued', 'processing', 'generated', 'storing', 'attaching'], true)) {
            return $checkpoint;
        }
        return match ($execution) {
            'queued' => 'queued',
            'running' => $checkpoint === 'queued' ? 'processing' : $checkpoint,
            'failed' => 'queue_failed',
            'interrupted', 'not_queued' => 'interrupted',
            'unknown' => 'unknown',
            default => $checkpoint,
        };
    }
}
