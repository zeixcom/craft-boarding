<?php

namespace zeix\boarding\events;

use yii\base\Event;
use zeix\boarding\models\Tour;

/**
 * TourEvent class
 *
 * Event triggered when tour-related actions occur
 */
class TourEvent extends Event
{
    /**
     * @var Tour The tour being affected
     */
    public Tour $tour;

    /**
     * @var bool Whether this is a new tour
     */
    public bool $isNew = false;

    /**
     * @var array Additional context data
     */
    public array $context = [];
}
