<?php

namespace zeix\boarding\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use craft\helpers\Db;
use yii\web\NotFoundHttpException;
use zeix\boarding\repositories\TourRepository;
use zeix\boarding\helpers\TranslationProcessor;
use zeix\boarding\helpers\BulkTourLoader;
use zeix\boarding\helpers\DatabaseSchemaHelper;
use zeix\boarding\helpers\TourProcessor;
use zeix\boarding\helpers\JsonCache;
use zeix\boarding\helpers\UserGroupProcessor;
use zeix\boarding\helpers\SiteHelper;
use zeix\boarding\utils\Logger;
use zeix\boarding\exceptions\TourException;
use zeix\boarding\Boarding;
use zeix\boarding\models\Tour;

/**
 * ToursService - Unified service for all tour operations
 *
 * This consolidated service handles all tour-related operations including:
 * - Querying tours and tour data
 * - Creating, updating, and deleting tours
 * - Managing translations
 * - Handling user groups and completions
 *
 * Replaces the previous split architecture (TourQueryService, TourMutationService, TourTranslationService)
 */
class ToursService extends Component
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

    // ==========================================
    // QUERY METHODS
    // ==========================================

    /**
     * Get all tours for admin interface
     *
     * @return array The tours
     */
    public function getAllTours(): array
    {
        try {
            $columns = $this->getExistingTourColumns();
            $currentSite = SiteHelper::getSiteForRequestAuto($this->request);
            $tourElements = Tour::find()
                ->siteId($currentSite->id)
                ->status(null)
                ->orderBy(['dateCreated' => SORT_DESC])
                ->all();

            $tours = [];
            foreach ($tourElements as $tour) {
                $tours[] = [
                    'id' => $tour->id,
                    'tourId' => $tour->tourId,
                    'name' => $tour->title,
                    'description' => $tour->description,
                    'enabled' => $tour->enabled,
                    'propagationMethod' => $tour->propagationMethod,
                    'progressPosition' => $tour->progressPosition,
                    'autoplay' => $tour->autoplay,
                    'data' => $tour->getData(),
                    'siteId' => $tour->siteId,
                    'dateCreated' => $tour->dateCreated,
                    'dateUpdated' => $tour->dateUpdated,
                    'uid' => $tour->uid,
                ];
            }

            JsonCache::preWarmCache($tours);
            
            $options = TourProcessor::getAdminProcessingOptions($columns);
            return TourProcessor::processToursWithBulkLoading($tours, $options, [
                'loadCompletions' => true,
                'loadUserGroups' => true,
                'loadTranslations' => true,
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get all tours: ' . $e->getMessage(), 'boarding');
            throw $e;
        }
    }

    /**
     * Get a tour by ID
     *
     * @param int $tourId The tour ID
     * @return array|null The tour or null if not found
     */
    public function getTourById(int $tourId): ?array
    {
        try {
            $tour = $this->repository->findById($tourId);

            if (!$tour) {
                throw TourException::notFound($tourId);
            }

            $tour['userGroups'] = $this->repository->getUserGroups($tourId);
            $tour['completedBy'] = $this->repository->getCompletions($tourId);
            $tour['steps'] = JsonCache::decodeTourSteps($tour);

            $tour = TranslationProcessor::processTranslatableTour($tour, function ($tourId) {
                return $this->repository->getTranslations($tourId);
            });

            if (TranslationProcessor::shouldLoadTranslations($tour) && isset($tour['translations'])) {
                $tour = TranslationProcessor::attachStepTranslations($tour, $tour['translations']);
            }

            return $tour;
        } catch (\Exception $e) {
            Logger::error('Failed to get tour by ID: ' . $e->getMessage(), 'boarding');
            throw $e;
        }
    }

    /**
     * Get tours for the current user
     *
     * @return array The tours
     */
    public function getToursForCurrentUser(): array
    {
        try {
            $user = Craft::$app->getUser()->getIdentity();
            if (!$user) {
                Logger::info('getToursForCurrentUser: No authenticated user', [], 'boarding');
                return [];
            }

            $userGroupIds = $this->getUserGroupIds($user);
            $currentSite = SiteHelper::getSiteForRequestAuto($this->request);
            $isAdmin = $user->admin;

            Logger::info('getToursForCurrentUser: User ID: ' . $user->id . ', isAdmin: ' . ($isAdmin ? 'yes' : 'no') . ', userGroupIds: ' . json_encode($userGroupIds), 'boarding');

            $query = Tour::find()
                ->siteId($currentSite->id)
                ->status(null)
                ->orderBy(['dateCreated' => SORT_DESC]);

            if (!$isAdmin) {
                Logger::info('getToursForCurrentUser: Applying userGroupId filter', 'boarding');
                $query->userGroupId($userGroupIds);
            } else {
                Logger::info('getToursForCurrentUser: Skipping userGroupId filter (admin user)', 'boarding');
            }

            $tourElements = $query->all();
            Logger::info('getToursForCurrentUser: Found ' . count($tourElements) . ' tours', 'boarding');

            $tours = [];
            foreach ($tourElements as $tour) {
                $tours[] = [
                    'id' => $tour->id,
                    'tourId' => $tour->tourId,
                    'name' => $tour->title,
                    'description' => $tour->description,
                    'enabled' => $tour->enabled,
                    'propagationMethod' => $tour->propagationMethod,
                    'progressPosition' => $tour->progressPosition,
                    'autoplay' => $tour->autoplay,
                    'data' => $tour->getData(),
                    'siteId' => $tour->siteId,
                    'dateCreated' => $tour->dateCreated,
                    'dateUpdated' => $tour->dateUpdated,
                    'uid' => $tour->uid,
                ];
            }

            JsonCache::preWarmCache($tours);
            $options = TourProcessor::getUserProcessingOptions($currentSite->id);
            $options['additionalLoaders'] = [
                'applyTranslations' => function ($tour, $siteId) {
                    return $this->applyTourTranslations($tour, $siteId);
                }
            ];

            return TourProcessor::processToursWithBulkLoading($tours, $options, [
                'loadCompletions' => true,
                'loadUserGroups' => true,
                'loadTranslations' => true,
            ]);
        } catch (\Exception $e) {
            Logger::error('Failed to get tours for current user: ' . $e->getMessage(), 'boarding');
            throw $e;
        }
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

    // ==========================================
    // MUTATION METHODS
    // ==========================================

    /**
     * Save a tour (create or update)
     *
     * @param array $data Tour data
     * @return bool Whether the save was successful
     * @throws TourException When save or validation fails
     */
    public function saveTour(array $data): bool
    {
        $currentSite = SiteHelper::getSiteForRequestAuto($this->request);

        try {
            $this->validateTourData($data);

            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $isCurrentSitePrimary = $currentSite->id === $primarySite->id;
            $tourDbData = [
                'tourId' => $data['tourId'] ?? 'tour_' . StringHelper::UUID(),
                'siteId' => $data['siteId'] ?? $primarySite->id,
                'dateCreated' => isset($data['dateCreated']) ? $data['dateCreated'] : Db::prepareDateForDb(new \DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                'progressPosition' => $data['progressPosition'],
                'autoplay' => $data['autoplay'] ?? false,
            ];

            if ($isCurrentSitePrimary || !isset($data['id'])) {
                $tourDbData['enabled'] = $data['enabled'] ?? 1;
            } else {
                if (isset($data['id'])) {
                    $existingTour = $this->repository->findById($data['id']);
                    $tourDbData['enabled'] = $existingTour ? $existingTour['enabled'] : 1;
                } else {
                    $tourDbData['enabled'] = 1;
                }
            }

            if (isset($data['id'])) {
                $tourDbData['id'] = $data['id'];
            }

            if ($isCurrentSitePrimary) {
                $tourDbData['name'] = $data['name'] ?? '';
                $tourDbData['description'] = $data['description'] ?? '';
                $tourDbData['data'] = json_encode(['steps' => $data['steps'] ?? []]);
            } else {
                $existingTour = null;
                if (isset($data['id'])) {
                    $existingTour = $this->repository->findById($data['id']);
                }

                if ($existingTour) {
                    $tourDbData['name'] = $existingTour['name'] ?? '';
                    $tourDbData['description'] = $existingTour['description'] ?? '';
                    $existingSteps = JsonCache::decodeTourSteps($existingTour);
                    $cleanSteps = TranslationProcessor::cleanStepsFromTranslations($existingSteps);
                    $tourDbData['data'] = json_encode(['steps' => $cleanSteps]);
                } else {
                    $tourDbData['name'] = '';
                    $tourDbData['description'] = '';
                    $tourDbData['data'] = json_encode(['steps' => []]);
                }
            }

            $tourId = $this->repository->save($tourDbData);

            if (!$tourId) {
                throw TourException::saveFailed('database operation failed');
            }

            $propagationMethod = $data['propagationMethod'] ?? 'none';
            $isTranslatable = $propagationMethod !== 'none';
            $hasSiteEnabledData = !empty($data['siteEnabled']) && count(Craft::$app->getSites()->getAllSites()) > 1;

            if ($isTranslatable || $hasSiteEnabledData) {
                if (!$this->saveTranslations($tourId, $data, $currentSite, $primarySite)) {
                    throw TourException::saveFailed('failed to save translations');
                }
            }

            if (isset($data['userGroupIds'])) {
                if (!$this->saveTourUserGroups($tourId, $data['userGroupIds'])) {
                    throw TourException::saveFailed('failed to save user groups');
                }
            }

            return true;
        } catch (\Exception $e) {
            Logger::error('Failed to save tour: ' . $e->getMessage(), 'boarding');
            throw $e;
        }
    }

    /**
     * Delete a tour
     *
     * @param int|string $id The tour ID or database ID
     * @return bool Success status
     * @throws TourException When tour doesn't exist or deletion fails
     */
    public function deleteTour($id): bool
    {
        try {
            $tourId = (int)$id;
            $tour = $this->repository->findById($tourId);

            if (!$tour) {
                throw TourException::notFound($id);
            }

            $success = $this->repository->delete($tourId);

            if (!$success) {
                throw TourException::deleteFailed($tourId);
            }

            return true;
        } catch (\Exception $e) {
            Logger::error('Failed to delete tour: ' . $e->getMessage(), 'boarding');
            throw $e;
        }
    }

    /**
     * Duplicate a tour
     *
     * @param int $tourId The tour database ID to duplicate
     * @return bool|int Returns the new tour ID on success, false on failure
     */
    public function duplicateTour(int $tourId): bool|int
    {
        $transaction = Craft::$app->getDb()->beginTransaction();
        $newTourId = null;

        try {
            $originalTour = $this->repository->findById($tourId);

            if (!$originalTour) {
                throw new \Exception('Tour not found');
            }

            $jsonData = JsonCache::decodeTourData($originalTour);
            if (isset($originalTour['progressPosition']) && !isset($jsonData['progressPosition'])) {
                $jsonData['progressPosition'] = $originalTour['progressPosition'];
            }

            $newTourData = [
                'tourId' => 'tour_' . StringHelper::UUID(),
                'name' => $originalTour['name'] . ' (Copy)',
                'description' => $originalTour['description'],
                'data' => json_encode($jsonData),
                'enabled' => $originalTour['enabled'],
                'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new \DateTime())
            ];

            if (isset($originalTour['siteId'])) {
                $newTourData['siteId'] = $originalTour['siteId'];
            } else {
                $newTourData['siteId'] = Craft::$app->getSites()->getPrimarySite()->id;
            }

            if (isset($originalTour['progressPosition'])) {
                $newTourData['progressPosition'] = $originalTour['progressPosition'];
            }

            $newTourId = $this->repository->save($newTourData);

            if (!$newTourId) {
                throw new \Exception('Failed to save new tour record');
            }

            $userGroups = $this->repository->getUserGroups($tourId);
            if (!empty($userGroups)) {
                if (!$this->saveTourUserGroups($newTourId, $userGroups)) {
                    throw new \Exception('Failed to copy user group assignments');
                }
            }

            $this->duplicateTranslations($tourId, $newTourId);

            JsonCache::clearCache();
            BulkTourLoader::clearCache();

            $transaction->commit();
            return $newTourId;
        } catch (\Exception $e) {
            $transaction->rollBack();

            if ($newTourId !== null) {
                try {
                    $this->repository->delete($newTourId);
                    Logger::info("Cleaned up orphaned tour {$newTourId} after failed duplication", 'boarding');
                } catch (\Exception $cleanupEx) {
                    Logger::error("Failed to cleanup orphaned tour {$newTourId}: " . $cleanupEx->getMessage(), 'boarding');
                }
            }

            JsonCache::clearCache();
            BulkTourLoader::clearCache();

            Logger::error('Error duplicating tour: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Mark a tour as completed for a user
     *
     * @param string|int $tourId The tour ID or database ID
     * @param int|null $userId The user ID (defaults to current user)
     * @return bool Whether the operation succeeded
     * @throws TourException When tour doesn't exist or user is not authenticated
     */
    public function markTourCompleted($tourId, $userId = null): bool
    {
        try {
            if ($userId === null) {
                $user = Craft::$app->getUser()->getIdentity();
                if (!$user) {
                    throw TourException::accessDenied('user not authenticated');
                }
                $userId = $user->id;
            }

            $tour = null;
            if (is_numeric($tourId)) {
                $tour = $this->repository->findById((int)$tourId);
                if (!$tour) {
                    $tour = $this->repository->findByTourId((string)$tourId);
                }
            } else {
                $tour = $this->repository->findByTourId($tourId);
            }

            if (!$tour) {
                throw TourException::notFound($tourId);
            }

            $tourDbId = (int)$tour['id'];
            $success = $this->repository->markCompleted($tourDbId, $userId);

            if (!$success) {
                throw TourException::saveFailed('failed to mark tour as completed');
            }

            return true;
        } catch (\Exception $e) {
            Logger::error('Failed to mark tour as completed: ' . $e->getMessage(), 'boarding');
            throw $e;
        }
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
        return UserGroupProcessor::saveTourUserGroups($tourId, $userGroupIds);
    }

    /**
     * Save user group assignments for multiple tours in a single batch operation
     *
     * @param array $tourUserGroups Array of [tourId => [userGroupIds]]
     * @return bool Success status
     */
    public function batchSaveTourUserGroups(array $tourUserGroups): bool
    {
        return UserGroupProcessor::batchSaveTourUserGroups($tourUserGroups);
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
        return UserGroupProcessor::validateTourUserGroups($tourId, $expectedGroupIds);
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
        try {
            $db = Craft::$app->getDb();
            $primarySite = Craft::$app->getSites()->getPrimarySite();

            if ($siteId == $primarySite->id) {
                $rowsAffected = $db->createCommand()
                    ->update(
                        '{{%boarding_tours}}',
                        ['enabled' => $enabled, 'dateUpdated' => Db::prepareDateForDb(new \DateTime())],
                        ['id' => $tourId]
                    )
                    ->execute();
                return $rowsAffected > 0;
            }

            $translationExists = (new \craft\db\Query())
                ->from('{{%boarding_tours_i18n}}')
                ->where(['tourId' => $tourId, 'siteId' => $siteId])
                ->exists();

            if ($translationExists) {
                $db->createCommand()
                    ->update(
                        '{{%boarding_tours_i18n}}',
                        ['enabled' => $enabled, 'dateUpdated' => Db::prepareDateForDb(new \DateTime())],
                        ['tourId' => $tourId, 'siteId' => $siteId]
                    )
                    ->execute();
                return true;
            } else {
                $mainTour = $this->repository->findById($tourId);
                if (!$mainTour) {
                    return false;
                }

                $db->createCommand()
                    ->insert('{{%boarding_tours_i18n}}', [
                        'tourId' => $tourId,
                        'siteId' => $siteId,
                        'name' => $mainTour['name'],
                        'description' => $mainTour['description'],
                        'data' => $mainTour['data'],
                        'enabled' => $enabled,
                        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                        'uid' => StringHelper::UUID()
                    ])
                    ->execute();
                return true;
            }
        } catch (\Exception $e) {
            Logger::error('Error setting tour enabled status for site: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    // ==========================================
    // TRANSLATION METHODS
    // ==========================================

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
            $currentSite = SiteHelper::getSiteForRequestAuto($this->request);
            $siteId = $currentSite->id;
        }

        // Don't apply translations for tours with language propagation method
        // These tours have the same content across all sites with the same language
        if (isset($tour['propagationMethod']) && $tour['propagationMethod'] === 'language') {
            return $tour;
        }

        $primarySite = Craft::$app->getSites()->getPrimarySite();
        return TranslationProcessor::applyTranslations($tour, $siteId, $primarySite->id);
    }

    /**
     * Process steps data from the request
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
     * Process steps for saving based on tour propagation method
     *
     * @param \zeix\boarding\models\Tour $tour Tour model
     * @param array $steps Steps array from request
     * @return array Processed steps
     */
    public function processStepsForSave($tour, array $steps): array
    {
        $propagationMethod = $tour->propagationMethod;
        $siteId = $tour->siteId;

        Logger::info('processStepsForSave - Tour ID: ' . ($tour->id ?? 'new') . ', propagationMethod: ' . $propagationMethod . ', siteId: ' . $siteId, 'boarding');
        Logger::info('processStepsForSave - Raw steps from form: ' . json_encode($steps), 'boarding');

        if ($propagationMethod === 'all' || $propagationMethod === 'language') {
            // All sites or language: Same content everywhere, strip translations
            $result = $this->normalizeStepsForPropagation($steps, $siteId);
            Logger::info('processStepsForSave - After normalizeStepsForPropagation: ' . json_encode($result), 'boarding');
            return $result;
        } elseif ($propagationMethod === 'none') {
            // Single site only: Process normally, then normalize for single site
            $processedSteps = $this->processStepsData($steps);
            Logger::info('processStepsForSave - After processStepsData: ' . json_encode($processedSteps), 'boarding');
            $result = $this->normalizeStepsForSingleSite($processedSteps, $siteId);
            Logger::info('processStepsForSave - After normalizeStepsForSingleSite: ' . json_encode($result), 'boarding');
            return $result;
        } else {
            // SiteGroup/Custom: Keep translations for site-specific content
            $result = $this->processStepsData($steps);
            Logger::info('processStepsForSave - After processStepsData (site group): ' . json_encode($result), 'boarding');
            return $result;
        }
    }

    /**
     * Save translations for a tour (public wrapper)
     *
     * @param int $tourId Tour ID
     * @param array $steps Steps array with translations
     * @param \craft\models\Site $currentSite Current editing site
     * @param \craft\models\Site $primarySite Primary site
     * @param string $propagationMethod Propagation method (language, siteGroup, custom, etc.)
     * @return bool Success
     */
    public function saveTranslationsForTour(int $tourId, array $steps, $currentSite, $primarySite, string $propagationMethod = 'none'): bool
    {
        $data = [
            'steps' => $steps,
            'propagationMethod' => $propagationMethod,
        ];

        return $this->saveTranslations($tourId, $data, $currentSite, $primarySite);
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
                'isProEdition' => Boarding::getInstance()->is(Boarding::EDITION_PRO),
                'tourCount' => 0,
                'tourLimit' => Boarding::LITE_TOUR_LIMIT,
                'tourLimitReached' => false
            ];
        }

        $tourIds = array_column($tours, 'id');
        $allTranslations = BulkTourLoader::bulkLoad($tourIds, [
            'loadCompletions' => false,
            'loadUserGroups' => false,
            'loadTranslations' => true,
        ])['translations'];

        foreach ($tours as &$tour) {
            $tour['translations'] = $allTranslations[$tour['id']] ?? [];

            // Apply translations based on propagation method
            if (TranslationProcessor::shouldLoadTranslations($tour)) {
                $tour = $this->applyTourTranslations($tour, $site->id);
            }
        }

        $isProEdition = Boarding::getInstance()->is(Boarding::EDITION_PRO);
        $tourCount = count($tours);
        $tourLimitReached = !$isProEdition && $tourCount >= Boarding::LITE_TOUR_LIMIT;

        return [
            'tours' => $tours,
            'isProEdition' => $isProEdition,
            'tourCount' => $tourCount,
            'tourLimit' => Boarding::LITE_TOUR_LIMIT,
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
            'isProEdition' => Boarding::getInstance()->is(Boarding::EDITION_PRO)
        ];
    }

    // ==========================================
    // PRIVATE HELPER METHODS
    // ==========================================

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

    /**
     * Validate tour data before saving
     *
     * @param array $data Tour data to validate
     * @throws TourException if validation fails
     */
    private function validateTourData(array $data): void
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = 'Tour name is required';
        }

        if (empty($data['steps']) || !is_array($data['steps']) || count($data['steps']) === 0) {
            $errors[] = 'Tour must have at least one step';
        }

        if (!empty($data['steps']) && is_array($data['steps'])) {
            foreach ($data['steps'] as $index => $step) {
                if (empty($step['title'])) {
                    $errors[] = "Step " . ($index + 1) . " title is required";
                }
                if (empty($step['text'])) {
                    $errors[] = "Step " . ($index + 1) . " content is required";
                }
            }
        }

        if (!empty($errors)) {
            throw TourException::validationFailed($errors);
        }
    }

    /**
     * Save all translations to the i18n table
     *
     * @param int $tourId Tour ID
     * @param array $data Tour data
     * @param \craft\models\Site $currentSite Current site
     * @param \craft\models\Site $primarySite Primary site
     * @return bool Success
     */
    private function saveTranslations(int $tourId, array $data, $currentSite, $primarySite): bool
    {
        try {
            if (!Craft::$app->getDb()->tableExists('{{%boarding_tours_i18n}}')) {
                Logger::warning('Translations table not available', ['tourId' => $tourId]);
                return false;
            }

            $sites = Craft::$app->getSites()->getAllSites();
            $context = $this->buildTranslationContext($data, $tourId);

            $savedCount = 0;
            foreach ($sites as $site) {
                if ($site->id == $primarySite->id) {
                    continue;
                }

                $translationData = $this->buildSiteTranslationData($site, $currentSite, $data, $context, $tourId);

                if ($translationData !== null) {
                    $this->saveTranslationRecord($tourId, $site->id, $translationData);
                    $savedCount++;
                }
            }

            return true;
        } catch (\Exception $e) {
            Logger::error('Error saving translations: ' . $e->getMessage(), ['tourId' => $tourId]);
            return false;
        }
    }

    /**
     * Build translation context data
     *
     * @param array $data Tour data
     * @param int $tourId Tour ID
     * @return array Translation context
     */
    private function buildTranslationContext(array $data, int $tourId): array
    {
        return [
            'steps' => $data['steps'] ?? [],
            'isTranslatable' => ($data['propagationMethod'] ?? 'none') !== 'none',
            'existingTranslations' => $this->repository->getTranslations($tourId),
            'siteEnabled' => $data['siteEnabled'] ?? [],
            'defaultEnabled' => $data['enabled'] ?? true,
        ];
    }

    /**
     * Build site-specific translation data
     *
     * @param \craft\models\Site $site Site to build data for
     * @param \craft\models\Site $currentSite Current editing site
     * @param array $data Tour data
     * @param array $context Translation context
     * @param int $tourId Tour ID
     * @return array|null Translation data or null if should not save
     */
    private function buildSiteTranslationData($site, $currentSite, array $data, array $context, int $tourId): ?array
    {
        $siteSteps = [];
        $siteName = '';
        $siteDescription = '';
        $siteEnabled = $context['defaultEnabled'];
        $hasSiteSpecificEnabledData = false;

        if (isset($context['siteEnabled'][$site->id])) {
            $siteEnabled = !empty($context['siteEnabled'][$site->id]);
            $hasSiteSpecificEnabledData = true;
        }

        $shouldSave = false;
        $propagationMethod = $data['propagationMethod'] ?? 'none';

        if ($site->id == $currentSite->id) {
            if ($context['isTranslatable']) {
                $siteName = $data['name'] ?? '';
                $siteDescription = $data['description'] ?? '';
                $siteSteps = $this->extractCurrentSiteSteps($context['steps'], $currentSite);
                $shouldSave = true;
            } else {
                if ($hasSiteSpecificEnabledData) {
                    $mainTour = $this->repository->findById($tourId);
                    if ($mainTour) {
                        $siteName = $mainTour['name'];
                        $siteDescription = $mainTour['description'];
                        $siteSteps = [];
                        $shouldSave = true;
                    }
                }
            }
        } else {
            if ($propagationMethod === 'language' && $site->language === $currentSite->language) {
                if ($context['isTranslatable']) {
                    $siteName = $data['name'] ?? '';
                    $siteDescription = $data['description'] ?? '';
                    $siteSteps = $this->extractCurrentSiteSteps($context['steps'], $currentSite);
                    $shouldSave = true;
                }
            } elseif ($propagationMethod === 'siteGroup' && $site->groupId === $currentSite->groupId) {
                if (isset($context['existingTranslations'][$site->id])) {
                    $existingTranslation = $context['existingTranslations'][$site->id];
                    $siteName = $existingTranslation['name'] ?? '';
                    $siteDescription = $existingTranslation['description'] ?? '';
                    $siteEnabled = $existingTranslation['enabled'] ?? true;

                    if ($context['isTranslatable']) {
                        $siteSteps = $this->extractExistingTranslationSteps($existingTranslation, $site->id);
                    }

                    $shouldSave = true;
                } else {
                    if ($context['isTranslatable']) {
                        $mainTour = $this->repository->findById($tourId);
                        $siteName = $mainTour['name'] ?? '';
                        $siteDescription = $mainTour['description'] ?? '';
                        $siteSteps = [];
                        $shouldSave = true;
                    }
                }
            } else {
                if (isset($context['existingTranslations'][$site->id])) {
                    $existingTranslation = $context['existingTranslations'][$site->id];
                    $siteName = $existingTranslation['name'] ?? '';
                    $siteDescription = $existingTranslation['description'] ?? '';
                    $siteEnabled = $existingTranslation['enabled'] ?? true;

                    if ($context['isTranslatable']) {
                        $siteSteps = $this->extractExistingTranslationSteps($existingTranslation, $site->id);
                    } else {
                        $siteSteps = [];
                    }

                    $shouldSave = true;
                }
            }
        }

        if (!$shouldSave) {
            return null;
        }

        return [
            'name' => $siteName,
            'description' => $siteDescription,
            'steps' => $siteSteps,
            'enabled' => $siteEnabled
        ];
    }

    /**
     * Extract steps for current site from step data
     *
     * @param array $steps Steps array
     * @param \craft\models\Site $currentSite Current site
     * @return array Site-specific steps
     */
    private function extractCurrentSiteSteps(array $steps, $currentSite): array
    {
        $siteSteps = [];
        $currentSiteIdInt = (int)$currentSite->id;
        $currentSiteIdStr = (string)$currentSite->id;

        foreach ($steps as $stepIndex => $step) {
            if (isset($step['translations'][$currentSiteIdInt])) {
                $siteSteps[$stepIndex] = $step['translations'][$currentSiteIdInt];
            } elseif (isset($step['translations'][$currentSiteIdStr])) {
                $siteSteps[$stepIndex] = $step['translations'][$currentSiteIdStr];
            } else {
                $siteSteps[$stepIndex] = TranslationProcessor::cleanStepsFromTranslations([$step])[0];
            }
        }

        return $siteSteps;
    }

    /**
     * Extract steps from existing translation data
     *
     * @param array $existingTranslation Existing translation data
     * @param int $siteId Site ID
     * @return array Steps array
     */
    private function extractExistingTranslationSteps(array $existingTranslation, int $siteId): array
    {
        if (empty($existingTranslation['data'])) {
            return [];
        }

        $existingData = JsonCache::decodeTranslationData($existingTranslation, $siteId);
        return $existingData['steps'] ?? [];
    }

    /**
     * Save a single translation record
     *
     * @param int $tourId Tour ID
     * @param int $siteId Site ID
     * @param array $translationData Translation data
     * @return bool Success
     */
    private function saveTranslationRecord(int $tourId, int $siteId, array $translationData): bool
    {
        try {
            $db = Craft::$app->getDb();

            $exists = (new \craft\db\Query())
                ->from('{{%boarding_tours_i18n}}')
                ->where(['tourId' => $tourId, 'siteId' => $siteId])
                ->exists();

            if ($exists) {
                $db->createCommand()
                    ->update('{{%boarding_tours_i18n}}', [
                        'name' => $translationData['name'],
                        'description' => $translationData['description'],
                        'data' => json_encode(['steps' => $translationData['steps']]),
                        'enabled' => $translationData['enabled'],
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime())
                    ], ['tourId' => $tourId, 'siteId' => $siteId])
                    ->execute();
            } else {
                $db->createCommand()
                    ->insert('{{%boarding_tours_i18n}}', [
                        'tourId' => $tourId,
                        'siteId' => $siteId,
                        'name' => $translationData['name'],
                        'description' => $translationData['description'],
                        'data' => json_encode(['steps' => $translationData['steps']]),
                        'enabled' => $translationData['enabled'],
                        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                        'uid' => StringHelper::UUID()
                    ])
                    ->execute();
            }

            return true;
        } catch (\Exception $e) {
            Logger::error('Error saving translation record: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Duplicate translations for a tour
     *
     * @param int $originalTourId Original tour ID
     * @param int $newTourId New tour ID
     * @return void
     */
    private function duplicateTranslations(int $originalTourId, int $newTourId): void
    {
        try {
            $translations = $this->repository->getTranslations($originalTourId);

            foreach ($translations as $siteId => $translation) {
                $this->saveTranslationRecord($newTourId, $siteId, [
                    'name' => ($translation['name'] ?? '') . ' (Copy)',
                    'description' => $translation['description'] ?? '',
                    'steps' => JsonCache::decodeTranslationData($translation, $siteId)['steps'] ?? [],
                    'enabled' => $translation['enabled'] ?? true
                ]);
            }
        } catch (\Exception $e) {
            Logger::error('Error duplicating translations: ' . $e->getMessage(), 'boarding');
        }
    }

    /**
     * Handle non-primary site editing special cases
     *
     * @param array $processedSteps Processed steps data
     * @param \craft\models\Site $currentSite Current site
     * @return array Modified steps data
     */
    private function handleNonPrimarySiteEditing(array $processedSteps, $currentSite): array
    {
        try {
            $tourId = $this->request->getBodyParam('tourId');
            $propagationMethod = $this->request->getBodyParam('propagationMethod', 'none');

            if (!empty($tourId) && $propagationMethod !== 'none') {
                $existingTour = $this->getTourById($tourId);
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

    /**
     * Normalize steps for propagating tours (all, language)
     * Strips translation objects and uses main content for all sites
     *
     * @param array $steps Steps array from request
     * @param int $siteId Current editing site ID
     * @return array Normalized steps without translations
     */
    private function normalizeStepsForPropagation(array $steps, int $siteId): array
    {
        $normalizedSteps = [];

        foreach ($steps as $step) {
            $normalizedStep = [
                'type' => $step['type'] ?? 'default',
                'title' => $step['title'] ?? '',
                'text' => $step['text'] ?? '',
            ];

            if (isset($step['attachTo'])) {
                $normalizedStep['attachTo'] = $step['attachTo'];
            }

            // For language propagation, always use the translation for the current editing site if it exists
            // This ensures we save the content being edited, not the old main field values
            if (isset($step['translations']) && is_array($step['translations'])) {
                $translation = $step['translations'][$siteId] ?? $step['translations'][(string)$siteId] ?? null;

                if ($translation && is_array($translation)) {
                    $normalizedStep['title'] = $translation['title'] ?? $normalizedStep['title'];
                    $normalizedStep['text'] = $translation['text'] ?? $normalizedStep['text'];
                }
            }

            // Handle navigation steps
            if ($normalizedStep['type'] === 'navigation') {
                $normalizedStep['navigationUrl'] = $step['navigationUrl'] ?? '';
                $normalizedStep['navigationButtonText'] = $step['navigationButtonText'] ?? '';

                // Check translation for navigation button text if empty
                if (empty($normalizedStep['navigationButtonText']) && isset($step['translations'])) {
                    $translation = $step['translations'][$siteId] ?? $step['translations'][(string)$siteId] ?? null;
                    if ($translation && isset($translation['navigationButtonText'])) {
                        $normalizedStep['navigationButtonText'] = $translation['navigationButtonText'];
                    }
                }
            }

            $normalizedSteps[] = $normalizedStep;
        }

        return $normalizedSteps;
    }

    /**
     * Normalize steps for single-site tours by extracting content from translations
     * to the main fields and removing the translations object
     *
     * @param array $steps The processed steps
     * @param int $siteId The current site ID
     * @return array Normalized steps
     */
    private function normalizeStepsForSingleSite(array $steps, int $siteId): array
    {
        foreach ($steps as &$step) {
            if (isset($step['translations']) && is_array($step['translations'])) {
                $mainTitleEmpty = empty($step['title']);
                $mainTextEmpty = empty($step['text']);

                // Try to find translation for current site
                $translation = $step['translations'][$siteId] ?? $step['translations'][(string)$siteId] ?? null;

                // If main content is empty and translation exists, use translation
                if ($translation && is_array($translation)) {
                    if ($mainTitleEmpty && !empty($translation['title'])) {
                        $step['title'] = $translation['title'];
                    }
                    if ($mainTextEmpty && !empty($translation['text'])) {
                        $step['text'] = $translation['text'];
                    }

                    // Handle navigation button text for navigation steps
                    if (($step['type'] ?? 'default') === 'navigation') {
                        if (empty($step['navigationButtonText']) && !empty($translation['navigationButtonText'])) {
                            $step['navigationButtonText'] = $translation['navigationButtonText'];
                        }
                    }
                }

                // If main content is still empty, use ANY available translation
                if (empty($step['title']) && empty($step['text'])) {
                    foreach ($step['translations'] as $translationData) {
                        if (!empty($translationData['title']) || !empty($translationData['text'])) {
                            $step['title'] = $translationData['title'] ?? '';
                            $step['text'] = $translationData['text'] ?? '';
                            break;
                        }
                    }
                }

                // Remove translations object for single-site tours
                unset($step['translations']);
            }
        }

        return $steps;
    }
}
