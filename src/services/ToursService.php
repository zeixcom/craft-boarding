<?php

namespace zeix\boarding\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;

/**
 * Migration to fix tours with missing or corrupted element entries
 *
 * This migration handles two cases:
 * 1. Tours with corrupted element entries (wrong type, wrong canonicalId, wrong fieldLayoutId, etc.)
 *    - These occur when tour IDs were reused from other element types
 * 2. Tours completely missing element/elements_sites entries
 *    - These occur when tours were imported directly via SQL after element integration
 *
 * This catches tours that were added or corrupted after the initial element integration migration ran.
 */
class m251028_000000_fix_orphaned_tours extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        echo "Checking for orphaned and corrupted tour element entries...\n";

        // Step 1: Fix corrupted element entries (wrong type, wrong fieldLayoutId, etc.)
        $corruptedTours = $this->findCorruptedTours();
        if (!empty($corruptedTours)) {
            echo "Found " . count($corruptedTours) . " corrupted tour(s). Fixing them...\n";
            foreach ($corruptedTours as $tour) {
                $this->fixCorruptedTour($tour);
            }
        }

        // Step 2: Find tours that don't have element entries at all
        $orphanedTours = $this->findOrphanedTours();

        if (empty($orphanedTours)) {
            echo "No orphaned tours found.\n";
            return true;
        }

        echo "Found " . count($orphanedTours) . " orphaned tour(s). Recreating them...\n";

        foreach ($orphanedTours as $tour) {
            $this->fixOrphanedTour($tour);
        }

        echo "All orphaned and corrupted tours have been fixed.\n";
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
     * Find tours with corrupted element entries
     * These are tours that have element entries but with incorrect data:
     * - Wrong element type (not 'zeix\boarding\models\Tour')
     * - Wrong canonicalId (not pointing to self)
     * - Wrong fieldLayoutId (should be NULL)
     * - Incorrect draftId or revisionId values
     */
    private function findCorruptedTours(): array
    {
        echo "Checking for tours with corrupted element entries...\n";

        $corruptedTours = (new Query())
            ->select(['bt.id', 'bt.name', 'e.type', 'e.canonicalId', 'e.fieldLayoutId', 'e.draftId', 'e.revisionId'])
            ->from(['bt' => '{{%boarding_tours}}'])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[bt.id]]')
            ->where([
                'or',
                ['!=', 'e.type', 'zeix\\boarding\\models\\Tour'],
                ['!=', 'e.canonicalId', new \yii\db\Expression('[[e.id]]')],
                ['is not', 'e.fieldLayoutId', null],
                ['is not', 'e.draftId', null],
                ['is not', 'e.revisionId', null],
            ])
            ->all();

        if (!empty($corruptedTours)) {
            echo "Corrupted tours found:\n";
            foreach ($corruptedTours as $tour) {
                echo "  - ID: {$tour['id']}, Name: {$tour['name']}, Type: {$tour['type']}\n";
            }
        }

        return $corruptedTours;
    }

    /**
     * Fix a corrupted tour element entry
     */
    private function fixCorruptedTour(array $tour): void
    {
        $id = $tour['id'];

        echo "  → Fixing corrupted tour #{$id}: {$tour['name']}\n";

        // Update the element entry to have correct values
        $this->update(Table::ELEMENTS, [
            'type' => 'zeix\\boarding\\models\\Tour',
            'canonicalId' => $id,
            'fieldLayoutId' => null,
            'draftId' => null,
            'revisionId' => null,
        ], ['id' => $id]);

        // Ensure elements_sites has a proper slug
        $elementSite = (new Query())
            ->select(['slug'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $id])
            ->one();

        if ($elementSite && empty($elementSite['slug'])) {
            $slug = \craft\helpers\ElementHelper::generateSlug($tour['name']);

            // Ensure slug is unique
            $slugCount = 0;
            $testSlug = $slug;
            while ((new Query())
                ->from(Table::ELEMENTS_SITES)
                ->where(['slug' => $testSlug])
                ->andWhere(['!=', 'elementId', $id])
                ->exists()
            ) {
                $slugCount++;
                $testSlug = $slug . '-' . $slugCount;
            }

            $this->update(Table::ELEMENTS_SITES, [
                'slug' => $testSlug,
            ], ['elementId' => $id]);

            echo "    ✓ Updated slug to: {$testSlug}\n";
        }

        echo "  ✓ Fixed corrupted tour #{$id}\n";
    }

    /**
     * Find tours that are missing element entries
     */
    private function findOrphanedTours(): array
    {
        // First, let's see all tours
        $allTours = (new Query())
            ->select(['bt.id', 'bt.name'])
            ->from(['bt' => '{{%boarding_tours}}'])
            ->all();

        echo "Total tours in database: " . count($allTours) . "\n";

        // Check which ones have element entries
        $toursWithElements = (new Query())
            ->select(['bt.id', 'bt.name', 'e.id as element_id'])
            ->from(['bt' => '{{%boarding_tours}}'])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[bt.id]]')
            ->all();

        echo "Tours with element entries: " . count($toursWithElements) . "\n";

        // Find orphaned tours
        $orphanedTours = (new Query())
            ->select(['bt.*'])
            ->from(['bt' => '{{%boarding_tours}}'])
            ->leftJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[bt.id]]')
            ->where(['e.id' => null])
            ->all();

        if (!empty($orphanedTours)) {
            echo "Orphaned tours found:\n";
            foreach ($orphanedTours as $tour) {
                echo "  - ID: {$tour['id']}, Name: {$tour['name']}\n";
            }
        }

        return $orphanedTours;
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

