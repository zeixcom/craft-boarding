<?php

namespace zeix\boarding\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;

/**
 * Migration to add propagation method for multi-site tour management
 *
 * This replaces the simple translatable boolean with Craft's propagation method system
 */
class m251020_130000_add_propagation_method extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // Step 1: Add propagationMethod column
        if (!$this->db->columnExists('{{%boarding_tours}}', 'propagationMethod')) {
            $this->addColumn(
                '{{%boarding_tours}}',
                'propagationMethod',
                $this->string(20)->defaultValue('none')->after('translatable')
            );
            
            echo "Added propagationMethod column\n";
        }
        
        // Step 2: Migrate existing translatable values to propagation method
        $this->migrateTranslatableData();
        
        // Step 3: Update elements_sites entries based on new propagation method
        $this->updateElementsSitesForPropagation();
        
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        // Remove propagationMethod column
        if ($this->db->columnExists('{{%boarding_tours}}', 'propagationMethod')) {
            $this->dropColumn('{{%boarding_tours}}', 'propagationMethod');
        }
        
        return true;
    }
    
    /**
     * Migrate existing translatable boolean to propagation method
     */
    private function migrateTranslatableData(): void
    {
        $tours = (new Query())
            ->select(['id', 'translatable', 'siteId'])
            ->from('{{%boarding_tours}}')
            ->all();
            
        foreach ($tours as $tour) {
            // translatable=true → 'all' (available on all sites with unique content)
            // translatable=false → 'none' (only on current site)
            $propagationMethod = $tour['translatable'] ? 'all' : 'none';
            
            $this->update(
                '{{%boarding_tours}}',
                ['propagationMethod' => $propagationMethod],
                ['id' => $tour['id']]
            );
            
            echo "Migrated tour #{$tour['id']} to propagationMethod: {$propagationMethod}\n";
        }
    }
    
    /**
     * Update elements_sites entries based on propagation method
     */
    private function updateElementsSitesForPropagation(): void
    {
        $tours = (new Query())
            ->select(['id', 'propagationMethod', 'siteId'])
            ->from('{{%boarding_tours}}')
            ->all();
            
        $allSites = Craft::$app->getSites()->getAllSites();
        
        foreach ($tours as $tour) {
            if ($tour['propagationMethod'] === 'all') {
                // Tour should be available on all sites
                foreach ($allSites as $site) {
                    // Check if elements_sites entry exists
                    $exists = (new Query())
                        ->select(['id'])
                        ->from(Table::ELEMENTS_SITES)
                        ->where([
                            'elementId' => $tour['id'],
                            'siteId' => $site->id,
                        ])
                        ->exists();
                        
                    if (!$exists) {
                        // Create elements_sites entry
                        $this->createElementSiteEntry($tour['id'], $site->id);
                    }
                }
            } else {
                // propagationMethod === 'none'
                // Only keep the entry for the tour's assigned site
                // Remove any other elements_sites entries
                $this->delete(Table::ELEMENTS_SITES, [
                    'and',
                    ['elementId' => $tour['id']],
                    ['!=', 'siteId', $tour['siteId']],
                ]);
                
                echo "Cleaned up elements_sites for tour #{$tour['id']} (site-specific)\n";
            }
        }
    }
    
    /**
     * Create a single elements_sites entry
     */
    private function createElementSiteEntry(int $elementId, int $siteId): void
    {
        // Get tour name for slug generation
        $tourName = (new Query())
            ->select(['name'])
            ->from('{{%boarding_tours}}')
            ->where(['id' => $elementId])
            ->scalar();
            
        if (!$tourName) {
            return;
        }
        
        // Generate slug
        $slug = \craft\helpers\ElementHelper::generateSlug($tourName);
        
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
        
        $this->insert(Table::ELEMENTS_SITES, [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'slug' => $slug,
            'uri' => null,
            'enabled' => true,
            'dateCreated' => new \yii\db\Expression('NOW()'),
            'dateUpdated' => new \yii\db\Expression('NOW()'),
            'uid' => \craft\helpers\StringHelper::UUID(),
        ]);
        
        echo "Created elements_sites entry for tour #{$elementId} on site #{$siteId}\n";
    }
}
