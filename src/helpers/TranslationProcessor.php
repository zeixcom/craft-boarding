<?php

namespace zeix\boarding\helpers;

use Craft;

/**
 * TranslationProcessor - Centralized translation data processing
 * 
 * This class provides consistent methods for handling translation data processing,
 * reducing duplication and ensuring consistent behavior across the plugin.
 */
class TranslationProcessor
{
    /**
     * Apply translations to a tour for a specific site
     * 
     * @param array $tour The tour data
     * @param int $siteId The site ID to apply translations for
     * @param int|null $primarySiteId Primary site ID (will be fetched if null)
     * @return array The tour with translations applied
     */
    public static function applyTranslations(array $tour, int $siteId, ?int $primarySiteId = null): array
    {
        if (empty($tour)) {
            return $tour;
        }

        $propagationMethod = $tour['propagationMethod'] ?? 'none';

        // For single-site tours with inline step translations (legacy data), fix them
        if ($propagationMethod === 'none') {
            return self::fixInlineStepTranslations($tour, $siteId);
        }

        if ($primarySiteId === null) {
            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $primarySiteId = $primarySite->id;
        }

        // For propagating tours (same content across sites), don't apply translations
        // These tours have propagate: true and share the same content
        if (in_array($propagationMethod, ['all', 'language', 'siteGroup'])) {
            return $tour;
        }

        if ($siteId == $primarySiteId) {
            return $tour;
        }

        if (isset($tour['translations']) && isset($tour['translations'][$siteId])) {
            $translation = $tour['translations'][$siteId];

            if (!empty($translation['name'])) {
                $tour['name'] = $translation['name'];
            }
            if (!empty($translation['description'])) {
                $tour['description'] = $translation['description'];
            }

            if (!empty($translation['data'])) {
                $decodedData = JsonCache::decodeTranslationData($translation);
                if (is_array($decodedData) && isset($decodedData['steps']) && !empty($decodedData['steps'])) {
                    $tour['steps'] = $decodedData['steps'];
                }
            }
        }

        return $tour;
    }

