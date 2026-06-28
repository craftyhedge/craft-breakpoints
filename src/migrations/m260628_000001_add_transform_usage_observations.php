<?php

namespace craftyhedge\craftbreakpoints\migrations;

use craft\db\Migration;

class m260628_000001_add_transform_usage_observations extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%bpi_transform_usage_observations}}')) {
            return true;
        }

        $this->createTable('{{%bpi_transform_usage_observations}}', [
            'id' => $this->primaryKey(),
            'transformHandle' => $this->string()->notNull(),
            'sourceKey' => $this->string(64)->notNull(),
            'sourceElementId' => $this->integer()->null()->defaultValue(null),
            'sourceUrl' => $this->string(255)->null()->defaultValue(null),
            'firstSeenAt' => $this->dateTime()->notNull(),
            'lastSeenAt' => $this->dateTime()->notNull(),
            'seenCount' => $this->integer()->notNull()->defaultValue(1),
            'initWidth' => $this->integer()->null()->defaultValue(null),
            'initHeight' => $this->integer()->null()->defaultValue(null),
            'initRatio' => $this->string(32)->null()->defaultValue(null),
            'initWidthAuto' => $this->boolean()->null()->defaultValue(null),
            'initHeightAuto' => $this->boolean()->null()->defaultValue(null),
            'includeEscapeWidth' => $this->boolean()->null()->defaultValue(null),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%bpi_transform_usage_observations}}', ['transformHandle', 'sourceKey'], true);
        $this->createIndex(null, '{{%bpi_transform_usage_observations}}', ['transformHandle'], false);
        $this->createIndex(null, '{{%bpi_transform_usage_observations}}', ['sourceElementId'], false);
        $this->createIndex(null, '{{%bpi_transform_usage_observations}}', ['lastSeenAt'], false);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%bpi_transform_usage_observations}}');

        return true;
    }
}
