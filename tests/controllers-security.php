<?php

declare(strict_types=1);

// Dependency-free regression checks for the pure authorization/scope helpers added in the
// audit fixes. Only framework base classes are doubled; the helpers run as production code.
namespace craft\base {
    class Component {}
    class Model {}
    class Plugin {}
}

namespace craft\web {
    class Controller {}
}

namespace {
    use arjanbrinkman\craftimageenhancer\controllers\ArticleImageController;
    use arjanbrinkman\craftimageenhancer\controllers\NotificationsController;
    use arjanbrinkman\craftimageenhancer\ImageEnhancer;

    require __DIR__ . '/../src/ImageEnhancer.php';
    require __DIR__ . '/../src/controllers/ArticleImageController.php';
    require __DIR__ . '/../src/controllers/NotificationsController.php';

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        if (!$condition) {
            throw new RuntimeException($message);
        }
        $checks++;
    }

    function helper(string $class, string $method): Closure
    {
        $reflection = new ReflectionMethod($class, $method);

        return static fn(mixed ...$args) => $reflection->invoke(null, ...$args);
    }

    // Status ownership: statuses must carry the creating user's ID.
    $belongs = helper(ArticleImageController::class, 'statusBelongsToUser');
    check($belongs(['userId' => 7], 7) === true, 'Owner must match.');
    check($belongs(['userId' => '7'], 7) === true, 'Cache may round-trip IDs as strings.');
    check($belongs(['userId' => 8], 7) === false, 'Other users must not match.');
    check($belongs(['assetId' => 1], 7) === false, 'Legacy statuses without userId must be rejected.');
    check($belongs(['userId' => 0], 0) === false, 'Guests (id 0) must never match.');
    check($belongs(false, 7) === false, 'Cache misses must not match.');

    // Active statuses block starting a new operation.
    $active = helper(ArticleImageController::class, 'isActiveStatus');
    foreach (['queued', 'running', 'pending'] as $state) {
        check($active(['status' => $state]) === true, "$state must count as active.");
    }
    foreach (['complete', 'failed', 'canceled'] as $state) {
        check($active(['status' => $state]) === false, "$state must not count as active.");
    }
    check($active(null) === false, 'Missing status is not active.');

    // Cancel/reset: asset must match; owner or admin only.
    $manage = helper(ArticleImageController::class, 'canManageStatus');
    $status = ['assetId' => 10, 'userId' => 7, 'jobId' => '99'];
    check($manage($status, 10, 7, false) === true, 'Owner may cancel.');
    check($manage($status, 10, 8, false) === false, 'Non-owner may not cancel.');
    check($manage($status, 10, 8, true) === true, 'Admins may cancel any status.');
    check($manage($status, 11, 7, false) === false, 'Asset mismatch must be rejected.');
    check($manage($status, 11, 8, true) === false, 'Admins still need a matching asset.');
    check($manage(false, 10, 7, true) === false, 'No status means nothing to cancel.');

    // Preview binding comes only from the status (no filename heuristics).
    $bound = helper(ArticleImageController::class, 'isPreviewBoundToStatus');
    $status = ['assetId' => 10, 'previewId' => 20, 'userId' => 7];
    check($bound($status, 10, 20) === true, 'Status-bound preview must be accepted.');
    check($bound($status, 10, 21) === false, 'Unbound preview IDs must be rejected.');
    check($bound($status, 11, 20) === false, 'Preview must belong to the posted original.');
    check($bound(['assetId' => 10, 'previewId' => 10], 10, 10) === false, 'Original can never be its own preview.');
    check($bound(['assetId' => 10], 10, 20) === false, 'Statuses without a preview bind nothing.');
    check($bound(null, 10, 20) === false, 'Missing status binds nothing.');

    // After-save analysis is limited to the configured volume allow-list.
    check(ImageEnhancer::isVolumeHandleInScope('news', ['news', 'blog']) === true, 'Listed volume is in scope.');
    check(ImageEnhancer::isVolumeHandleInScope('private', ['news']) === false, 'Unlisted volume is out of scope.');
    check(ImageEnhancer::isVolumeHandleInScope('news', []) === false, 'An empty allow-list selects nothing.');
    check(ImageEnhancer::isVolumeHandleInScope(null, ['news']) === false, 'Volumeless assets are out of scope.');

    // Suppression is nesting-safe and restores state after exceptions.
    check(ImageEnhancer::isAssetQueueSuppressed() === false, 'Not suppressed by default.');
    $result = ImageEnhancer::suppressAssetQueue(static function() {
        ImageEnhancer::suppressAssetQueue(static fn() => null);
        check(ImageEnhancer::isAssetQueueSuppressed() === true, 'Inner call must not re-enable the outer scope.');

        return 'value';
    });
    check($result === 'value', 'Callback result must be returned.');
    check(ImageEnhancer::isAssetQueueSuppressed() === false, 'Suppression must end with the outer scope.');
    try {
        ImageEnhancer::suppressAssetQueue(static fn() => throw new LogicException('boom'));
    } catch (LogicException) {
    }
    check(ImageEnhancer::isAssetQueueSuppressed() === false, 'Exceptions must not leak suppression.');
    ImageEnhancer::$skipAssetQueue = true;
    check(ImageEnhancer::isAssetQueueSuppressed() === true, 'Legacy flag must still suppress.');
    ImageEnhancer::$skipAssetQueue = false;

    // Notification failures must never log the exception message (it can hold the webhook URL).
    $describe = helper(NotificationsController::class, 'describeFailure');
    $logged = $describe(new RuntimeException('https://hooks.slack.com/services/T000/B000/SECRET'));
    check($logged === 'RuntimeException', 'Only the exception class may be logged.');
    check(!str_contains($logged, 'SECRET'), 'Webhook URL must not be logged.');
    $sanitize = helper(NotificationsController::class, 'sanitizeSlackErrorCode');
    check($sanitize('channel_not_found') === 'channel_not_found', 'Keep Slack error codes.');
    check($sanitize('<b>Bad</b> token=xoxb') === 'bbadbtokenxoxb', 'Strip markup from Slack error codes.');
    check($sanitize(null) === 'unknown', 'Missing Slack error codes become unknown.');
    check($sanitize(['x']) === 'unknown', 'Non-string Slack error codes become unknown.');

    echo "Passed {$checks} controller security regression checks.\n";
}
