<?php

namespace zeix\boarding\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * TourRecord represents a tour record in the database.
 * 
 * @property int $id ID
 * @property int $siteId Site ID
 * @property string $tourId Tour ID
 * @property string $name Tour name
 * @property string $description Tour description
 * @property string $data Serialized tour data
 * @property bool $enabled Whether the tour is enabled
 * @property bool $translatable Whether the tour is translatable
 * @property string $progressPosition Progress indicator position
 * @property \DateTime $dateCreated Date created
 * @property \DateTime $dateUpdated Date updated
 * @property string $uid UUID
 */
class TourRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%boarding_tours}}';
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
     * Returns the tour's translations.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getTranslations(): ActiveQueryInterface
    {
        return $this->hasMany(TourI18nRecord::class, ['tourId' => 'id']);
    }
    
    /**
     * Returns the tour's user group assignments.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getUserGroups(): ActiveQueryInterface
    {
        return $this->hasMany(TourUserGroupRecord::class, ['tourId' => 'id']);
    }
} 