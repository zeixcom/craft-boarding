<?php

namespace zeix\boarding\migrations;

use craft\db\Migration;

class m250101_000000_fix_tour_completion_tourId_type extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%boarding_tour_completions}}';
        $tableName = $this->db->getTableSchema($table)->fullName;

        // Get the existing index name from MySQL
        $sql = "SHOW INDEX FROM {$tableName} WHERE Column_name IN ('tourId', 'userId')";
        $indexes = $this->db->createCommand($sql)->queryAll();

        $indexName = null;
        if (!empty($indexes)) {
            $indexName = $indexes[0]['Key_name'];
        }

        // Drop the existing index if found
        if ($indexName) {
            $this->dropIndex($indexName, $table);
        }

        // Change tourId from string to integer
        $this->alterColumn($table, 'tourId', $this->integer()->notNull());

        // Recreate the index
        $this->createIndex(null, $table, ['tourId', 'userId'], true);

        // Add foreign key constraint
        $this->addForeignKey(
            null,
            $table,
            'tourId',
            '{{%boarding_tours}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%boarding_tour_completions}}';
        $tableName = $this->db->getTableSchema($table)->fullName;

        // Drop the foreign key
        $foreignKeys = $this->db->getSchema()->getTableSchema($table)->foreignKeys;
        foreach ($foreignKeys as $name => $constraint) {
            if (isset($constraint[0]) && $constraint[0] === '{{%boarding_tours}}') {
                $this->dropForeignKey($name, $table);
            }
        }

        // Get the existing index name from MySQL
        $sql = "SHOW INDEX FROM {$tableName} WHERE Column_name IN ('tourId', 'userId')";
        $indexes = $this->db->createCommand($sql)->queryAll();

        $indexName = null;
        if (!empty($indexes)) {
            $indexName = $indexes[0]['Key_name'];
        }

        // Drop the index if found
        if ($indexName) {
            $this->dropIndex($indexName, $table);
        }

        // Change tourId back to string
        $this->alterColumn($table, 'tourId', $this->string()->notNull());

        // Recreate the index
        $this->createIndex(null, $table, ['tourId', 'userId'], true);

        return true;
    }
}
