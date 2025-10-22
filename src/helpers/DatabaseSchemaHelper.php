<?php

namespace zeix\boarding\helpers;

use Craft;
use zeix\boarding\utils\Logger;

/**
 * DatabaseSchemaHelper - Centralized database schema checking
 * 
 * This class provides consistent methods for checking column existence and
 * caching results to avoid repeated database queries.
 */
class DatabaseSchemaHelper
{
    /**
     * @var array Cache for column existence checks
     */
    private static array $columnCache = [];


    /**
     * Check if the progressPosition column exists in the tours table
     * 
     * @return bool
     */
    public static function hasProgressPositionColumn(): bool
    {
        return self::columnExists('{{%boarding_tours}}', 'progressPosition');
    }

    /**
     * Check if the autoplay column exists in the tours table
     * 
     * @return bool
     */
    public static function hasAutoplayColumn(): bool
    {
        return self::columnExists('{{%boarding_tours}}', 'autoplay');
    }

    /**
     * Get all available column information for tours table
     * 
     * @return array Column existence information
     */
    public static function getAvailableColumns(): array
    {
        return [
            'hasProgressPosition' => self::hasProgressPositionColumn(),
            'hasAutoplay' => self::hasAutoplayColumn(),
        ];
    }

    /**
     * Check if a column exists in a table with caching
     * 
     * @param string $table Table name
     * @param string $column Column name
     * @return bool
     */
    public static function columnExists(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;

        if (isset(self::$columnCache[$cacheKey])) {
            return self::$columnCache[$cacheKey];
        }

        try {
            $exists = Craft::$app->getDb()->columnExists($table, $column);
            self::$columnCache[$cacheKey] = $exists;
            return $exists;
        } catch (\Exception $e) {
            Logger::error('Error checking column existence: ' . $e->getMessage(), 'boarding');
            self::$columnCache[$cacheKey] = false;
            return false;
        }
    }
}
