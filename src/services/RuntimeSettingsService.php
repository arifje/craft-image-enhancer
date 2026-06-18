<?php

namespace arjanbrinkman\craftimagequalitychecker\services;

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
		try {
			$value = (new Query())
				->select(['qualityCheckEnabled'])
				->from(self::TABLE)
				->scalar();
		} catch (\Throwable $e) {
			Craft::warning('ImageQualityChecker: Could not read runtime settings: ' . $e->getMessage(), __METHOD__);
			return true;
		}

		return $value === false ? true : (bool) $value;
	}

	public function setQualityCheckEnabled(bool $enabled): void
	{
		$now = Db::prepareDateForDb(new \DateTime());
		$db = Craft::$app->getDb();
		$exists = (new Query())
			->from(self::TABLE)
			->exists();

		if ($exists) {
			$db->createCommand()
				->update(self::TABLE, [
					'qualityCheckEnabled' => $enabled,
					'dateUpdated' => $now,
				])
				->execute();
			return;
		}

		$db->createCommand()
			->insert(self::TABLE, [
				'qualityCheckEnabled' => $enabled,
				'dateCreated' => $now,
				'dateUpdated' => $now,
				'uid' => StringHelper::UUID(),
			])
			->execute();
	}
}
