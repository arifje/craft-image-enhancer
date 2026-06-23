<?php

namespace arjanbrinkman\craftimagequalitychecker\services;

use arjanbrinkman\craftimagequalitychecker\models\Settings;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\base\Component;

class RuntimeSettingsService extends Component
{
	private const TABLE = '{{%imagequalitychecker_runtime_settings}}';

	public function isQualityCheckEnabled(): bool
	{
		$row = $this->getRuntimeSettingsRow();
		$value = $row['qualityCheckEnabled'] ?? null;

		return $value === null ? true : (bool) $value;
	}

	public function getCreativeEnhancementPromptOverride(): string
	{
		return trim((string) ($this->getRuntimeSettingsRow()['creativeEnhancementPromptOverride'] ?? ''));
	}

	public function getFaceBlurDetectionPromptOverride(): string
	{
		return trim((string) ($this->getRuntimeSettingsRow()['faceBlurDetectionPromptOverride'] ?? ''));
	}

	public function hasCreativeEnhancementPromptOverride(): bool
	{
		return $this->getCreativeEnhancementPromptOverride() !== '';
	}

	public function hasFaceBlurDetectionPromptOverride(): bool
	{
		return $this->getFaceBlurDetectionPromptOverride() !== '';
	}

	public function getCreativeEnhancementPromptForRequest(Settings $settings): string
	{
		$prompt = $this->getCreativeEnhancementPromptOverride();
		if ($prompt === '') {
			$prompt = $settings->getEffectiveCreativeEnhancementPrompt();
		}

		return trim($prompt) . "\n\n" . $settings->getCreativeEnhancementTuningPrompt();
	}

	public function getFaceBlurDetectionPromptForRequest(Settings $settings): string
	{
		$prompt = $this->getFaceBlurDetectionPromptOverride();

		return $prompt !== '' ? $prompt : $settings->getEffectiveFaceBlurDetectionPrompt();
	}

	public function setQualityCheckEnabled(bool $enabled): void
	{
		$this->setRuntimeSettings(
			$enabled,
			$this->getCreativeEnhancementPromptOverride(),
			$this->getFaceBlurDetectionPromptOverride()
		);
	}

	public function setRuntimeSettings(
		bool $enabled,
		?string $creativeEnhancementPromptOverride,
		?string $faceBlurDetectionPromptOverride
	): void {
		$now = Db::prepareDateForDb(new \DateTime());
		$db = Craft::$app->getDb();
		$exists = (new Query())
			->from(self::TABLE)
			->exists();
		$data = [
			'qualityCheckEnabled' => $enabled,
			'creativeEnhancementPromptOverride' => $this->normalizePromptOverride($creativeEnhancementPromptOverride),
			'faceBlurDetectionPromptOverride' => $this->normalizePromptOverride($faceBlurDetectionPromptOverride),
			'dateUpdated' => $now,
		];

		if ($exists) {
			$db->createCommand()
				->update(self::TABLE, $data)
				->execute();
			return;
		}

		$db->createCommand()
			->insert(self::TABLE, array_merge($data, [
				'dateCreated' => $now,
				'uid' => StringHelper::UUID(),
			]))
			->execute();
	}

	private function getRuntimeSettingsRow(): array
	{
		try {
			$row = (new Query())
				->from(self::TABLE)
				->one();
		} catch (\Throwable $e) {
			Craft::warning('ImageQualityChecker: Could not read runtime settings: ' . $e->getMessage(), __METHOD__);
			return [];
		}

		return is_array($row) ? $row : [];
	}

	private function normalizePromptOverride(?string $prompt): ?string
	{
		$prompt = trim((string) $prompt);

		return $prompt !== '' ? $prompt : null;
	}
}
