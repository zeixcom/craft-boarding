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

        echo "Found " . count($orphanedTours) . " orphaned tour(s). Creating element entries...\n";

        foreach ($orphanedTours as $tour) {
            $this->createElementEntry($tour);
            $this->createElementSitesEntry($tour);
            echo "  ✓ Fixed tour #{$tour['id']}: {$tour['name']}\n";
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
            ->select(['bt.id', 'bt.siteId', 'bt.tourId', 'bt.name', 'bt.enabled', 'bt.dateCreated', 'bt.dateUpdated', 'bt.uid'])
            ->from(['bt' => '{{%boarding_tours}}'])
            ->leftJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[bt.id]]')
            ->where(['e.id' => null])
            ->all();
    }

    /**
     * Create element entry for a tour
     */
    private function createElementEntry(array $tour): void
    {
        // Check if element entry already exists (race condition protection)
        $elementExists = (new Query())
            ->select(['id'])
            ->from(Table::ELEMENTS)
            ->where(['id' => $tour['id']])
            ->exists();

        if ($elementExists) {
            return;
        }

        $this->insert(Table::ELEMENTS, [
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
    }

    /**
     * Create elements_sites entry for a tour
     */
    private function createElementSitesEntry(array $tour): void
    {
        // Check if elements_sites entry already exists
        $exists = (new Query())
            ->select(['id'])
            ->from(Table::ELEMENTS_SITES)
            ->where([
                'elementId' => $tour['id'],
                'siteId' => $tour['siteId'],
            ])
            ->exists();

        if ($exists) {
            return;
        }

        // Generate slug from title
        $slug = \craft\helpers\ElementHelper::generateSlug($tour['name']);

        // Ensure slug is unique for this site
        $slugCount = 0;
        $testSlug = $slug;
        while ((new Query())
            ->from(Table::ELEMENTS_SITES)
            ->where([
                'siteId' => $tour['siteId'],
                'slug' => $testSlug,
            ])
            ->exists()
        ) {
            $slugCount++;
            $testSlug = $slug . '-' . $slugCount;
        }
        $slug = $testSlug;

        $this->insert(Table::ELEMENTS_SITES, [
            'elementId' => $tour['id'],
            'siteId' => $tour['siteId'],
            'slug' => $slug,
            'uri' => null,
            'enabled' => $tour['enabled'],
            'dateCreated' => new \yii\db\Expression('NOW()'),
            'dateUpdated' => new \yii\db\Expression('NOW()'),
            'uid' => \craft\helpers\StringHelper::UUID(),
        ]);
    }
}
