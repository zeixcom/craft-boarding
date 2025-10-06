<?php

namespace zeix\boarding\variables;

use zeix\boarding\Boarding;

class BoardingVariable
{
    /**
     * Get all tours
     *
     * @return array
     */
    public function getAllTours(): array
    {
        return Boarding::getInstance()->tours->getAllTours();
    }
    
    /**
     * Get a tour by ID
     * 
     * @param int $id
     * @return array|null
     */
    public function getTourById(int $id): ?array
    {
        return Boarding::getInstance()->tours->getTourById((int)$id);
    }

    /**
     * Get total number of tours
     *
     * @return int
     */
    public function getTotalTours(): int
    {
        return count(Boarding::getInstance()->tours->getAllTours());
    }

    /**
     * Get number of completed tours by current user
     *
     * @return int
     */
    public function getCompletedTours(): int
    {
        return count(Boarding::getInstance()->tours->getToursForCurrentUser());
    }

    /**
     * Get all available sections
     *
     * @return array
     */
    public function getAllSections(): array
    {
        return \Craft::$app->entries->getAllSections();
    }
} 