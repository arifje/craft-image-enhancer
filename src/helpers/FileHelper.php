<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper as CraftFileHelper;

/**
 * Temporary file helpers shared by the plugin's jobs and services.
 *
 * All plugin temp files live in `<Craft temp path>/image-enhancer/` with an unpredictable
 * name, so nothing is written to the shared system temp directory and stale files can be
 * purged by {@see \arjanbrinkman\craftimageenhancer\services\CleanupService}.
 */
class FileHelper
{
	// Const Properties
	// =========================================================================

	public const TEMP_DIRECTORY = 'image-enhancer';
	public const TEMP_PREFIX = 'image-enhancer-';

	// Public Methods
	// =========================================================================

	/**
	 * Returns (and creates) the plugin's private temp directory.
	 *
	 * @throws \yii\base\Exception
	 */
	public static function getTempDirectory(): string
	{
		$directory = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . self::TEMP_DIRECTORY;
		CraftFileHelper::createDirectory($directory);

		return $directory;
	}

	/**
	 * Returns a fresh, unpredictable temp file path. The file itself is not created.
	 *
	 * @throws \yii\base\Exception
	 */
	public static function createTempPath(string $extension = ''): string
	{
		$extension = self::sanitizeExtension($extension);

		return self::getTempDirectory() . DIRECTORY_SEPARATOR . self::TEMP_PREFIX . bin2hex(random_bytes(16)) .
			($extension !== '' ? '.' . $extension : '');
	}

	/**
	 * Returns a fresh temp file path that carries the asset's file extension.
	 *
	 * @throws \yii\base\Exception
	 */
	public static function createTempPathForAsset(Asset $asset): string
	{
		return self::createTempPath((string) pathinfo((string) $asset->filename, PATHINFO_EXTENSION));
	}

	/**
	 * Returns a lowercase alphanumeric extension, or an empty string when it is unsafe.
	 */
	public static function sanitizeExtension(string $extension): string
	{
		$extension = strtolower(ltrim($extension, '.'));

		return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : '';
	}

	/**
	 * Copies the asset's file to a local temp file (works for local and remote volumes).
	 * The caller owns the returned file and must delete it.
	 *
	 * @throws \RuntimeException if the file could not be copied
	 */
	public static function copyAssetToTemp(Asset $asset): string
	{
		try {
			$path = $asset->getCopyOfFile();
		} catch (\Throwable $e) {
			throw new \RuntimeException('Could not read the original asset file.', 0, $e);
		}

		clearstatcache(true, $path);
		if (!is_file($path) || (int) filesize($path) === 0) {
			self::delete($path);
			throw new \RuntimeException('Could not read the original asset file.');
		}

		return $path;
	}

	/**
	 * Deletes the given files if they exist. Null and empty paths are ignored.
	 */
	public static function delete(?string ...$paths): void
	{
		foreach ($paths as $path) {
			if ($path !== null && $path !== '' && is_file($path)) {
				@unlink($path);
			}
		}
	}

	/**
	 * Deletes plugin temp files older than the given age. Returns the number of deleted files.
	 *
	 * Also covers legacy `image-enhancer-*` files that older versions created with `tempnam()`
	 * in the Craft temp path and in the system temp directory.
	 *
	 * @throws \yii\base\Exception
	 */
	public static function purgeStaleTempFiles(int $maxAgeSeconds): int
	{
		$cutoff = time() - max(0, $maxAgeSeconds);
		$tempPath = Craft::$app->getPath()->getTempPath();
		$patterns = [
			self::getTempDirectory() . DIRECTORY_SEPARATOR . self::TEMP_PREFIX . '*',
			$tempPath . DIRECTORY_SEPARATOR . self::TEMP_PREFIX . '*',
			rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::TEMP_PREFIX . '*',
		];

		$deleted = 0;
		foreach ($patterns as $pattern) {
			foreach (glob($pattern) ?: [] as $path) {
				if (!is_file($path) || is_link($path) || (filemtime($path) ?: PHP_INT_MAX) >= $cutoff) {
					continue;
				}

				if (@unlink($path)) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}
}
