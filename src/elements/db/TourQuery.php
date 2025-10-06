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
    public ?bool $translatable = null;
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
     * Narrows the query results to only tours that are translatable
     *
     * @param bool|null $value The property value
     * @return static self reference
     */
    public function translatable($value = true)
    {
        $this->translatable = $value;
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
            'boarding_tours.translatable',
            'boarding_tours.progressPosition',
            'boarding_tours.data',
        ]);

        if ($this->enabled !== null) {
            $this->subQuery->andWhere(Db::parseParam('boarding_tours.enabled', (int)$this->enabled));
        }

        if ($this->translatable !== null) {
            $this->subQuery->andWhere(Db::parseParam('boarding_tours.translatable', (int)$this->translatable));
        }

        if ($this->userGroupId !== null) {
            $this->subQuery->innerJoin(
                'boarding_tours_usergroups',
                '[[boarding_tours_usergroups.tourId]] = [[boarding_tours.id]]'
            );
            $this->subQuery->andWhere(Db::parseParam('boarding_tours_usergroups.userGroupId', $this->userGroupId));
        }

        return parent::beforePrepare();
    }
}
