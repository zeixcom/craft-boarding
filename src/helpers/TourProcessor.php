<?php

namespace zeix\boarding\helpers;

/**
 * TourProcessor - Unified tour data processing pipeline
 * 
 * This class provides a consistent approach to processing tour data,
 * eliminating duplication between different tour processing contexts.
 */
class TourProcessor
{
    /**
     * Process a single tour with all pro transformations
     * 
     * @param array $tour Raw tour data
     * @param array $options Processing options
     * @param array $loaders Callable loaders for external data
     * @return array Processed tour data
     */
    public static function processTour(array $tour, array $options = [], array $loaders = []): array
    {
        $defaultOptions = [
            'loadUserGroups' => true,
            'loadCompletions' => true,
            'loadTranslations' => true,
            'processJsonData' => true,
            'extractSteps' => true,
            'applyTranslations' => false,
            'processProgress' => false,
            'ensureStepsArray' => true,
            'siteId' => null,
            'hasTranslatableColumn' => true,
        ];

        $options = array_merge($defaultOptions, $options);
        $processedTour = $tour;

        if (!isset($processedTour['id'])) {
            return $processedTour;
        }

        if (!$options['hasTranslatableColumn']) {
            $processedTour['translatable'] = false;
        } elseif (isset($processedTour['translatable'])) {
            // Convert numeric translatable values to boolean
            $processedTour['translatable'] = (bool) $processedTour['translatable'];
        }

        if ($options['loadUserGroups']) {
            $processedTour = self::processUserGroups($processedTour, $loaders);
        }

        if ($options['loadCompletions']) {
            $processedTour = self::loadCompletions($processedTour, $loaders);
        }

        $processedTour = self::cleanupFields($processedTour);

        if ($options['processJsonData']) {
            $processedTour = self::processJsonData($processedTour);
        }

        if ($options['extractSteps']) {
            $processedTour = self::extractSteps($processedTour);
        }

        if ($options['loadTranslations']) {
            $processedTour = self::loadTranslations($processedTour, $loaders);
        }

        if ($options['applyTranslations'] && $options['siteId']) {
            $processedTour = self::applyTranslations($processedTour, $options['siteId'], $loaders);
        }

        if ($options['processProgress']) {
            $processedTour = self::processProgress($processedTour);
        }

        if ($options['ensureStepsArray'] && !isset($processedTour['steps'])) {
            $processedTour['steps'] = [];
        }

        return $processedTour;
    }

    /**
     * Process multiple tours using the unified pipeline
     * 
     * @param array $tours Array of tour data
     * @param array $options Processing options
     * @param array $loaders Callable loaders for external data
     * @return array Processed tours
     */
    public static function processTours(array $tours, array $options = [], array $loaders = []): array
    {
        $processed = [];

        foreach ($tours as $tour) {
            $processed[] = self::processTour($tour, $options, $loaders);
        }

        return $processed;
    }

    /**
     * Process multiple tours with bulk loading for optimal performance
     * 
     * @param array $tours Array of tour data
     * @param array $options Processing options
     * @param array $bulkOptions Bulk loading options
     * @return array Processed tours
     */
    public static function processToursWithBulkLoading(array $tours, array $options = [], array $bulkOptions = []): array
    {
        if (empty($tours)) {
            return [];
        }

        // Extract tour IDs for bulk loading
        $tourIds = array_column($tours, 'id');
        $tourIds = array_filter($tourIds); // Remove any null/empty IDs

        if (empty($tourIds)) {
            return self::processTours($tours, $options, []);
        }

        // Create bulk loaders
        $bulkLoaders = BulkTourLoader::createBulkLoaders($tourIds, $bulkOptions);

        // Merge with any additional loaders provided
        $loaders = array_merge($bulkLoaders, $options['additionalLoaders'] ?? []);

        return self::processTours($tours, $options, $loaders);
    }

    /**
     * Process user groups from raw data
     * 
     * @param array $tour Tour data
     * @param array $loaders Loaders array
     * @return array Tour with processed user groups
     */
    private static function processUserGroups(array $tour, array $loaders): array
    {
        if (isset($tour['userGroupIds']) && is_string($tour['userGroupIds'])) {
            $userGroups = array_filter(explode(',', $tour['userGroupIds']));
            $tour['userGroups'] = array_map('intval', $userGroups);
        } elseif (!isset($tour['userGroups']) && isset($loaders['userGroups'])) {
            $tour['userGroups'] = $loaders['userGroups']($tour['id']);
        } elseif (!isset($tour['userGroups'])) {
            $tour['userGroups'] = [];
        }

        return $tour;
    }

    /**
     * Load completion data
     * 
     * @param array $tour Tour data
     * @param array $loaders Loaders array
     * @return array Tour with completion data
     */
    private static function loadCompletions(array $tour, array $loaders): array
    {
        if (!isset($tour['completedBy']) && isset($loaders['completions'])) {
            $tour['completedBy'] = $loaders['completions']($tour['id']);
        } elseif (!isset($tour['completedBy'])) {
            $tour['completedBy'] = [];
        }

        return $tour;
    }

