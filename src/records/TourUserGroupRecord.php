<?php

namespace zeix\boarding\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * TourUserGroupRecord represents a tour user group assignment in the database.
 *
 * @property int $id ID
 * @property int $tourId Tour ID
 * @property int $userGroupId User group ID
 * @property \DateTime $dateCreated Date created
 * @property \DateTime $dateUpdated Date updated
 * @property string $uid UUID
 */
class TourUserGroupRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%boarding_tours_usergroups}}';
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
     * Returns the tour this user group belongs to.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getTour(): ActiveQueryInterface
    {
        return $this->hasOne(TourRecord::class, ['id' => 'tourId']);
    }
    
    /**
     * Returns the user group.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getUserGroup(): ActiveQueryInterface
    {
        return $this->hasOne(\craft\records\UserGroup::class, ['id' => 'userGroupId']);
    }
}
