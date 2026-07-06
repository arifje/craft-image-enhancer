<?php

namespace arjanbrinkman\craftimageenhancer\migrations;

use craft\db\Migration;

class m260623_120000_add_prompt_overrides_to_runtime_settings extends Migration
{
	private const TABLE = '{{%imageenhancer_runtime_settings}}';

	public function safeUp(): bool
	{
		if (!$this->columnExists('creativeEnhancementPromptOverride')) {
			$this->addColumn(self::TABLE, 'creativeEnhancementPromptOverride', $this->text()->null());
		}

		if (!$this->columnExists('faceBlurDetectionPromptOverride')) {
			$this->addColumn(self::TABLE, 'faceBlurDetectionPromptOverride', $this->text()->null());
		}

		return true;
	}

	public function safeDown(): bool
	{
		if ($this->columnExists('faceBlurDetectionPromptOverride')) {
			$this->dropColumn(self::TABLE, 'faceBlurDetectionPromptOverride');
		}

		if ($this->columnExists('creativeEnhancementPromptOverride')) {
			$this->dropColumn(self::TABLE, 'creativeEnhancementPromptOverride');
		}

		return true;
	}

	private function columnExists(string $column): bool
	{
		$table = $this->db->getTableSchema(self::TABLE);

		return $table !== null && $table->getColumn($column) !== null;
	}
}
