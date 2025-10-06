<?php

namespace zeix\boarding\helpers;

use zeix\boarding\utils\Logger;
use zeix\boarding\repositories\TourRepository;

/**
 * BulkTourLoader - Efficient bulk loading of tour-related data
 * 
 * This class eliminates N+1 query problems by bulk loading related data
 * for multiple tours in single queries and caching the results.
 */
class BulkTourLoader
{
    /**
     * @var array Cache for bulk-loaded data
     */
    private static array $cache = [];

    /**
     * @var TourRepository Repository instance
     */
    private static ?TourRepository $repository = null;

    /**
     * Get repository instance
     * 
     * @return TourRepository
     */
    private static function getRepository(): TourRepository
    {
        if (self::$repository === null) {
            self::$repository = new TourRepository();
        }
        return self::$repository;
    }

    /**
     * Bulk load all related data for multiple tours
     * 
     * @param array $tourIds Array of tour IDs
     * @param array $options Loading options
     * @return array Bulk loaded data indexed by type and tour ID
     */
    public static function bulkLoad(array $tourIds, array $options = []): array
    {
        if (empty($tourIds)) {
            return [
                'completions' => [],
                'userGroups' => [],
                'translations' => [],
            ];
        }

        $defaultOptions = [
            'loadCompletions' => true,
            'loadUserGroups' => true,
            'loadTranslations' => true,
            'useCache' => true,
        ];

        $options = array_merge($defaultOptions, $options);
        $cacheKey = self::generateCacheKey($tourIds, $options);

        if ($options['useCache'] && isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $result = [
            'completions' => [],
            'userGroups' => [],
            'translations' => [],
        ];

        try {
            $repository = self::getRepository();

            if ($options['loadCompletions']) {
                $result['completions'] = $repository->bulkLoadCompletions($tourIds);
            }

            if ($options['loadUserGroups']) {
                $result['userGroups'] = $repository->bulkLoadUserGroups($tourIds);
            }

            if ($options['loadTranslations']) {
                $result['translations'] = $repository->bulkLoadTranslations($tourIds);
            }

            if ($options['useCache']) {
                self::$cache[$cacheKey] = $result;
            }
        } catch (\Exception $e) {
            Logger::error('Error bulk loading tour data: ' . $e->getMessage(), 'boarding');

            foreach ($tourIds as $tourId) {
                $result['completions'][$tourId] = [];
                $result['userGroups'][$tourId] = [];
                $result['translations'][$tourId] = [];
            }
        }

        return $result;
    }

    /**
     * Create bulk-aware loaders for use with TourProcessor
     * 
     * @param array $tourIds All tour IDs that will be processed
     * @param array $options Bulk loading options
     * @return array Array of loader functions
     */
    public static function createBulkLoaders(array $tourIds, array $options = []): array
    {
        $bulkData = self::bulkLoad($tourIds, $options);

        return [
            'completions' => function ($tourId) use ($bulkData) {
                return $bulkData['completions'][$tourId] ?? [];
            },
            'userGroups' => function ($tourId) use ($bulkData) {
                return $bulkData['userGroups'][$tourId] ?? [];
            },
            'translations' => function ($tourId) use ($bulkData) {
                return $bulkData['translations'][$tourId] ?? [];
            },
        ];
    }

    /**
     * Create bulk-aware admin loaders for TourProcessor
     * 
     * @param array $tourIds Tour IDs to load
     * @return array Admin loader configuration
     */
    public static function createBulkAdminLoaders(array $tourIds): array
    {
        $bulkLoaders = self::createBulkLoaders($tourIds, [
            'loadCompletions' => true,
            'loadUserGroups' => false, // Admin loads user groups differently
            'loadTranslations' => true,
        ]);

        return [
            'completions' => $bulkLoaders['completions'],
            'translations' => $bulkLoaders['translations'],
        ];
    }

    /**
     * Create bulk-aware user loaders for TourProcessor
     * 
     * @param array $tourIds Tour IDs to load
     * @param callable $applyTranslationsLoader Function to apply translations
     * @return array User loader configuration
     */
    public static function createBulkUserLoaders(array $tourIds, callable $applyTranslationsLoader): array
    {
        $bulkLoaders = self::createBulkLoaders($tourIds);

        return [
            'userGroups' => $bulkLoaders['userGroups'],
            'completions' => $bulkLoaders['completions'],
            'translations' => $bulkLoaders['translations'],
            'applyTranslations' => $applyTranslationsLoader,
        ];
    }

    /**
     * Get completions for a specific tour (fallback to individual query if not bulk loaded)
     * 
     * @param int $tourId Tour ID
     * @return array Completions array
     */
    public static function getCompletions(int $tourId): array
    {
        foreach (self::$cache as $cacheData) {
            if (isset($cacheData['completions'][$tourId])) {
                return $cacheData['completions'][$tourId];
            }
        }

        return self::getRepository()->getCompletions($tourId);
    }

    /**
     * Get user groups for a specific tour (fallback to individual query if not bulk loaded)
     * 
     * @param int $tourId Tour ID
     * @return array User group IDs
     */
    public static function getUserGroups(int $tourId): array
    {
        foreach (self::$cache as $cacheData) {
            if (isset($cacheData['userGroups'][$tourId])) {
                return $cacheData['userGroups'][$tourId];
            }
        }

        return self::getRepository()->getUserGroups($tourId);
    }

    /**
     * Get translations for a specific tour (fallback to individual query if not bulk loaded)
     * 
     * @param int $tourId Tour ID
     * @return array Translations indexed by site ID
     */
    public static function getTranslations(int $tourId): array
    {
        foreach (self::$cache as $cacheData) {
            if (isset($cacheData['translations'][$tourId])) {
                return $cacheData['translations'][$tourId];
            }
        }

        return self::getRepository()->getTranslations($tourId);
    }

    /**
     * Clear the bulk loading cache
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Generate a cache key for bulk loaded data
     * 
     * @param array $tourIds Tour IDs
     * @param array $options Loading options
     * @return string Cache key
     */
    private static function generateCacheKey(array $tourIds, array $options): string
    {
        sort($tourIds);
        return md5(serialize(['tourIds' => $tourIds, 'options' => $options]));
    }
}
