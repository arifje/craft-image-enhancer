<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use Craft;

/**
 * Shared status cache for the article image jobs (enhance, face blur, video).
 *
 * The controller seeds the status at enqueue time (with ownership keys such as `userId`);
 * jobs merge their progress into it so those keys survive every update.
 */
class JobStatus
{
	// Const Properties
	// =========================================================================

	/** Status lifetime in seconds. Generated videos are retained for the same window. */
	public const TTL = 3600;

	// Public Methods
	// =========================================================================

	public static function tokenKey(string $token): string
	{
		return 'image-enhancer:article-image-enhancement:' . $token;
	}

	public static function assetKey(int $assetId): string
	{
		return 'image-enhancer:article-image-enhancement-asset:' . $assetId;
	}

	public static function get(string $token): ?array
	{
		$status = Craft::$app->getCache()->get(self::tokenKey($token));

		return is_array($status) ? $status : null;
	}

	/**
	 * Merges the payload into the existing token status and mirrors it to the asset key.
	 */
	public static function merge(string $token, int $assetId, array $payload): array
	{
		$status = array_merge(self::get($token) ?? [], $payload);
		$cache = Craft::$app->getCache();
		$cache->set(self::tokenKey($token), $status, self::TTL);
		$cache->set(self::assetKey($assetId), $status, self::TTL);

		return $status;
	}

	public static function isCanceled(string $token): bool
	{
		return (self::get($token)['status'] ?? null) === 'canceled';
	}
}