    /**
     * Clean up unwanted fields
     * 
     * @param array $tour Tour data
     * @return array Cleaned tour data
     */
    private static function cleanupFields(array $tour): array
    {
        $fieldsToRemove = ['sections', 'userGroupIds'];

        foreach ($fieldsToRemove as $field) {
            unset($tour[$field]);
        }

        return $tour;
    }

    /**
     * Process JSON data field and merge into tour with caching
     * 
     * @param array $tour Tour data
     * @return array Tour with JSON data processed
     */
    private static function processJsonData(array $tour): array
    {
        return JsonCache::mergeTourData($tour);
    }

    /**
     * Extract steps from data field with caching
     * 
     * @param array $tour Tour data
     * @return array Tour with steps extracted
     */
    private static function extractSteps(array $tour): array
    {
        if (isset($tour['steps'])) {
            return $tour;
        }

        $tour['steps'] = JsonCache::decodeTourSteps($tour);
        return $tour;
    }

    /**
     * Load translations using provided loader
     * 
     * @param array $tour Tour data
     * @param array $loaders Loaders array
     * @return array Tour with translations loaded
     */
    private static function loadTranslations(array $tour, array $loaders): array
    {
        if (!isset($tour['translations']) && isset($loaders['translations'])) {
            $tour = TranslationProcessor::processTranslatableTour($tour, $loaders['translations']);
        }

        return $tour;
    }

    /**
     * Apply translations for a specific site
     * 
     * @param array $tour Tour data
     * @param int $siteId Site ID
     * @param array $loaders Loaders array
     * @return array Tour with translations applied
     */
    private static function applyTranslations(array $tour, int $siteId, array $loaders): array
    {
        if (isset($tour['translatable']) && $tour['translatable'] && isset($loaders['applyTranslations'])) {
            return $loaders['applyTranslations']($tour, $siteId);
        }

        return $tour;
    }

    /**
     * Process progress information for user-specific tours
     * 
     * @param array $tour Tour data
     * @return array Tour with progress processed
     */
    private static function processProgress(array $tour): array
    {
        if (isset($tour['progress'])) {
            $tour['currentStep'] = $tour['progress']['currentStep'] ?? 0;
            $tour['completed'] = $tour['progress']['completed'] ?? false;
            unset($tour['progress']);
        } else {
            $tour['currentStep'] = 0;
            $tour['completed'] = false;
        }

        // Calculate completion count from completedBy array
        if (isset($tour['completedBy']) && is_array($tour['completedBy'])) {
            $tour['completionCount'] = count($tour['completedBy']);
        }

        return $tour;
    }

    /**
     * Create a loader configuration for admin tour processing
     * 
     * @param callable $completionsLoader Function to load completions
     * @param callable $translationsLoader Function to load translations
     * @return array Loader configuration
     */
    public static function createAdminLoaders(callable $completionsLoader, callable $translationsLoader): array
    {
        return [
            'completions' => $completionsLoader,
            'translations' => $translationsLoader,
        ];
    }

    /**
     * Create a loader configuration for user tour processing
     * 
     * @param callable $userGroupsLoader Function to load user groups
     * @param callable $completionsLoader Function to load completions
     * @param callable $translationsLoader Function to load translations
     * @param callable $applyTranslationsLoader Function to apply translations
     * @return array Loader configuration
     */
    public static function createUserLoaders(
        callable $userGroupsLoader,
        callable $completionsLoader,
        callable $translationsLoader,
        callable $applyTranslationsLoader
    ): array {
        return [
            'userGroups' => $userGroupsLoader,
            'completions' => $completionsLoader,
            'translations' => $translationsLoader,
            'applyTranslations' => $applyTranslationsLoader,
        ];
    }

    /**
     * Get processing options for admin context
     * 
     * @param array $columns Column availability information
     * @return array Processing options
     */
    public static function getAdminProcessingOptions(array $columns): array
    {
        return [
            'loadUserGroups' => true,
            'loadCompletions' => true,
            'loadTranslations' => true,
            'processJsonData' => true,
            'extractSteps' => false,
            'applyTranslations' => false,
            'processProgress' => false,
            'ensureStepsArray' => false,
            'hasTranslatableColumn' => $columns['hasTranslatable'] ?? true,
        ];
    }

    /**
     * Get processing options for user context
     * 
     * @param int $siteId Current site ID
     * @return array Processing options
     */
    public static function getUserProcessingOptions(int $siteId): array
    {
        return [
            'loadUserGroups' => true,
            'loadCompletions' => true,
            'loadTranslations' => true,
            'processJsonData' => false,
            'extractSteps' => true,
            'applyTranslations' => true,
            'processProgress' => true,
            'ensureStepsArray' => true,
            'siteId' => $siteId,
        ];
    }
}
