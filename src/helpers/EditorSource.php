<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use Craft;
use craft\elements\Asset;
use RuntimeException;

/** Resolves an accepted editor result without replacing the original asset. */
class EditorSource
{
    // Public Methods
    // =========================================================================

    /**
     * Only the owner of a completed operation may reuse its preview, for the same original.
     * Repeated in the worker so expiration or lost access cannot silently revert to the original.
     *
     * @throws RuntimeException
     */
    public static function resolve(Asset $original, int $userId, ?string $sourceToken): Asset
    {
        if ($sourceToken === null || $sourceToken === '') {
            return $original;
        }

        $status = JobStatus::get($sourceToken);
        if (!self::isOwnedSource($status, (int) $original->id, $userId)) {
            throw new RuntimeException('This editor result expired or is unavailable. Undo to an earlier image or download your edits before reopening.');
        }

        $preview = Craft::$app->getAssets()->getAssetById((int) $status['previewId']);
        $user = Craft::$app->getUsers()->getUserById($userId);
        if (!$preview || !$user || !$preview->canView($user)) {
            throw new RuntimeException('The editor source image is no longer available. Download your edits before reopening.');
        }

        return $preview;
    }

    /** Checks the complete source binding before resolving any asset ID. */
    public static function isOwnedSource(?array $status, int $assetId, int $userId): bool
    {
        return $status !== null &&
            $assetId > 0 && $userId > 0 &&
            ($status['status'] ?? null) === 'complete' &&
            (int) ($status['assetId'] ?? 0) === $assetId &&
            (int) ($status['userId'] ?? 0) === $userId &&
            (int) ($status['previewId'] ?? 0) > 0 &&
            (int) $status['previewId'] !== $assetId;
    }
}
