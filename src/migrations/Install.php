<?php

namespace craftyhedge\craftbreakpointimages\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%bpi_transform_last_processed}}')) {
            $this->createTable('{{%bpi_transform_last_processed}}', [
                'id' => $this->primaryKey(),
                'transformHandle' => $this->string()->notNull(),
                'sourceElementId' => $this->integer()->null()->defaultValue(null),
                'sourceUrl' => $this->string(255)->null()->defaultValue(null),
                'lastSeenAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%bpi_transform_last_processed}}', ['transformHandle'], true);
            $this->createIndex(null, '{{%bpi_transform_last_processed}}', ['lastSeenAt'], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%bpi_transform_last_processed}}');

        return true;
    }
}
