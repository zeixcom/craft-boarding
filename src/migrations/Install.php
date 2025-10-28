<?php

namespace zeix\boarding\migrations;

use Craft;
use craft\db\Migration;

class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%boarding_tours}}')) {
            $this->createTable('{{%boarding_tours}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull()->defaultValue(1),
                'tourId' => $this->string()->notNull(),
                'name' => $this->string()->notNull(),
                'description' => $this->text(),
                'data' => $this->text(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'translatable' => $this->boolean()->notNull()->defaultValue(false),
                'propagationMethod' => $this->string(20)->defaultValue('none')->notNull(),
                'progressPosition' => $this->string(10)->defaultValue('off')->notNull(),
                'autoplay' => $this->boolean()->notNull()->defaultValue(false),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%boarding_tours}}', ['tourId'], true);

            $this->addForeignKey(
                null,
                '{{%boarding_tours}}',
                'siteId',
                '{{%sites}}',
                'id',
                'CASCADE',
                'CASCADE'
            );

            // Add foreign key to elements table for Craft element integration
            $this->addForeignKey(
                null,
                '{{%boarding_tours}}',
                'id',
                '{{%elements}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
        }

        if (!$this->db->tableExists('{{%boarding_tour_completions}}')) {
            $this->createTable('{{%boarding_tour_completions}}', [
                'id' => $this->primaryKey(),
                'tourId' => $this->integer()->notNull(),
                'userId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%boarding_tour_completions}}', ['tourId', 'userId'], true);
            $this->addForeignKey(null, '{{%boarding_tour_completions}}', ['tourId'], '{{%boarding_tours}}', ['id'], 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%boarding_tour_completions}}', ['userId'], '{{%users}}', ['id'], 'CASCADE');
        }

        if (!$this->db->tableExists('{{%boarding_tours_usergroups}}')) {
            $this->createTable('{{%boarding_tours_usergroups}}', [
                'id' => $this->primaryKey(),
                'tourId' => $this->integer()->notNull(),
                'userGroupId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%boarding_tours_usergroups}}', ['tourId']);
            $this->createIndex(null, '{{%boarding_tours_usergroups}}', ['userGroupId']);
            $this->addForeignKey(
                null,
                '{{%boarding_tours_usergroups}}',
                ['tourId'],
                '{{%boarding_tours}}',
                ['id'],
                'CASCADE'
            );
            $this->addForeignKey(
                null,
                '{{%boarding_tours_usergroups}}',
                ['userGroupId'],
                '{{%usergroups}}',
                ['id'],
                'CASCADE'
            );
        }

        if (!$this->db->tableExists('{{%boarding_tours_i18n}}')) {
            $this->createTable('{{%boarding_tours_i18n}}', [
                'id' => $this->primaryKey(),
                'tourId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'name' => $this->string()->notNull(),
                'description' => $this->text(),
                'data' => $this->text(), // This will store the translated steps
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%boarding_tours_i18n}}', ['tourId', 'siteId'], true);

            $this->addForeignKey(
                null,
                '{{%boarding_tours_i18n}}',
                'tourId',
                '{{%boarding_tours}}',
                'id',
                'CASCADE',
                'CASCADE'
            );

            $this->addForeignKey(
                null,
                '{{%boarding_tours_i18n}}',
                'siteId',
                '{{%sites}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
        }

        $permissions = [
            'boarding:createtours',
            'boarding:edittours',
            'boarding:deletetours',
            'boarding:managetoursettings'
        ];

        foreach ($permissions as $permission) {
            $permissionId = (new \craft\db\Query())
                ->select(['id'])
                ->from('{{%userpermissions}}')
                ->where(['name' => $permission])
                ->scalar();

            if (!$permissionId) {
                $this->insert('{{%userpermissions}}', [
                    'name' => $permission,
                    'dateCreated' => new \yii\db\Expression('NOW()'),
                    'dateUpdated' => new \yii\db\Expression('NOW()'),
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ]);
                $permissionId = $this->db->getLastInsertID('{{%userpermissions}}');
            }

            $adminGroupId = (new \craft\db\Query())
                ->select(['id'])
                ->from('{{%usergroups}}')
                ->where(['handle' => 'admin'])
                ->scalar();
        }

        // Create element entries for all tours (needed for Craft element integration)
        $this->createElementEntries();

        return true;
    }

    /**
     * Create element entries for all tours (needed for Craft element integration)
     */
    private function createElementEntries(): void
    {
        $tours = (new \craft\db\Query())
            ->select(['id', 'siteId', 'tourId', 'name', 'enabled', 'dateCreated', 'dateUpdated', 'uid'])
            ->from('{{%boarding_tours}}')
            ->all();
            
        foreach ($tours as $tour) {
            // Check if element entry exists
            $elementExists = (new \craft\db\Query())
                ->select(['id'])
                ->from('{{%elements}}')
                ->where(['id' => $tour['id']])
                ->exists();
                
            if (!$elementExists) {
                // Create element entry
                $this->insert('{{%elements}}', [
                    'id' => $tour['id'],
                    'canonicalId' => $tour['id'],
                    'draftId' => null,
                    'revisionId' => null,
                    'fieldLayoutId' => null,
                    'type' => 'zeix\\boarding\\models\\Tour',
                    'enabled' => $tour['enabled'],
                    'archived' => false,
                    'dateCreated' => $tour['dateCreated'],
                    'dateUpdated' => $tour['dateUpdated'],
                    'dateLastMerged' => null,
                    'dateDeleted' => null,
                    'uid' => $tour['uid'],
                ]);
                
                // Create elements_sites entry
                $this->insert('{{%elements_sites}}', [
                    'elementId' => $tour['id'],
                    'siteId' => $tour['siteId'],
                    'slug' => \craft\helpers\ElementHelper::generateSlug($tour['name']),
                    'uri' => null,
                    'enabled' => $tour['enabled'],
                    'dateCreated' => $tour['dateCreated'],
                    'dateUpdated' => $tour['dateUpdated'],
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ]);
                
                echo "Created element entries for tour #{$tour['id']}: {$tour['name']}\n";
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Remove permissions
        $this->delete('{{%userpermissions}}', ['name' => 'accessPlugin-boarding']);
        $this->delete('{{%userpermissions}}', ['name' => 'boarding:createTours']);
        $this->delete('{{%userpermissions}}', ['name' => 'boarding:editTours']);
        $this->delete('{{%userpermissions}}', ['name' => 'boarding:deleteTours']);
        $this->delete('{{%userpermissions}}', ['name' => 'boarding:manageTourSettings']);

        // Drop tables in correct order (dependent tables first)
        $this->dropTableIfExists('{{%boarding_tour_progress}}');
        $this->dropTableIfExists('{{%boarding_tour_completions}}');
        $this->dropTableIfExists('{{%boarding_tours_usergroups}}');
        $this->dropTableIfExists('{{%boarding_tours_i18n}}');
        $this->dropTableIfExists('{{%boarding_tours}}');

        return true;
    }
}
