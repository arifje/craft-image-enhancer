<?php

namespace arjanbrinkman\craftimageenhancer\helpers;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\elements\Asset;

/**
 * Asset element helpers used by the jobs. Every save/delete runs with the plugin's
 * after-save analysis hook suppressed so generated files never queue a new analysis.
 */
class AssetHelper
{
	// Const Properties
	// =========================================================================

	public const ENHANCEMENT_PREVIEW_MARKER = '-enhancement-preview-';
	public const FACE_BLUR_PREVIEW_MARKER = '-face-blur-preview-';

	// Public Methods
	// =========================================================================

	/**
	 * Creates a new asset next to the original from a local file. Returns null (and logs the
	 * validation errors) when the element could not be saved.
	 *
	 * @throws \Throwable from the element save
	 */
	public static function createAssetFromFile(Asset $originalAsset, string $path, string $filename, ?int $uploaderId): ?Asset
	{
		$asset = new Asset();
		$asset->tempFilePath = $path;
		$asset->filename = $filename;
		$asset->newFolderId = $originalAsset->folderId;
		$asset->volumeId = $originalAsset->volumeId;
		$asset->uploaderId = $uploaderId;
		$asset->avoidFilenameConflicts = true;
		$asset->setScenario(Asset::SCENARIO_CREATE);

		$saved = ImageEnhancer::suppressAssetQueue(static fn(): bool => Craft::$app->getElements()->saveElement($asset));
		if (!$saved) {
			Craft::warning('ImageEnhancer: Could not save generated asset: ' . json_encode($asset->getErrors()), __METHOD__);

			return null;
		}

		return $asset;
	}

	/**
	 * Hard-deletes a generated asset (preview), so its file is removed immediately.
	 */
	public static function deleteGeneratedAsset(Asset $asset): void
	{
		try {
			ImageEnhancer::suppressAssetQueue(static fn(): bool => Craft::$app->getElements()->deleteElement($asset, true));
		} catch (\Throwable $e) {
			Craft::warning('ImageEnhancer: Could not delete generated asset ' . $asset->id . ' (' . get_class($e) . ').', __METHOD__);
		}
	}

	/**
	 * Returns a sanitized base filename (without extension) for derived files.
	 */
	public static function getSafeBaseName(Asset $asset): string
	{
		$baseName = pathinfo((string) $asset->filename, PATHINFO_FILENAME);

		return preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName) ?: 'image';
	}

	/**
	 * Returns a timestamped preview filename using the given marker.
	 */
	public static function getPreviewFilename(Asset $asset, string $marker): string
	{
		$extension = FileHelper::sanitizeExtension((string) pathinfo((string) $asset->filename, PATHINFO_EXTENSION));

		return self::getSafeBaseName($asset) . $marker . date('YmdHis') . ($extension !== '' ? '.' . $extension : '');
	}

	/**
	 * Whether a filename looks like a generated preview (marker followed by a 14-digit timestamp).
	 */
	public static function isPreviewFilename(string $filename): bool
	{
		return preg_match('/(?:-enhancement-preview-|-face-blur-preview-)\d{14}(?:[-_]\d+)?(?:\.[A-Za-z0-9]+)?$/', $filename) === 1;
	}
}
