<?php

namespace craftyhedge\craftbreakpoints\migrations;

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
                'initWidth' => $this->integer()->null()->defaultValue(null),
                'initHeight' => $this->integer()->null()->defaultValue(null),
                'initRatio' => $this->string(32)->null()->defaultValue(null),
                'initWidthAuto' => $this->boolean()->null()->defaultValue(null),
                'initHeightAuto' => $this->boolean()->null()->defaultValue(null),
                'includeEscapeWidth' => $this->boolean()->null()->defaultValue(null),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%bpi_transform_last_processed}}', ['transformHandle'], true);
            $this->createIndex(null, '{{%bpi_transform_last_processed}}', ['lastSeenAt'], false);
        }

        if (!$this->db->tableExists('{{%bpi_transform_usage_observations}}')) {
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
        }

        if (!$this->db->tableExists('{{%bpi_processing_run_snapshot}}')) {
            $this->createTable('{{%bpi_processing_run_snapshot}}', [
                'id' => $this->primaryKey(),
                'ranAt' => $this->dateTime()->notNull(),
                'runStatus' => $this->string(16)->notNull(),
                'durationMs' => $this->integer()->notNull()->defaultValue(0),
                'entryId' => $this->integer()->null()->defaultValue(null),
                'sourceUrl' => $this->string(255)->null()->defaultValue(null),
                'runId' => $this->string(64)->null()->defaultValue(null),
                'failureReasonCounts' => $this->text()->notNull(),
                'transformMetadata' => $this->text()->null()->defaultValue(null),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%bpi_processing_run_snapshot}}', ['ranAt'], false);
            $this->createIndex(null, '{{%bpi_processing_run_snapshot}}', ['entryId'], false);
        }

        if (!$this->db->tableExists('{{%bpi_processing_run_snapshot_breakpoints}}')) {
            $this->createTable('{{%bpi_processing_run_snapshot_breakpoints}}', [
                'id' => $this->primaryKey(),
                'snapshotId' => $this->integer()->notNull(),
                'transformHandle' => $this->string()->notNull(),
                'slotKey' => $this->string(64)->notNull(),
                'slotIndex' => $this->integer()->notNull(),
                'breakpointWidth' => $this->integer()->notNull(),
                'measureWidth' => $this->integer()->notNull(),
                'assetId' => $this->string(255)->null()->defaultValue(null),
                'displayAssetUrl' => $this->string(1024)->null()->defaultValue(null),
                'rowStatus' => $this->string(24)->notNull()->defaultValue('unprocessed'),
                'isVisible' => $this->boolean()->null()->defaultValue(null),
                'renderedWidth' => $this->integer()->notNull()->defaultValue(0),
                'renderedHeight' => $this->integer()->notNull()->defaultValue(0),
                'autoDimension' => $this->string(16)->null()->defaultValue(null),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(
                null,
                '{{%bpi_processing_run_snapshot_breakpoints}}',
                ['snapshotId', 'transformHandle', 'breakpointWidth'],
                false
            );
            $this->createIndex(null, '{{%bpi_processing_run_snapshot_breakpoints}}', ['snapshotId', 'transformHandle', 'slotKey'], false);
            $this->createIndex(null, '{{%bpi_processing_run_snapshot_breakpoints}}', ['transformHandle'], false);
            $this->createIndex(null, '{{%bpi_processing_run_snapshot_breakpoints}}', ['breakpointWidth'], false);

            $this->addForeignKey(
                null,
                '{{%bpi_processing_run_snapshot_breakpoints}}',
                ['snapshotId'],
                '{{%bpi_processing_run_snapshot}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        if (!$this->db->tableExists('{{%bpi_processing_run_snapshot_dimensions}}')) {
            $this->createTable('{{%bpi_processing_run_snapshot_dimensions}}', [
                'id' => $this->primaryKey(),
                'snapshotId' => $this->integer()->notNull(),
                'transformHandle' => $this->string()->notNull(),
                'slotKey' => $this->string(64)->notNull(),
                'slotIndex' => $this->integer()->notNull(),
                'breakpointWidth' => $this->integer()->notNull(),
                'measureWidth' => $this->integer()->notNull(),
                'savedWidth' => $this->integer()->null()->defaultValue(null),
                'savedHeight' => $this->integer()->null()->defaultValue(null),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(
                null,
                '{{%bpi_processing_run_snapshot_dimensions}}',
                ['snapshotId', 'transformHandle', 'breakpointWidth'],
                false
            );
            $this->createIndex(null, '{{%bpi_processing_run_snapshot_dimensions}}', ['snapshotId', 'transformHandle', 'slotKey'], true);

            $this->addForeignKey(
                null,
                '{{%bpi_processing_run_snapshot_dimensions}}',
                ['snapshotId'],
                '{{%bpi_processing_run_snapshot}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        if (!$this->db->tableExists('{{%bpi_preview_cache}}')) {
            $this->createTable('{{%bpi_preview_cache}}', [
                'id' => $this->primaryKey(),
                'transformHandle' => $this->string()->notNull(),
                'slotKey' => $this->string(64)->notNull(),
                'slotIndex' => $this->integer()->notNull(),
                'breakpointWidth' => $this->integer()->notNull(),
                'measureWidth' => $this->integer()->notNull(),
                'displayAssetUrl' => $this->string(1024)->null()->defaultValue(null),
                'rowStatus' => $this->string(24)->notNull()->defaultValue('unprocessed'),
                'renderedWidth' => $this->integer()->null()->defaultValue(null),
                'renderedHeight' => $this->integer()->null()->defaultValue(null),
                'lastProcessedAt' => $this->dateTime()->notNull(),
                'runId' => $this->string(64)->null()->defaultValue(null),
                'sourceEntryId' => $this->integer()->null()->defaultValue(null),
                'sourceUrl' => $this->string(255)->null()->defaultValue(null),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%bpi_preview_cache}}', ['transformHandle', 'breakpointWidth'], false);
            $this->createIndex(null, '{{%bpi_preview_cache}}', ['transformHandle', 'slotKey'], true);
            $this->createIndex(null, '{{%bpi_preview_cache}}', ['transformHandle'], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%bpi_preview_cache}}');
        $this->dropTableIfExists('{{%bpi_processing_run_snapshot_dimensions}}');
        $this->dropTableIfExists('{{%bpi_processing_run_snapshot_breakpoints}}');
        $this->dropTableIfExists('{{%bpi_processing_run_snapshot}}');
        $this->dropTableIfExists('{{%bpi_transform_usage_observations}}');
        $this->dropTableIfExists('{{%bpi_transform_last_processed}}');

        return true;
    }
}
