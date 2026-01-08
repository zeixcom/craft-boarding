<?php

namespace zeix\boarding\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * TourI18nRecord represents a tour translation record in the database.
 *
 * @property int $id ID
 * @property int $tourId Tour ID
 * @property int $siteId Site ID
 * @property string $name Tour name
 * @property string $description Tour description
 * @property string $data Serialized tour data
 * @property bool $enabled Whether the tour is enabled for this site
 * @property \DateTime $dateCreated Date created
 * @property \DateTime $dateUpdated Date updated
 * @property string $uid UUID
 */
class TourI18nRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%boarding_tours_i18n}}';
    }
    
    /**
     * Deletes all records matching the given condition.
     *
     * @param array|string|null $condition The condition to match records for deletion
     * @param array $params The parameters to be bound to the condition
     * @return int The number of deleted rows
     */
    public static function deleteAll($condition = null, $params = []): int
    {
        return parent::deleteAll($condition, $params);
    }
    
    /**
     * Returns the tour this translation belongs to.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getTour(): ActiveQueryInterface
    {
        return $this->hasOne(TourRecord::class, ['id' => 'tourId']);
    }
    
    /**
     * Returns the site this translation is for.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getSite(): ActiveQueryInterface
    {
        return $this->hasOne(\craft\records\Site::class, ['id' => 'siteId']);
    }
}
