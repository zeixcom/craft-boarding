<?php

namespace zeix\boarding\services;

use Craft;
use craft\base\Component;
use zeix\boarding\repositories\TourRepository;
use zeix\boarding\helpers\DatabaseSchemaHelper;
use zeix\boarding\helpers\TranslationProcessor;
use zeix\boarding\helpers\TourProcessor;
use zeix\boarding\helpers\JsonCache;
use zeix\boarding\utils\Logger;
use zeix\boarding\helpers\SiteHelper;
use zeix\boarding\handlers\ErrorHandler;
use zeix\boarding\exceptions\TourNotFoundException;
use yii\web\NotFoundHttpException;

/**
 * TourQueryService - Focused service for tour data retrieval
 * 
 * This service handles all tour querying and data retrieval operations,
 * providing a clean interface for fetching tour data in various contexts.
 */
class TourQueryService extends Component
{
    protected TourRepository $repository;
    protected \craft\web\Request $request;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        
        $this->repository = new TourRepository();
        $this->request = Craft::$app->getRequest();
    }

    /**
     * Get all tours for admin interface
     * 
     * @return array The tours
     */
    public function getAllTours(): array
    {
        return ErrorHandler::wrap(function() {
            $columns = $this->getExistingTourColumns();

            $tours = $this->repository->findAll([
                'includeTranslatable' => $columns['hasTranslatable'],
                'enabledOnly' => false
            ]);

            JsonCache::preWarmCache($tours);

            $options = TourProcessor::getAdminProcessingOptions($columns);
            return TourProcessor::processToursWithBulkLoading($tours, $options, [
                'loadCompletions' => true,
                'loadUserGroups' => true, 
                'loadTranslations' => true,
            ]);
        }, [
            'operation' => 'getAllTours'
        ]);
    }

    /**
     * Get a tour by ID with all related data
     * 
     * @param int $tourId The tour ID
     * @return array|null The tour or null if not found
     * @throws TourNotFoundException When tour doesn't exist
     */
    public function getTourById(int $tourId): ?array
    {
        return ErrorHandler::wrap(function() use ($tourId) {
            $tour = $this->repository->findById($tourId);

            if (!$tour) {
                throw TourNotFoundException::forTourId($tourId);
            }

            $tour['userGroups'] = $this->repository->getUserGroups($tourId);
            $tour['completedBy'] = $this->repository->getCompletions($tourId);
            $tour['steps'] = JsonCache::decodeTourSteps($tour);

            $tour = TranslationProcessor::processTranslatableTour($tour, function($tourId) {
                return $this->repository->getTranslations($tourId);
            });

            if (TranslationProcessor::shouldLoadTranslations($tour) && isset($tour['translations'])) {
                $tour = TranslationProcessor::attachStepTranslations($tour, $tour['translations']);
            }

            return $tour;
        }, [
            'operation' => 'getTourById',
            'tourId' => $tourId
        ]);
    }

    /**
     * Get tours for the current user
     * 
     * @return array The tours
     */
    public function getToursForCurrentUser(): array
    {
        return ErrorHandler::wrap(function() {
            $user = Craft::$app->getUser()->getIdentity();
            if (!$user) {
                return [];
            }

            $userGroupIds = $this->getUserGroupIds($user);

            $columns = $this->getExistingTourColumns();

            $currentSite = SiteHelper::getSiteForRequest($this->request, true);

            $tours = $this->repository->findForUser($user->id, $userGroupIds, [
                'includeTranslatable' => $columns['hasTranslatable'],
                'siteId' => $currentSite->id
            ]);

            JsonCache::preWarmCache($tours);

            $currentSite = SiteHelper::getSiteForRequest($this->request, true);
            $options = TourProcessor::getUserProcessingOptions($currentSite->id);
            $options['additionalLoaders'] = [
                'applyTranslations' => function($tour, $siteId) { 
                    return $this->applyTourTranslations($tour, $siteId); 
                }
            ];

            return TourProcessor::processToursWithBulkLoading($tours, $options, [
                'loadCompletions' => true,
                'loadUserGroups' => true,
                'loadTranslations' => true,
            ]);
        }, [
            'operation' => 'getToursForCurrentUser'
        ]);
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
        $tour = $this->getTourById((int)$id);

        if (!$tour) {
            throw new NotFoundHttpException('Tour not found');
        }

        if ($siteId) {
            $tour = $this->applyTourTranslations($tour, $siteId);
        }

        return $tour;
    }

    /**
     * Get tour translations
     * 
     * @param int $tourId Tour ID
     * @return array Translations indexed by site ID
     */
    public function getTourTranslations(int $tourId): array
    {
        return $this->repository->getTranslations($tourId);
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
        $translations = $this->repository->getTranslations($tourId);
        return isset($translations[$siteId]);
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
        return $this->repository->isEnabledForSite($tourId, $siteId);
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
        if ($siteId === null) {
            $currentSite = SiteHelper::getSiteForRequest($this->request, true);
            $siteId = $currentSite->id;
        }

        $primarySite = Craft::$app->getSites()->getPrimarySite();
        return TranslationProcessor::applyTranslations($tour, $siteId, $primarySite->id);
    }

    /**
     * Get user group IDs for a user
     * 
     * @param \craft\elements\User $user The user
     * @return array Array of group IDs
     */
    private function getUserGroupIds($user): array
    {
        $userGroups = Craft::$app->getUserGroups()->getGroupsByUserId($user->id);
        return array_map(function ($group) {
            return $group->id;
        }, $userGroups);
    }

    /**
     * Check which columns exist in the tours table
     * 
     * @return array Column existence information
     */
    private function getExistingTourColumns(): array
    {
        return DatabaseSchemaHelper::getAvailableColumns();
    }
}