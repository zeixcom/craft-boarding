<?php

namespace zeix\boarding\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * TourQuery represents a SELECT SQL statement for tours.
 */
class TourQuery extends ElementQuery
{
    public ?bool $enabled = null;
    public int|array|null $userGroupId = null;

    /**
     * @inheritdoc
     */
    protected array $defaultOrderBy = ['boarding_tours.dateCreated' => SORT_DESC];

    /**
     * Narrows the query results to only tours that are enabled
     *
     * @param bool|null $value The property value
     * @return static self reference
     */
    public function enabled($value = true)
    {
        $this->enabled = $value;
        return $this;
    }

    /**
     * Narrows the query results based on the user group the tours are for
     *
     * @param int|int[]|null $value The property value
     * @return static self reference
     */
    public function userGroupId($value)
    {
        $this->userGroupId = $value;
        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function beforePrepare(): bool
    {
        $this->joinElementTable('boarding_tours');

        $this->query->select([
            'boarding_tours.tourId',
            'boarding_tours.description',
            'boarding_tours.enabled',
            'boarding_tours.propagationMethod',
            'boarding_tours.progressPosition',
            'boarding_tours.autoplay',
            'boarding_tours.data',
        ]);

        if ($this->enabled !== null) {
            $this->subQuery->andWhere(Db::parseParam('boarding_tours.enabled', (int)$this->enabled));
        }

        if ($this->userGroupId !== null) {
            $userGroupIds = is_array($this->userGroupId) ? $this->userGroupId : [$this->userGroupId];

            // If user has no groups, only show tours with no user group restrictions
            if (empty($userGroupIds)) {
                $this->subQuery->andWhere([
                    'not exists',
                    (new \craft\db\Query())
                        ->from(['tug' => '{{%boarding_tours_usergroups}}'])
                        ->where('[[tug.tourId]] = [[boarding_tours.id]]')
                ]);
            } else {
                // Show tours that either:
                // 1. Have no user group restrictions (available to everyone), OR
                // 2. Have a restriction matching one of the user's groups
                $this->subQuery->andWhere([
                    'or',
                    [
                        'exists',
                        (new \craft\db\Query())
                            ->from(['tug1' => '{{%boarding_tours_usergroups}}'])
                            ->where('[[tug1.tourId]] = [[boarding_tours.id]]')
                            ->andWhere(['tug1.userGroupId' => $userGroupIds])
                    ],
                    [
                        'not exists',
                        (new \craft\db\Query())
                            ->from(['tug2' => '{{%boarding_tours_usergroups}}'])
                            ->where('[[tug2.tourId]] = [[boarding_tours.id]]')
                    ]
                ]);
            }
        }

        return parent::beforePrepare();
    }
}