    /**
     * Fix inline step translations for single-site tours (legacy data)
     * This handles cases where content was saved in translations object instead of main fields
     * 
     * @param array $tour The tour data
     * @param int $siteId The current site ID
     * @return array The tour with fixed steps
     */
    private static function fixInlineStepTranslations(array $tour, int $siteId): array
    {
        if (!isset($tour['steps']) || !is_array($tour['steps'])) {
            return $tour;
        }

        foreach ($tour['steps'] as $stepIndex => &$step) {
            // Check if main content is empty but translations exist
            if (isset($step['translations']) && is_array($step['translations'])) {
                $mainTitleEmpty = empty($step['title']);
                $mainTextEmpty = empty($step['text']);
                $isNavigationStep = ($step['type'] ?? 'default') === 'navigation';
                $mainNavButtonEmpty = $isNavigationStep && empty($step['navigationButtonText']);

                if ($mainTitleEmpty || $mainTextEmpty || $mainNavButtonEmpty) {
                    // Try current site first
                    $translation = null;
                    if (isset($step['translations'][$siteId])) {
                        $translation = $step['translations'][$siteId];
                    } elseif (isset($step['translations'][(string)$siteId])) {
                        $translation = $step['translations'][(string)$siteId];
                    }

                    // Apply translation if found
                    if ($translation && is_array($translation)) {
                        if ($mainTitleEmpty && !empty($translation['title'])) {
                            $step['title'] = $translation['title'];
                        }
                        if ($mainTextEmpty && !empty($translation['text'])) {
                            $step['text'] = $translation['text'];
                        }
                        if ($mainNavButtonEmpty && !empty($translation['navigationButtonText'])) {
                            $step['navigationButtonText'] = $translation['navigationButtonText'];
                        }
                    }

                    // If still empty, use ANY available translation
                    if (empty($step['title']) && empty($step['text'])) {
                        foreach ($step['translations'] as $translationData) {
                            if (is_array($translationData) && (!empty($translationData['title']) || !empty($translationData['text']))) {
                                $step['title'] = $translationData['title'] ?? '';
                                $step['text'] = $translationData['text'] ?? '';
                                if ($isNavigationStep && !empty($translationData['navigationButtonText'])) {
                                    $step['navigationButtonText'] = $translationData['navigationButtonText'];
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }

        return $tour;
    }

    /**
     * Attach step-level translations to tour steps
     * 
     * @param array $tour The tour data with steps
     * @param array $translations The translations data indexed by site ID
     * @return array The tour with step translations attached
     */
    public static function attachStepTranslations(array $tour, array $translations): array
    {
        if (empty($tour['steps']) || empty($translations)) {
            return $tour;
        }

        foreach ($tour['steps'] as $stepIndex => &$step) {
            $step['translations'] = [];

            foreach ($translations as $siteId => $translation) {
                $translationData = JsonCache::decodeTranslationData($translation, $siteId);
                $translationSteps = $translationData['steps'] ?? [];

                if (isset($translationSteps[$stepIndex])) {
                    $step['translations'][$siteId] = $translationSteps[$stepIndex];
                }
            }
        }

        return $tour;
    }

    /**
     * Process tour data and conditionally load translations
     * 
     * @param array $tour The tour data
     * @param callable $translationLoader Function to load translations (receives tourId)
     * @return array The processed tour
     */
    public static function processTranslatableTour(array $tour, callable $translationLoader): array
    {
        if (self::shouldLoadTranslations($tour)) {
            $tour['translations'] = $translationLoader($tour['id']);
        } else {
            unset($tour['translations']);
        }

        return $tour;
    }

    /**
     * Extract and process step translations from form data
     * 
     * @param array $steps The steps data from form submission
     * @return array Processed steps with translation data
     */
    public static function processStepTranslations(array $steps): array
    {
        $processedSteps = [];

        foreach ($steps as $stepIndex => $step) {
            $processedStep = [
                'title' => $step['title'] ?? '',
                'text' => $step['text'] ?? '',
                'type' => $step['type'] ?? 'default',
            ];

            if (isset($step['attachTo'])) {
                $processedStep['attachTo'] = $step['attachTo'];
            }

            if (($step['type'] ?? 'default') === 'navigation') {
                $processedStep['navigationUrl'] = $step['navigationUrl'] ?? '';
                $processedStep['navigationButtonText'] = $step['navigationButtonText'] ?? 'Continue';
            }

            if (isset($step['translations']) && is_array($step['translations'])) {
                $processedStep['translations'] = [];

                foreach ($step['translations'] as $siteId => $siteTranslation) {
                    $siteId = (int)$siteId;

                    $processedStep['translations'][$siteId] = [
                        'title' => $siteTranslation['title'] ?? '',
                        'text' => $siteTranslation['text'] ?? ''
                    ];

                    if (($step['type'] ?? 'default') === 'navigation' && isset($siteTranslation['navigationButtonText'])) {
                        $processedStep['translations'][$siteId]['navigationButtonText'] = $siteTranslation['navigationButtonText'];
                    }
                }
            }

            $processedSteps[] = $processedStep;
        }

        return $processedSteps;
    }

    /**
     * Clean step data by removing translation information
     * 
     * @param array $steps Array of steps
     * @return array Steps with translations removed
     */
    public static function cleanStepsFromTranslations(array $steps): array
    {
        return array_map(function ($step) {
            unset($step['translations']);
            return $step;
        }, $steps);
    }

    /**
     * Decode translation data safely with caching
     * 
     * @param string $data JSON encoded translation data
     * @return array Decoded data or empty array on failure
     */
    public static function decodeTranslationData(string $data): array
    {
        return JsonCache::decode($data, true, 'translation');
    }

    /**
     * Check if a tour should have translations loaded
     *
     * @param array $tour Tour data
     * @return bool Whether translations should be loaded
     */
    public static function shouldLoadTranslations(array $tour): bool
    {
        $propagationMethod = $tour['propagationMethod'] ?? 'none';

        // Load translations for methods that allow different content per site:
        // - 'custom': unique content per site (propagate: false)
        // - 'language': different content per language
        // - 'siteGroup': different content per site within group
        //
        // 'all' and 'none' don't need translations:
        // - 'all': same content propagated to all sites
        // - 'none': single site only
        return in_array($propagationMethod, ['custom', 'language', 'siteGroup'], true);
    }

    /**
     * Get site-specific translation data for current site context
     * 
     * @param array $translations All translations indexed by site ID
     * @param int $currentSiteId Current site ID
     * @return array Translation data for the current site
     */
    public static function getSiteTranslationForStep(array $translations, int $currentSiteId): array
    {
        if (isset($translations[$currentSiteId])) {
            return $translations[$currentSiteId];
        } elseif (isset($translations[(string)$currentSiteId])) {
            return $translations[(string)$currentSiteId];
        }

        return [];
    }

    /**
     * Merge existing tour data with new translation data
     * 
     * @param array $existingTour Existing tour data
     * @param array $newData New translation data
     * @param bool $preserveSteps Whether to preserve existing steps structure
     * @return array Merged tour data
     */
    public static function mergeTranslationData(array $existingTour, array $newData, bool $preserveSteps = true): array
    {
        $merged = $existingTour;

        if (isset($newData['name'])) {
            $merged['name'] = $newData['name'];
        }
        if (isset($newData['description'])) {
            $merged['description'] = $newData['description'];
        }

        if (isset($newData['steps']) && !$preserveSteps) {
            $merged['steps'] = $newData['steps'];
        } elseif ($preserveSteps && isset($existingTour['steps'])) {
            $merged['steps'] = self::cleanStepsFromTranslations($existingTour['steps']);
        }

        return $merged;
    }
}
