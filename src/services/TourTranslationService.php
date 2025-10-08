<?php

namespace zeix\boarding\services;

use Craft;
use craft\base\Component;
use zeix\boarding\helpers\TranslationProcessor;
use zeix\boarding\helpers\SiteHelper;
use zeix\boarding\utils\Logger;

/**
 * TourTranslationService - Focused service for tour translation operations.
 *
 * This service handles all tour translation processing, step translation handling,
 * and site-specific translation logic.
 */
class TourTranslationService extends Component
{
    protected \craft\web\Request $request;
    protected ?TourQueryService $queryService = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->request = Craft::$app->getRequest();
    }

    /**
     * Get or create TourQueryService instance.
     *
     * @return TourQueryService
     */
    protected function getQueryService(): TourQueryService
    {
        if ($this->queryService === null) {
            $this->queryService = new TourQueryService();
        }
        return $this->queryService;
    }

    /**
     * Apply translations to a tour for a specific site.
     * 
     * @param array $tour The tour data
     * @param int|null $siteId The site ID (defaults to current site)
     * @return array The tour with translations applied
     */
    public function applyTourTranslations(array $tour, ?int $siteId = null): array
    {
        if ($siteId === null) {
            $currentSite = SiteHelper::getSiteForRequestAuto($this->request);
            $siteId = $currentSite->id;
        }

        $primarySite = Craft::$app->getSites()->getPrimarySite();
        return TranslationProcessor::applyTranslations($tour, $siteId, $primarySite->id);
    }

    /**
     * Process steps data from the request, handling translations.
     *
     * @param array $steps The raw steps data
     * @return array Processed steps data
     */
    public function processStepsData(array $steps): array
    {
        try {
            $processedSteps = TranslationProcessor::processStepTranslations($steps);

            $currentSite = SiteHelper::getSiteForRequestAuto($this->request);
            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $isEditingInPrimarySite = $currentSite->id == $primarySite->id;

            if (!$isEditingInPrimarySite) {
                $processedSteps = $this->handleNonPrimarySiteEditing($processedSteps, $currentSite);
            }

            return $processedSteps;
        } catch (\Exception $e) {
            Logger::error('Error processing steps data: ' . $e->getMessage(), 'boarding');
            return $steps;
        }
    }

    /**
     * Attach step-level translations to tour steps.
     * 
     * @param array $tour The tour data with steps
     * @param array $translations The translations data indexed by site ID
     * @return array The tour with step translations attached
     */
    public function attachStepTranslations(array $tour, array $translations): array
    {
        return TranslationProcessor::attachStepTranslations($tour, $translations);
    }

    /**
     * Process a tour to conditionally load translations.
     * 
     * @param array $tour The tour data
     * @param callable $translationLoader Function to load translations (receives tourId)
     * @return array The processed tour
     */
    public function processTranslatableTour(array $tour, callable $translationLoader): array
    {
        return TranslationProcessor::processTranslatableTour($tour, $translationLoader);
    }

    /**
     * Check if a tour should have translations loaded.
     * 
     * @param array $tour Tour data
     * @return bool Whether translations should be loaded
     */
    public function shouldLoadTranslations(array $tour): bool
    {
        return TranslationProcessor::shouldLoadTranslations($tour);
    }

    /**
     * Get site-specific translation data for current site context.
     * 
     * @param array $translations All translations indexed by site ID
     * @param int $currentSiteId Current site ID
     * @return array Translation data for the current site
     */
    public function getSiteTranslationForStep(array $translations, int $currentSiteId): array
    {
        return TranslationProcessor::getSiteTranslationForStep($translations, $currentSiteId);
    }

    /**
     * Clean step data by removing translation information.
     * 
     * @param array $steps Array of steps
     * @return array Steps with translations removed
     */
    public function cleanStepsFromTranslations(array $steps): array
    {
        return TranslationProcessor::cleanStepsFromTranslations($steps);
    }

    /**
     * Merge existing tour data with new translation data.
     * 
     * @param array $existingTour Existing tour data
     * @param array $newData New translation data
     * @param bool $preserveSteps Whether to preserve existing steps structure
     * @return array Merged tour data
     */
    public function mergeTranslationData(array $existingTour, array $newData, bool $preserveSteps = true): array
    {
        return TranslationProcessor::mergeTranslationData($existingTour, $newData, $preserveSteps);
    }

    /**
     * Decode translation data safely with caching.
     * 
     * @param string $data JSON encoded translation data
     * @return array Decoded data or empty array on failure
     */
    public function decodeTranslationData(string $data): array
    {
        return TranslationProcessor::decodeTranslationData($data);
    }

    /**
     * Validate translation data for completeness and correctness.
     * 
     * @param array $translationData Translation data to validate
     * @param array $originalSteps Original steps for reference
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validateTranslationData(array $translationData, array $originalSteps = []): array
    {
        $errors = [];
        $valid = true;

        try {
            if (!isset($translationData['name'])) {
                $errors[] = 'Translation name is required';
                $valid = false;
            }

            if (!isset($translationData['description'])) {
                $errors[] = 'Translation description is required';
                $valid = false;
            }

            if (isset($translationData['steps']) && is_array($translationData['steps'])) {
                foreach ($translationData['steps'] as $stepIndex => $step) {
                    if (!isset($step['title']) || empty(trim($step['title']))) {
                        $errors[] = "Step {$stepIndex} title is required";
                        $valid = false;
                    }

                    if (!isset($step['text']) || empty(trim($step['text']))) {
                        $errors[] = "Step {$stepIndex} text is required";
                        $valid = false;
                    }
                }

                if (!empty($originalSteps) && count($translationData['steps']) !== count($originalSteps)) {
                    $errors[] = 'Translation step count must match original tour steps';
                    $valid = false;
                }
            }

        } catch (\Exception $e) {
            $errors[] = 'Error validating translation data: ' . $e->getMessage();
            $valid = false;
            Logger::error('Translation validation error: ' . $e->getMessage(), 'boarding');
        }

        return [
            'valid' => $valid,
            'errors' => $errors
        ];
    }

    /**
     * Get available sites for translation.
     * 
     * @param bool $excludePrimary Whether to exclude the primary site
     * @return array Array of site models
     */
    public function getAvailableSitesForTranslation(bool $excludePrimary = true): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        
        if ($excludePrimary) {
            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $sites = array_filter($sites, function($site) use ($primarySite) {
                return $site->id !== $primarySite->id;
            });
        }

        return array_values($sites);
    }

    /**
     * Get translation completion status for a tour across all sites.
     * 
     * @param array $tour Tour data with translations
     * @return array Translation status indexed by site ID
     */
    public function getTranslationCompletionStatus(array $tour): array
    {
        $status = [];
        $availableSites = $this->getAvailableSitesForTranslation();
        $translations = $tour['translations'] ?? [];

        foreach ($availableSites as $site) {
            $siteId = $site->id;
            $hasTranslation = isset($translations[$siteId]);
            
            if ($hasTranslation) {
                $translation = $translations[$siteId];
                $isComplete = $this->isTranslationComplete($translation, $tour);
                
                $status[$siteId] = [
                    'site' => $site,
                    'hasTranslation' => true,
                    'isComplete' => $isComplete,
                    'enabled' => $translation['enabled'] ?? true
                ];
            } else {
                $status[$siteId] = [
                    'site' => $site,
                    'hasTranslation' => false,
                    'isComplete' => false,
                    'enabled' => false
                ];
            }
        }

        return $status;
    }

    /**
     * Check if a translation is complete compared to the original tour.
     * 
     * @param array $translation Translation data
     * @param array $originalTour Original tour data
     * @return bool Whether the translation is complete
     */
    public function isTranslationComplete(array $translation, array $originalTour): bool
    {
        try {
            if (empty($translation['name']) || empty($translation['description'])) {
                return false;
            }

            if (isset($originalTour['steps']) && !empty($originalTour['steps'])) {
                $translationSteps = [];
                
                if (!empty($translation['data'])) {
                    $decodedData = $this->decodeTranslationData($translation['data']);
                    $translationSteps = $decodedData['steps'] ?? [];
                }

                if (count($translationSteps) !== count($originalTour['steps'])) {
                    return false;
                }

                foreach ($translationSteps as $step) {
                    if (empty($step['title']) || empty($step['text'])) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            Logger::error('Error checking translation completeness: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Handle non-primary site editing special cases.
     * 
     * @param array $processedSteps Processed steps data
     * @param \craft\models\Site $currentSite Current site
     * @return array Modified steps data
     */
    private function handleNonPrimarySiteEditing(array $processedSteps, $currentSite): array
    {
        try {
            $tourId = $this->request->getBodyParam('tourId');
            $isTranslatable = $this->request->getBodyParam('translatable', false);

            if (!empty($tourId) && $isTranslatable) {
                // Use injected service instead of creating new instance
                $existingTour = $this->getQueryService()->getTourById($tourId);
                $existingSteps = $existingTour['steps'] ?? [];

                foreach ($processedSteps as $index => &$processedStep) {
                    if (!isset($processedStep['attachTo']) && isset($existingSteps[$index]['attachTo'])) {
                        $processedStep['attachTo'] = $existingSteps[$index]['attachTo'];
                    }
                }
            }

            return $processedSteps;
        } catch (\Exception $e) {
            Logger::error('Error handling non-primary site editing: ' . $e->getMessage(), 'boarding');
            return $processedSteps;
        }
    }
}