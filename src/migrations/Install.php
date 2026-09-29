<?php

namespace arjanbrinkman\craftimageenhancer\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;

/**
 * Install migration.
 *
 * Creates the complete runtime settings schema on a fresh install. Craft marks the
 * plugin's existing `m*` migrations as applied after this runs (see
 * `craft\base\Plugin::install()`), so this must match the combined result of
 * `m260519_091500_create_runtime_settings_table` and
 * `m260623_120000_add_prompt_overrides_to_runtime_settings`.
 */
class Install extends Migration
{
	private const TABLE = '{{%imageenhancer_runtime_settings}}';

	/**
	 * @inheritdoc
	 */
	public function safeUp(): bool
	{
		// A previous install without an Install migration never dropped the table on
		// uninstall, so reuse it and fill in any missing columns.
		if (!$this->db->tableExists(self::TABLE)) {
			$this->createTable(self::TABLE, [
				'id' => $this->primaryKey(),
				'qualityCheckEnabled' => $this->boolean()->notNull()->defaultValue(true),
				'creativeEnhancementPromptOverride' => $this->text()->null(),
				'faceBlurDetectionPromptOverride' => $this->text()->null(),
				'dateCreated' => $this->dateTime()->notNull(),
				'dateUpdated' => $this->dateTime()->notNull(),
				'uid' => $this->uid(),
			]);
		} else {
			foreach (['creativeEnhancementPromptOverride', 'faceBlurDetectionPromptOverride'] as $column) {
				if (!$this->columnExists($column)) {
					$this->addColumn(self::TABLE, $column, $this->text()->null());
				}
			}
		}

		if (!(new Query())->from(self::TABLE)->exists($this->db)) {
			$now = Db::prepareDateForDb(new \DateTime());
			$this->insert(self::TABLE, [
				'qualityCheckEnabled' => true,
				'dateCreated' => $now,
				'dateUpdated' => $now,
				'uid' => StringHelper::UUID(),
			]);
		}

		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function safeDown(): bool
	{
		$this->dropTableIfExists(self::TABLE);

		return true;
	}

	/**
	 * Returns whether the runtime settings table has the given column.
	 */
	private function columnExists(string $column): bool
	{
		$table = $this->db->getTableSchema(self::TABLE, true);

		return $table !== null && $table->getColumn($column) !== null;
	}
}
