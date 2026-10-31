<?php

namespace arjanbrinkman\craftimageenhancer\migrations;

use craft\db\Migration;

/** Durable idempotency, checkpoints and provenance for server-side enhancement. */
class m261031_120000_add_mcp_requests extends Migration
{
    public const TABLE = '{{%imageenhancer_mcp_requests}}';

    /** @inheritdoc */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            $this->createTable(self::TABLE, [
                'id' => $this->primaryKey(),
                'requestId' => $this->string(36)->notNull(),
                'userId' => $this->integer()->notNull(),
                'idempotencyHash' => $this->string(64)->notNull(),
                'requestHash' => $this->string(64)->notNull(),
                'request' => $this->text()->notNull(),
                'status' => $this->string(32)->notNull(),
                'progress' => $this->float()->notNull()->defaultValue(0),
                'error' => $this->text(),
                'queueJobId' => $this->integer(),
                'resultAssetId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::TABLE, ['requestId'], true);
            $this->createIndex(null, self::TABLE, ['idempotencyHash'], true);
        }
        return true;
    }

    /** @inheritdoc */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
