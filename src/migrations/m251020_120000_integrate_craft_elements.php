<?php

namespace zeix\boarding\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;

/**
 * Migration to properly integrate tours with Craft's element system
 * 
 * This migration:
 * 1. Ensures all tours have proper element table entries
 * 2. Creates elements_sites entries for all tours
 * 3. Adds proper foreign key from boarding_tours.id to elements.id
 */
class m251020_120000_integrate_craft_elements extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // Step 1: Ensure all tours have element entries
        $this->ensureElementEntries();
        
        // Step 2: Create elements_sites entries for existing tours
        $this->createElementSitesEntries();
        
        // Step 3: Add foreign key constraint (if not exists)
        $this->addForeignKeyIfNotExists();
        
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m251020_120000_integrate_craft_elements cannot be reverted.\n";
        return false;
    }
    
    /**
     * Ensure all tours in boarding_tours have corresponding entries in elements table
     */
    private function ensureElementEntries(): void
    {
        $tours = (new Query())
            ->select(['id', 'siteId', 'tourId', 'name', 'enabled', 'dateCreated', 'dateUpdated', 'uid'])
            ->from('{{%boarding_tours}}')
            ->all();
            
        foreach ($tours as $tour) {
            // Check if element entry exists
            $elementExists = (new Query())
                ->select(['id'])
                ->from(Table::ELEMENTS)
                ->where(['id' => $tour['id']])
                ->exists();
                
            if (!$elementExists) {
                // Create element entry
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
                
                echo "Created element entry for tour #{$tour['id']}: {$tour['name']}\n";
            }
        }
    }
    
    /**
     * Create elements_sites entries for all tours
     */
    private function createElementSitesEntries(): void
    {
        $tours = (new Query())
            ->select(['t.id', 't.siteId', 't.name', 't.enabled', 't.translatable', 't.tourId'])
            ->from(['t' => '{{%boarding_tours}}'])
            ->all();
            
        $allSites = Craft::$app->getSites()->getAllSites();
        
        foreach ($tours as $tour) {
            if ($tour['translatable']) {
                // Tour is translatable - create entries for all sites
                foreach ($allSites as $site) {
                    $this->createElementSiteEntry($tour, $site->id);
                }
            } else {
                // Tour is not translatable - only create entry for its assigned site
                $this->createElementSiteEntry($tour, $tour['siteId']);
            }
        }
    }
    
    /**
     * Create a single elements_sites entry
     */
    private function createElementSiteEntry(array $tour, int $siteId): void
    {
        // Check if elements_sites entry already exists
        $exists = (new Query())
            ->select(['id'])
            ->from(Table::ELEMENTS_SITES)
            ->where([
                'elementId' => $tour['id'],
                'siteId' => $siteId,
            ])
            ->exists();
            
        if (!$exists) {
            // Generate slug from title
            $slug = \craft\helpers\ElementHelper::generateSlug($tour['name']);
            
            // Ensure slug is unique for this site
            $slugCount = 0;
            $testSlug = $slug;
            while ((new Query())
                ->from(Table::ELEMENTS_SITES)
                ->where([
                    'siteId' => $siteId,
                    'slug' => $testSlug,
                ])
                ->exists()
            ) {
                $slugCount++;
                $testSlug = $slug . '-' . $slugCount;
            }
            $slug = $testSlug;
            
            // Check if this site has custom translations
            $i18nData = (new Query())
                ->select(['name', 'enabled'])
                ->from('{{%boarding_tours_i18n}}')
                ->where([
                    'tourId' => $tour['id'],
                    'siteId' => $siteId,
                ])
                ->one();
                
            $enabled = $i18nData ? $i18nData['enabled'] : $tour['enabled'];
            
            $this->insert(Table::ELEMENTS_SITES, [
                'elementId' => $tour['id'],
                'siteId' => $siteId,
                'slug' => $slug,
                'uri' => null,
                'enabled' => $enabled,
                'dateCreated' => new \yii\db\Expression('NOW()'),
                'dateUpdated' => new \yii\db\Expression('NOW()'),
                'uid' => \craft\helpers\StringHelper::UUID(),
            ]);
            
            echo "Created elements_sites entry for tour #{$tour['id']} on site #{$siteId}\n";
        }
    }
    
    /**
     * Add foreign key constraint if it doesn't exist
     */
    private function addForeignKeyIfNotExists(): void
    {
        // Check if foreign key already exists
        $fkExists = false;
        $foreignKeys = $this->db->getSchema()->getTableSchema('{{%boarding_tours}}')->foreignKeys;
        
        foreach ($foreignKeys as $fk) {
            if (isset($fk['id']) && in_array('elements', $fk)) {
                $fkExists = true;
                break;
            }
        }
        
        if (!$fkExists) {
            $this->addForeignKey(
                $this->db->getForeignKeyName('{{%boarding_tours}}', 'id'),
                '{{%boarding_tours}}',
                'id',
                Table::ELEMENTS,
                'id',
                'CASCADE',
                null
            );
            
            echo "Added foreign key from boarding_tours.id to elements.id\n";
        }
    }
}

