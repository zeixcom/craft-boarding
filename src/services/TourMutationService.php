<?php

namespace zeix\boarding\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use craft\helpers\Db;
use zeix\boarding\repositories\TourRepository;
use zeix\boarding\helpers\UserGroupProcessor;
use zeix\boarding\helpers\TranslationProcessor;
use zeix\boarding\helpers\JsonCache;
use zeix\boarding\helpers\BulkTourLoader;
use zeix\boarding\utils\Logger;
use zeix\boarding\helpers\SiteHelper;
use zeix\boarding\handlers\ErrorHandler;
use zeix\boarding\exceptions\TourSaveException;
use zeix\boarding\exceptions\TourNotFoundException;
use zeix\boarding\exceptions\TourValidationException;
use zeix\boarding\exceptions\TourAccessException;

/**
 * TourMutationService - Focused service for tour data modifications
 * 
 * This service handles all tour creation, updating, deletion, and state changes,
 * providing a clean interface for modifying tour data.
 */
class TourMutationService extends Component
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
     * Save a tour (create or update)
     * 
     * @param array $data Tour data
     * @return bool Whether the save was successful
     * @throws TourSaveException When save operation fails
     * @throws TourValidationException When validation fails
     */
    public function saveTour(array $data): bool
    {
        $currentSite = SiteHelper::getSiteForRequest($this->request, true);

        $result = ErrorHandler::wrap(function () use ($data, $currentSite) {
            $this->validateTourData($data);

            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $isTranslatable = $data['translatable'] ?? false;
            $isCurrentSitePrimary = $currentSite->id === $primarySite->id;
            $tourDbData = [
                'tourId' => $data['tourId'] ?? 'tour_' . StringHelper::UUID(),
                'translatable' => $isTranslatable ? 1 : 0,
                'siteId' => $data['siteId'] ?? $primarySite->id,
                'dateCreated' => isset($data['dateCreated']) ? $data['dateCreated'] : Db::prepareDateForDb(new \DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                'progressPosition' => $data['progressPosition'],
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

            if ($isCurrentSitePrimary || !$isTranslatable) {
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
                throw TourSaveException::databaseFailed('tour_save', [
                    'tourData' => $tourDbData
                ]);
            }

            $hasSiteEnabledData = !empty($data['siteEnabled']) && count(Craft::$app->getSites()->getAllSites()) > 1;
            if ($isTranslatable || $hasSiteEnabledData) {
                if (!$this->saveTranslations($tourId, $data, $currentSite, $primarySite)) {
                    throw TourSaveException::databaseFailed('translation_save', [
                        'tourId' => $tourId,
                        'isTranslatable' => $isTranslatable,
                        'hasSiteEnabledData' => $hasSiteEnabledData
                    ]);
                }
            }

            if (isset($data['userGroupIds'])) {
                if (!$this->saveTourUserGroups($tourId, $data['userGroupIds'])) {
                    throw TourSaveException::databaseFailed('user_groups_save', [
                        'tourId' => $tourId,
                        'userGroupIds' => $data['userGroupIds']
                    ]);
                }
            }

            return true;
        }, [
            'operation' => 'saveTour',
            'tourId' => $data['id'] ?? 'new',
            'siteId' => $currentSite->id ?? 'unknown'
        ]);

        if (is_array($result)) {
            $context = $result['context'] ?? [];

            throw TourSaveException::databaseFailed('saveTour', array_merge([
                'errorDetails' => $result
            ], $context));
        }

        return $result;
    }

    /**
     * Delete a tour
     * 
     * @param int|string $id The tour ID or database ID
     * @return bool Success status
     * @throws TourNotFoundException When tour doesn't exist
     * @throws TourSaveException When deletion fails
     */
    public function deleteTour($id): bool
    {
        $result = ErrorHandler::wrap(function () use ($id) {
            $tourId = (int)$id;

            $tour = $this->repository->findById($tourId);
            if (!$tour) {
                throw TourNotFoundException::forTourId($id, ['operation' => 'delete']);
            }

            $success = $this->repository->delete($tourId);

            if (!$success) {
                throw TourSaveException::databaseFailed('tour_delete', [
                    'tourId' => $tourId
                ]);
            }

            return true;
        }, [
            'operation' => 'deleteTour',
            'tourId' => $id
        ]);

        if (is_array($result)) {
            $context = $result['context'] ?? [];

            throw TourSaveException::databaseFailed('deleteTour', array_merge([
                'errorDetails' => $result
            ], $context));
        }

        return $result;
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
                'translatable' => $originalTour['translatable'] ?? false,
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

            if ($originalTour['translatable'] ?? false) {
                $this->duplicateTranslations($tourId, $newTourId);
            }

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
     * @throws TourNotFoundException When tour doesn't exist
     * @throws TourAccessException When user is not authenticated
     */
    public function markTourCompleted($tourId, $userId = null): bool
    {
        $result = ErrorHandler::wrap(function () use ($tourId, $userId) {
            if ($userId === null) {
                $user = Craft::$app->getUser()->getIdentity();
                if (!$user) {
                    throw TourAccessException::missingPermission('authenticated_user', [
                        'operation' => 'mark_completed'
                    ]);
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
                throw TourNotFoundException::forOperation($tourId, 'mark_completed');
            }

            $tourDbId = (int)$tour['id'];
            $success = $this->repository->markCompleted($tourDbId, $userId);

            if (!$success) {
                throw TourSaveException::databaseFailed('mark_completed', [
                    'tourId' => $tourDbId,
                    'userId' => $userId
                ]);
            }

            return true;
        }, [
            'operation' => 'markTourCompleted',
            'tourId' => $tourId,
            'userId' => $userId
        ]);

        if (is_array($result)) {
            $context = $result['context'] ?? [];

            throw TourSaveException::databaseFailed('markTourCompleted', array_merge([
                'errorDetails' => $result
            ], $context));
        }

        return $result;
    }

    /**
     * Save user group assignments for a tour using optimized batch operations
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


    /**
     * Validate tour data before saving
     * 
     * @param array $data Tour data to validate
     * @throws TourValidationException if validation fails
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
            throw new TourValidationException($errors, ['validatedData' => $data]);
        }
    }

    /**
     * Check if translations table is available
     *
     * @return bool True if table exists
     */
    private function isTranslationsTableAvailable(): bool
    {
        return Craft::$app->getDb()->tableExists('{{%boarding_tours_i18n}}');
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
            'isTranslatable' => !empty($data['translatable']) && $data['translatable'] == 1,
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
            if (!$this->isTranslationsTableAvailable()) {
                return false;
            }

            $sites = Craft::$app->getSites()->getAllSites();
            $context = $this->buildTranslationContext($data, $tourId);

            foreach ($sites as $site) {
                if ($site->id == $primarySite->id) {
                    continue;
                }

                $translationData = $this->buildSiteTranslationData($site, $currentSite, $data, $context, $tourId);

                if ($translationData !== null) {
                    $this->saveTranslationRecord($tourId, $site->id, $translationData);
                }
            }

            return true;
        } catch (\Exception $e) {
            Logger::error('Error saving translations: ' . $e->getMessage(), 'boarding');
            return false;
        }
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
}
