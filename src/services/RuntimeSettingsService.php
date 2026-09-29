<?php

namespace arjanbrinkman\craftimageenhancer\services;

use arjanbrinkman\craftimageenhancer\models\Settings;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\base\Component;
use yii\base\InvalidConfigException;

class RuntimeSettingsService extends Component
{
	private const TABLE = '{{%imageenhancer_runtime_settings}}';
	private const WRITTEN_COLUMNS = [
		'qualityCheckEnabled',
		'creativeEnhancementPromptOverride',
		'faceBlurDetectionPromptOverride',
		'dateCreated',
		'dateUpdated',
		'uid',
	];

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

	/**
	 * @throws InvalidConfigException if the runtime settings table is missing or its migrations are pending
	 * @throws \yii\db\Exception if the write fails
	 */
	public function setQualityCheckEnabled(bool $enabled): void
	{
		$this->setRuntimeSettings(
			$enabled,
			$this->getCreativeEnhancementPromptOverride(),
			$this->getFaceBlurDetectionPromptOverride(),
		);
	}

	/**
	 * Persists the runtime settings row. The table is created by the plugin's install/upgrade
	 * migrations; this method never alters the schema.
	 *
	 * @throws InvalidConfigException if the runtime settings table is missing or its migrations are pending
	 * @throws \yii\db\Exception if the write fails
	 */
	public function setRuntimeSettings(
		bool $enabled,
		?string $creativeEnhancementPromptOverride,
		?string $faceBlurDetectionPromptOverride,
	): void {
		if (!$this->hasCurrentSchema()) {
			$message = 'The Image Enhancer runtime settings table is missing or outdated. Run `php craft migrate/all` (or reinstall the plugin) to apply its migrations.';
			Craft::error('ImageEnhancer: Could not save runtime settings: ' . $message, __METHOD__);
			throw new InvalidConfigException($message);
		}

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

	/**
	 * Returns the single runtime settings row, or an empty array (defaults) when the table
	 * is missing (plugin migrations not yet applied) or cannot be read.
	 */
	private function getRuntimeSettingsRow(): array
	{
		try {
			if (!$this->tableExists()) {
				Craft::warning('ImageEnhancer: Runtime settings table is missing; using defaults. Run pending plugin migrations.', __METHOD__);
				return [];
			}

			$row = (new Query())
				->from(self::TABLE)
				->one();
		} catch (\Throwable $e) {
			Craft::warning('ImageEnhancer: Could not read runtime settings: ' . $e->getMessage(), __METHOD__);
			return [];
		}

		return is_array($row) ? $row : [];
	}

	/**
	 * Checks for the table without issuing a failing query, which would abort an
	 * enclosing PostgreSQL transaction (e.g. during an asset save).
	 */
	private function tableExists(): bool
	{
		return Craft::$app->getDb()->tableExists(self::TABLE);
	}

	/**
	 * Checks that the install/upgrade migrations have created every column this service writes.
	 */
	private function hasCurrentSchema(): bool
	{
		$table = Craft::$app->getDb()->getTableSchema(self::TABLE);
		if ($table === null) {
			return false;
		}

		foreach (self::WRITTEN_COLUMNS as $column) {
			if ($table->getColumn($column) === null) {
				return false;
			}
		}

		return true;
	}

	private function normalizePromptOverride(?string $prompt): ?string
	{
		$prompt = trim((string) $prompt);

		return $prompt !== '' ? $prompt : null;
	}
}
