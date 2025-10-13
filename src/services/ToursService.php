<?php

namespace zeix\boarding\services;

use Craft;
use craft\base\Component;
use yii\web\NotFoundHttpException;

/**
 * ToursService - Orchestrating facade for tour operations
 * 
 * This service acts as a facade, coordinating between focused services
 * while maintaining backward compatibility with the original ToursService interface.
 * 
 * This replaces the original ToursService with a cleaner, more maintainable architecture.
 */
class ToursService extends Component
{
    protected TourQueryService $queryService;
    protected TourMutationService $mutationService;
    protected TourTranslationService $translationService;
    protected \craft\web\Request $request;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->request = Craft::$app->getRequest();
        $this->queryService = new TourQueryService();
        $this->mutationService = new TourMutationService();
        $this->translationService = new TourTranslationService();
    }

    /**
     * Get all tours for admin interface
     * 
     * @return array The tours
     */
    public function getAllTours(): array
    {
        return $this->queryService->getAllTours();
    }

    /**
     * Get a tour by ID
     * 
     * @param int $tourId The tour ID
     * @return array|null The tour or null if not found
     */
    public function getTourById(int $tourId): ?array
    {
        return $this->queryService->getTourById($tourId);
    }

    /**
     * Get tours for the current user
     * 
     * @return array The tours
     */
    public function getToursForCurrentUser(): array
    {
        return $this->queryService->getToursForCurrentUser();
    }

    /**
     * Get tour data with translations applied for the current site
     * 
     * @param string $id Tour ID
     * @param int|null $siteId Site ID to apply translations for
     * @return array The tour with translations applied
     * @throws NotFoundHttpException if tour is not found
     */
    public function getTourWithTranslations(string $id, ?int $siteId = null): array
    {
        return $this->queryService->getTourWithTranslations($id, $siteId);
    }

    /**
     * Get tour translations
     * 
     * @param int $tourId Tour ID
     * @return array Translations indexed by site ID
     */
    public function getTourTranslations(int $tourId): array
    {
        return $this->queryService->getTourTranslations($tourId);
    }

    /**
     * Check if a tour has translation for a specific site
     * 
     * @param int $tourId Tour ID
     * @param int $siteId Site ID
     * @return bool Whether translation exists
     */
    public function hasTranslation(int $tourId, int $siteId): bool
    {
        return $this->queryService->hasTranslation($tourId, $siteId);
    }

    /**
     * Check if a tour is enabled for a specific site
     * 
     * @param int $tourId Tour ID
     * @param int $siteId Site ID
     * @return bool Whether the tour is enabled for the site
     */
    public function isTourEnabledForSite(int $tourId, int $siteId): bool
    {
        return $this->queryService->isTourEnabledForSite($tourId, $siteId);
    }

    /**
     * Save a tour
     * 
     * @param array $data Tour data
     * @return bool Whether the save was successful
     */
    public function saveTour(array $data): bool
    {
        return $this->mutationService->saveTour($data);
    }

    /**
     * Delete a tour
     * 
     * @param int|string $id The tour ID or database ID
     * @return bool Success status
     */
    public function deleteTour($id): bool
    {
        return $this->mutationService->deleteTour($id);
    }

    /**
     * Duplicate a tour
     * 
     * @param int $tourId The tour database ID to duplicate
     * @return bool|int Returns the new tour ID on success, false on failure
     */
    public function duplicateTour(int $tourId): bool|int
    {
        return $this->mutationService->duplicateTour($tourId);
    }

    /**
     * Mark a tour as completed for a user
     * 
     * @param string|int $tourId The tour ID or database ID
     * @param int|null $userId The user ID (defaults to current user)
     * @return bool Whether the operation succeeded
     */
    public function markTourCompleted($tourId, $userId = null)
    {
        return $this->mutationService->markTourCompleted($tourId, $userId);
    }

    /**
     * Save user group assignments for a tour
     * 
     * @param int $tourId Tour database ID
     * @param array $userGroupIds Array of user group IDs
     * @return bool Success status
     */
    public function saveTourUserGroups(int $tourId, array $userGroupIds = []): bool
    {
        return $this->mutationService->saveTourUserGroups($tourId, $userGroupIds);
    }

    /**
     * Save user group assignments for multiple tours in a single batch operation
     * 
     * @param array $tourUserGroups Array of [tourId => [userGroupIds]]
     * @return bool Success status
     */
    public function batchSaveTourUserGroups(array $tourUserGroups): bool
    {
        return $this->mutationService->batchSaveTourUserGroups($tourUserGroups);
    }

    /**
     * Validate user group assignments for a tour
     * 
     * @param int $tourId Tour ID
     * @param array $expectedGroupIds Expected group IDs
     * @return bool Whether assignments match expectations
     */
    public function validateTourUserGroups(int $tourId, array $expectedGroupIds = []): bool
    {
        return $this->mutationService->validateTourUserGroups($tourId, $expectedGroupIds);
    }

    /**
     * Set tour enablement for a specific site
     * 
     * @param int $tourId Tour ID
     * @param int $siteId Site ID
     * @param bool $enabled Whether to enable the tour for this site
     * @return bool Success
     */
    public function setTourEnabledForSite(int $tourId, int $siteId, bool $enabled): bool
    {
        return $this->mutationService->setTourEnabledForSite($tourId, $siteId, $enabled);
    }

    /**
     * Apply translations to a tour if applicable
     * 
     * @param array $tour The tour data
     * @param int|null $siteId The site ID (defaults to current site)
     * @return array The tour with translations applied
     */
    public function applyTourTranslations(array $tour, ?int $siteId = null): array
    {
        return $this->translationService->applyTourTranslations($tour, $siteId);
    }

    /**
     * Process steps data from the request
     *
     * @param array $steps The raw steps data
     * @return array Processed steps data
     */
    public function processStepsData(array $steps): array
    {
        return $this->translationService->processStepsData($steps);
    }

    /**
     * Get tours data prepared for the index page
     *
     * This method handles:
     * - Loading all tours
     * - Bulk loading translations for all tours (prevents N+1 queries)
     * - Applying translations for the current site
     * - Calculating edition limits and restrictions
     *
     * @param \craft\models\Site $site Current site
     * @return array Array containing tours and metadata for the index page
     */
    public function getToursForIndex(\craft\models\Site $site): array
    {
        $tours = $this->getAllTours();

        if (empty($tours)) {
            return [
                'tours' => [],
                'isProEdition' => \zeix\boarding\Boarding::getInstance()->is(\zeix\boarding\Boarding::EDITION_PRO),
                'tourCount' => 0,
                'tourLimit' => \zeix\boarding\Boarding::LITE_TOUR_LIMIT,
                'tourLimitReached' => false
            ];
        }

        $tourIds = array_column($tours, 'id');
        $allTranslations = \zeix\boarding\helpers\BulkTourLoader::bulkLoad($tourIds, [
            'loadCompletions' => false,
            'loadUserGroups' => false,
            'loadTranslations' => true,
        ])['translations'];

        foreach ($tours as &$tour) {
            $tour['translations'] = $allTranslations[$tour['id']] ?? [];

            if (!empty($tour['translatable']) && $tour['translatable'] == 1) {
                $tour = $this->applyTourTranslations($tour, $site->id);
            }
        }

        $isProEdition = \zeix\boarding\Boarding::getInstance()->is(\zeix\boarding\Boarding::EDITION_PRO);
        $tourCount = count($tours);
        $tourLimitReached = !$isProEdition && $tourCount >= \zeix\boarding\Boarding::LITE_TOUR_LIMIT;

        return [
            'tours' => $tours,
            'isProEdition' => $isProEdition,
            'tourCount' => $tourCount,
            'tourLimit' => \zeix\boarding\Boarding::LITE_TOUR_LIMIT,
            'tourLimitReached' => $tourLimitReached
        ];
    }

    /**
     * Get tour data prepared for the edit page
     *
     * @param int|null $id Tour ID (null for new tour)
     * @param \craft\models\Site $site Current site
     * @return array Array containing tour and metadata for the edit page
     * @throws \yii\web\NotFoundHttpException if tour not found
     */
    public function getTourForEdit(?int $id, \craft\models\Site $site): array
    {
        $tour = null;
        $primarySite = Craft::$app->getSites()->getPrimarySite();

        if ($id) {
            $tour = $this->getTourById($id);

            if (!$tour) {
                throw new \yii\web\NotFoundHttpException('Tour not found');
            }
        }

        return [
            'tour' => $tour,
            'primarySite' => $primarySite,
            'currentSite' => $site,
            'isProEdition' => \zeix\boarding\Boarding::getInstance()->is(\zeix\boarding\Boarding::EDITION_PRO)
        ];
    }
}
