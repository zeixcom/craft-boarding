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
                'progressPosition' => $this->string(10)->defaultValue('off')->notNull(),
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

        return true;
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
