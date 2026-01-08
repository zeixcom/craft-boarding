<?php

namespace zeix\boarding\helpers;

use zeix\boarding\utils\Logger;

/**
 * JsonCache - Request-scoped JSON decoding cache
 *
 * This class eliminates redundant JSON decoding by caching decoded results
 * within the request lifecycle, improving performance when the same JSON
 * data is accessed multiple times.
 */
class JsonCache
{
    /**
     * @var array Cache for decoded JSON data
     */
    private static array $cache = [];

    /**
     * @var array Statistics for cache performance monitoring
     */
    private static array $stats = [
        'hits' => 0,
        'misses' => 0,
        'errors' => 0,
    ];

    /**
     * Decode JSON with caching support
     *
     * @param string $json JSON string to decode
     * @param bool $associative Whether to return associative array
     * @param string|null $context Optional context for cache key uniqueness
     * @return mixed Decoded JSON data
     */
    public static function decode(string $json, bool $associative = true, ?string $context = null): mixed
    {
        if (empty($json)) {
            return $associative ? [] : null;
        }


        $cacheKey = self::generateCacheKey($json, $associative, $context);

        if (isset(self::$cache[$cacheKey])) {
            self::$stats['hits']++;
            return self::$cache[$cacheKey];
        }

        self::$stats['misses']++;
        
        try {
            $decoded = json_decode($json, $associative);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                self::$stats['errors']++;
                Logger::error('JSON decode error: ' . json_last_error_msg() . ' for data: ' . substr($json, 0, 100), 'boarding');
                return $associative ? [] : null;
            }

            self::$cache[$cacheKey] = $decoded;
            return $decoded;
        } catch (\Exception $e) {
            self::$stats['errors']++;
            Logger::error('Exception during JSON decode: ' . $e->getMessage(), 'boarding');
            return $associative ? [] : null;
        }
    }

    /**
     * Decode tour data JSON with tour-specific caching
     *
     * @param array $tour Tour data array
     * @param string $field Field name containing JSON (default: 'data')
     * @return array Decoded tour data
     */
    public static function decodeTourData(array $tour, string $field = 'data'): array
    {
        if (!isset($tour[$field]) || empty($tour[$field])) {
            return [];
        }

        $tourId = $tour['id'] ?? 'unknown';
        $context = "tour:{$tourId}:{$field}";
        
        return self::decode($tour[$field], true, $context) ?: [];
    }

    /**
     * Decode translation data JSON with translation-specific caching.
     *
     * @param array $translation Translation data array
     * @param int|null $siteId Site ID for context
     * @return array Decoded translation data
     */
    public static function decodeTranslationData(array $translation, ?int $siteId = null): array
    {
        if (!isset($translation['data']) || empty($translation['data'])) {
            return [];
        }

        $context = "translation";
        if ($siteId !== null) {
            $context .= ":{$siteId}";
        }

        return self::decode($translation['data'], true, $context) ?: [];
    }

    /**
     * Decode tour steps with caching.
     *
     * @param array $tour Tour data array
     * @return array Tour steps array
     */
    public static function decodeTourSteps(array $tour): array
    {
        $tourData = self::decodeTourData($tour);
        return $tourData['steps'] ?? [];
    }

    /**
     * Get or decode tour field with caching.
     *
     * @param array $tour Tour data array
     * @param string $fieldName Field name to extract from decoded data
     * @param mixed $default Default value if field not found
     * @return mixed Field value or default
     */
    public static function getTourField(array $tour, string $fieldName, mixed $default = null): mixed
    {
        $tourData = self::decodeTourData($tour);
        return $tourData[$fieldName] ?? $default;
    }

    /**
     * Merge decoded JSON tour data into tour array (avoiding duplicate decoding).
     *
     * @param array $tour Tour data array
     * @return array Tour array with JSON data merged in
     */
    public static function mergeTourData(array $tour): array
    {
        $tourData = self::decodeTourData($tour);
        
        if (empty($tourData)) {
            return $tour;
        }

        // Merge JSON data into tour, but don't overwrite existing keys
        foreach ($tourData as $key => $value) {
            if (!isset($tour[$key])) {
                $tour[$key] = $value;
            }
        }

        return $tour;
    }

    /**
     * Pre-warm cache with commonly accessed data.
     *
     * @param array $tours Array of tour data to pre-decode
     * @param array $options Warming options
     *   - includeTourData (bool): Decode tour data field (default: true)
     *   - includeSteps (bool): Decode steps separately (default: true)
     *   - includeTranslations (bool): Decode translation data (default: false)
     * @return void
     */
    public static function preWarmCache(array $tours, array $options = []): void
    {
        $defaultOptions = [
            'includeTourData' => true,
            'includeSteps' => true,
            'includeTranslations' => false,
        ];

        $options = array_merge($defaultOptions, $options);

        foreach ($tours as $tour) {
            if ($options['includeTourData'] && isset($tour['data']) && !empty($tour['data'])) {
                self::decodeTourData($tour);
            }

            if ($options['includeSteps']) {
                self::decodeTourSteps($tour);
            }

            if ($options['includeTranslations'] && isset($tour['translations'])) {
                foreach ($tour['translations'] as $siteId => $translation) {
                    self::decodeTranslationData($translation, $siteId);
                }
            }
        }
    }

    /**
     * Clear the JSON cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$stats = [
            'hits' => 0,
            'misses' => 0,
            'errors' => 0,
        ];
    }

    /**
     * Get cache statistics.
     *
     * @return array Cache statistics
     */
    public static function getStats(): array
    {
        $hits = (int)self::$stats['hits'];
        $misses = (int)self::$stats['misses'];
        $total = $hits + $misses;
        $hitRate = $total > 0 ? round(($hits / $total) * 100, 2) : 0;
        
        return [
            'hits' => self::$stats['hits'],
            'misses' => self::$stats['misses'],
            'errors' => self::$stats['errors'],
            'total_requests' => $total,
            'hit_rate_percent' => $hitRate,
            'cache_size' => count(self::$cache),
        ];
    }

    /**
     * Generate cache key for JSON data.
     *
     * @param string $json JSON string
     * @param bool $associative Whether associative array was requested
     * @param string|null $context Optional context
     * @return string Cache key
     */
    private static function generateCacheKey(string $json, bool $associative, ?string $context): string
    {
        $key = md5($json) . ($associative ? ':assoc' : ':obj');
        
        if ($context !== null) {
            $key = $context . ':' . $key;
        }
        
        return $key;
    }
}
