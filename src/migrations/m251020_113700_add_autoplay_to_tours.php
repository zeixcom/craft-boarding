<?php

namespace zeix\boarding\migrations;

use craft\db\Migration;

/**
 * m251020_113700_add_autoplay_to_tours migration.
 */
class m251020_113700_add_autoplay_to_tours extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Add autoplay column to boarding_tours table
        if (!$this->db->columnExists('{{%boarding_tours}}', 'autoplay')) {
            $this->addColumn(
                '{{%boarding_tours}}',
                'autoplay',
                $this->boolean()->defaultValue(false)->notNull()->after('progressPosition')
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m251020_113700_add_autoplay_to_tours cannot be reverted.\n";
        
        // Remove autoplay column
        if ($this->db->columnExists('{{%boarding_tours}}', 'autoplay')) {
            $this->dropColumn('{{%boarding_tours}}', 'autoplay');
        }

        return true;
    }
}

