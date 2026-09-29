<?php

namespace arjanbrinkman\craftimageenhancer\services;

use arjanbrinkman\craftimageenhancer\helpers\AssetHelper;
use arjanbrinkman\craftimageenhancer\helpers\FileHelper;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;

/**
 * Purges abandoned plugin artifacts. Registered as the `cleanup` component and run on
 * Craft's garbage collection (Gc::EVENT_RUN).
 */
class CleanupService extends Component
{
	// Const Properties
	// =========================================================================

	/** Previews older than this were abandoned (statuses expire after an hour). */
	public const PREVIEW_MAX_AGE = 86400;
	/** Temp files older than this belong to no running job (longest TTR is 40 minutes). */
	public const TEMP_FILE_MAX_AGE = 86400;
	/** Previews deleted per run, to keep a web-triggered GC run short. */
	public const PREVIEW_BATCH_SIZE = 100;

	// Public Methods
	// =========================================================================

	/**
	 * Deletes stale preview assets, expired generated videos and old plugin temp files.
	 * Each step is isolated so one failure does not block the others.
	 */
	public function purgeStale(): void
	{
		$this->runStep('preview assets', fn() => $this->purgeStalePreviewAssets());
		$this->runStep('generated videos', static fn() => ImageEnhancer::getInstance()->aiVideoGeneration->cleanupExpiredVideos());
		$this->runStep('temp files', static fn() => FileHelper::purgeStaleTempFiles(self::TEMP_FILE_MAX_AGE));
	}

	/**
	 * Hard-deletes preview assets (enhancement and face blur) older than PREVIEW_MAX_AGE.
	 * Previews that are used in any relation field are left alone.
	 *
	 * @return int Number of deleted previews
	 */
	public function purgeStalePreviewAssets(): int
	{
		$cutoff = new \DateTime('-' . self::PREVIEW_MAX_AGE . ' seconds', new \DateTimeZone('UTC'));

		$candidates = Asset::find()
			->kind(Asset::KIND_IMAGE)
			->filename(['*' . AssetHelper::ENHANCEMENT_PREVIEW_MARKER . '*', '*' . AssetHelper::FACE_BLUR_PREVIEW_MARKER . '*'])
			->dateCreated('< ' . $cutoff->format(\DateTimeInterface::ATOM))
			->site('*')
			->unique()
			->status(null)
			->limit(self::PREVIEW_BATCH_SIZE)
			->all();

		$candidates = array_filter(
			$candidates,
			static fn(Asset $asset): bool => AssetHelper::isPreviewFilename((string) $asset->filename),
		);
		if ($candidates === []) {
			return 0;
		}

		$relatedIds = (new Query())
			->select(['targetId'])
			->distinct()
			->from(Table::RELATIONS)
			->where(['targetId' => array_map(static fn(Asset $asset): int => (int) $asset->id, $candidates)])
			->column();
		$relatedIds = array_map('intval', $relatedIds);

		$deleted = 0;
		foreach ($candidates as $asset) {
			if (in_array((int) $asset->id, $relatedIds, true)) {
				continue;
			}

			AssetHelper::deleteGeneratedAsset($asset);
			$deleted++;
		}

		if ($deleted > 0) {
			Craft::info("ImageEnhancer: Deleted {$deleted} abandoned preview asset(s).", __METHOD__);
		}

		return $deleted;
	}

	// Private Methods
	// =========================================================================

	private function runStep(string $label, callable $step): void
	{
		try {
			$step();
		} catch (\Throwable $e) {
			Craft::warning('ImageEnhancer: Cleanup of ' . $label . ' failed (' . get_class($e) . ').', __METHOD__);
		}
	}
}
