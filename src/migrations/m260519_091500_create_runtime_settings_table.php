<?php

namespace arjanbrinkman\craftimageenhancer\migrations;

use craft\db\Query;
use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\StringHelper;

class m260519_091500_create_runtime_settings_table extends Migration
{
	private const TABLE = '{{%imageenhancer_runtime_settings}}';

	public function safeUp(): bool
	{
		if (!$this->db->tableExists(self::TABLE)) {
			$this->createTable(self::TABLE, [
				'id' => $this->primaryKey(),
				'qualityCheckEnabled' => $this->boolean()->notNull()->defaultValue(true),
				'dateCreated' => $this->dateTime()->notNull(),
				'dateUpdated' => $this->dateTime()->notNull(),
				'uid' => $this->uid(),
			]);
		}

		if (!(new Query())->from(self::TABLE)->exists()) {
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

	public function safeDown(): bool
	{
		$this->dropTableIfExists(self::TABLE);

		return true;
	}
}
