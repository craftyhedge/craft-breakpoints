<?php

namespace craftyhedge\craftbreakpoints\migrations;

use craft\db\Migration;

class m260926_000001_add_snapshot_picture_instance extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%bpi_processing_run_snapshot_breakpoints}}')) {
            return true;
        }

        if ($this->db->columnExists('{{%bpi_processing_run_snapshot_breakpoints}}', 'pictureInstance')) {
            return true;
        }

        $this->addColumn(
            '{{%bpi_processing_run_snapshot_breakpoints}}',
            'pictureInstance',
            $this->string(64)->null()->defaultValue(null)->after('assetId')
        );

        return true;
    }

    public function safeDown(): bool
    {
        if (
            $this->db->tableExists('{{%bpi_processing_run_snapshot_breakpoints}}')
            && $this->db->columnExists('{{%bpi_processing_run_snapshot_breakpoints}}', 'pictureInstance')
        ) {
            $this->dropColumn('{{%bpi_processing_run_snapshot_breakpoints}}', 'pictureInstance');
        }

        return true;
    }
}
