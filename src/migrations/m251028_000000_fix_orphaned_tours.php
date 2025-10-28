<?php

namespace zeix\boarding\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;

/**
 * Migration to fix tours that were imported directly via SQL
 * and are missing required element/elements_sites entries
 *
 * This catches tours that were added after the initial element integration migration ran.
 */
class m251028_000000_fix_orphaned_tours extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        echo "Checking for orphaned tours missing element entries...\n";

        // Find tours that don't have element entries
        $orphanedTours = $this->findOrphanedTours();

        if (empty($orphanedTours)) {
            echo "No orphaned tours found.\n";
            return true;
        }

        echo "Found " . count($orphanedTours) . " orphaned tour(s). Recreating them...\n";

        foreach ($orphanedTours as $tour) {
            $this->fixOrphanedTour($tour);
        }

        echo "All orphaned tours have been fixed.\n";
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m251028_000000_fix_orphaned_tours cannot be reverted.\n";
        return false;
    }

    /**
     * Find tours that are missing element entries
     */
    private function findOrphanedTours(): array
    {
        return (new Query())
            ->select(['bt.*'])
            ->from(['bt' => '{{%boarding_tours}}'])
            ->leftJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[bt.id]]')
            ->where(['e.id' => null])
            ->all();
    }

    /**
     * Fix an orphaned tour by recreating it with new IDs
     */
    private function fixOrphanedTour(array $tour): void
    {
        $oldId = $tour['id'];

        // Step 1: Get all related data before deletion
        $userGroups = (new Query())
            ->select(['userGroupId'])
            ->from('{{%boarding_tours_usergroups}}')
            ->where(['tourId' => $oldId])
            ->column();

        $completions = (new Query())
            ->select(['userId', 'dateCreated', 'dateUpdated', 'uid'])
            ->from('{{%boarding_tour_completions}}')
            ->where(['tourId' => $oldId])
            ->all();

        $translations = (new Query())
            ->select(['*'])
            ->from('{{%boarding_tours_i18n}}')
            ->where(['tourId' => $oldId])
            ->all();

        // Step 2: Delete the orphaned tour (this cascades to related tables)
        $this->delete('{{%boarding_tours}}', ['id' => $oldId]);

        echo "  → Deleted orphaned tour #{$oldId}: {$tour['name']}\n";

        // Step 3: Create element entry first (required for foreign key)
        $newId = null;
        $this->insert(Table::ELEMENTS, [
            'canonicalId' => null, // Will be updated after insert
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
            'uid' => \craft\helpers\StringHelper::UUID(),
        ]);

        $newId = $this->db->getLastInsertID(Table::ELEMENTS);

        // Update canonicalId to point to itself
        $this->update(Table::ELEMENTS, ['canonicalId' => $newId], ['id' => $newId]);

        // Step 4: Create the tour record
        $this->insert('{{%boarding_tours}}', [
            'id' => $newId,
            'siteId' => $tour['siteId'],
            'tourId' => $tour['tourId'],
            'name' => $tour['name'],
            'description' => $tour['description'] ?? null,
            'data' => $tour['data'],
            'enabled' => $tour['enabled'],
            'translatable' => $tour['translatable'] ?? false,
            'propagationMethod' => $tour['propagationMethod'] ?? 'none',
            'progressPosition' => $tour['progressPosition'] ?? 'off',
            'autoplay' => $tour['autoplay'] ?? false,
            'dateCreated' => $tour['dateCreated'],
            'dateUpdated' => $tour['dateUpdated'],
            'uid' => $tour['uid'],
        ]);

        // Step 5: Create elements_sites entry
        $slug = \craft\helpers\ElementHelper::generateSlug($tour['name']);

        // Ensure slug is unique
        $slugCount = 0;
        $testSlug = $slug;
        while ((new Query())
            ->from(Table::ELEMENTS_SITES)
            ->where(['siteId' => $tour['siteId'], 'slug' => $testSlug])
            ->exists()
        ) {
            $slugCount++;
            $testSlug = $slug . '-' . $slugCount;
        }

        $this->insert(Table::ELEMENTS_SITES, [
            'elementId' => $newId,
            'siteId' => $tour['siteId'],
            'slug' => $testSlug,
            'uri' => null,
            'enabled' => $tour['enabled'],
            'dateCreated' => new \yii\db\Expression('NOW()'),
            'dateUpdated' => new \yii\db\Expression('NOW()'),
            'uid' => \craft\helpers\StringHelper::UUID(),
        ]);

        // Step 6: Restore user groups
        foreach ($userGroups as $userGroupId) {
            $this->insert('{{%boarding_tours_usergroups}}', [
                'tourId' => $newId,
                'userGroupId' => $userGroupId,
                'dateCreated' => new \yii\db\Expression('NOW()'),
                'dateUpdated' => new \yii\db\Expression('NOW()'),
                'uid' => \craft\helpers\StringHelper::UUID(),
            ]);
        }

        // Step 7: Restore completions
        foreach ($completions as $completion) {
            $this->insert('{{%boarding_tour_completions}}', [
                'tourId' => $newId,
                'userId' => $completion['userId'],
                'dateCreated' => $completion['dateCreated'],
                'dateUpdated' => $completion['dateUpdated'],
                'uid' => $completion['uid'],
            ]);
        }

        // Step 8: Restore translations
        foreach ($translations as $translation) {
            $this->insert('{{%boarding_tours_i18n}}', [
                'tourId' => $newId,
                'siteId' => $translation['siteId'],
                'name' => $translation['name'],
                'description' => $translation['description'] ?? null,
                'data' => $translation['data'] ?? null,
                'enabled' => $translation['enabled'],
                'dateCreated' => $translation['dateCreated'],
                'dateUpdated' => $translation['dateUpdated'],
                'uid' => $translation['uid'],
            ]);
        }

        echo "  ✓ Recreated tour as #{$newId}: {$tour['name']}\n";
    }
}
