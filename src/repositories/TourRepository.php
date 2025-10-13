<?php

namespace zeix\boarding\repositories;

use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use craft\helpers\Db;
use zeix\boarding\helpers\DatabaseSchemaHelper;
use zeix\boarding\utils\Logger;

/**
 * TourRepository - Consistent data access layer for tour operations
 * 
 * This repository provides a unified interface for all tour-related database operations,
 * standardizing data access patterns and eliminating mixed ActiveRecord/QueryBuilder usage.
 */
class TourRepository
{
    /**
     * Find a tour by its database ID
     * 
     * @param int $id Tour database ID
     * @return array|null Tour data or null if not found
     */
    public function findById(int $id): ?array
    {
        try {
            $query = $this->buildBaseTourQuery()
                ->where(['t.id' => $id]);

            return $query->one();
        } catch (\Exception $e) {
            Logger::error('Error finding tour by ID: ' . $e->getMessage(), 'boarding');
            return null;
        }
    }

    /**
     * Find a tour by its tourId string
     * 
     * @param string $tourId Tour ID string
     * @return array|null Tour data or null if not found
     */
    public function findByTourId(string $tourId): ?array
    {
        try {
            $query = $this->buildBaseTourQuery()
                ->where(['t.tourId' => $tourId]);

            return $query->one();
        } catch (\Exception $e) {
            Logger::error('Error finding tour by tourId: ' . $e->getMessage(), 'boarding');
            return null;
        }
    }

    /**
     * Get all tours for admin interface
     * 
     * @param array $options Query options
     * @return array Tours array
     */
    public function findAll(array $options = []): array
    {
        $defaultOptions = [
            'includeTranslatable' => true,
            'enabledOnly' => false,
            'orderBy' => ['t.dateCreated' => SORT_DESC]
        ];

        $options = array_merge($defaultOptions, $options);

        try {
            $query = $this->buildBaseTourQuery($options);

            if ($options['enabledOnly']) {
                $query->andWhere(['t.enabled' => true]);
            }

            if (!empty($options['orderBy'])) {
                $query->orderBy($options['orderBy']);
            }

            return $query->all();
        } catch (\Exception $e) {
            Logger::error('Error finding all tours: ' . $e->getMessage(), 'boarding');
            return [];
        }
    }

    /**
     * Find tours for a specific user
     * 
     * @param int $userId User ID
     * @param array $userGroupIds User's group IDs
     * @param array $options Query options
     * @return array Tours array
     */
    public function findForUser(int $userId, array $userGroupIds, array $options = []): array
    {
        $defaultOptions = [
            'includeTranslatable' => true,
            'siteId' => null,
            'orderBy' => ['t.dateCreated' => SORT_DESC]
        ];

        $options = array_merge($defaultOptions, $options);

        try {
            $query = $this->buildBaseTourQuery($options);

            $this->applyUserGroupFilter($query, $userGroupIds);

            $this->addCompletionStatus($query, $userId);

            if ($options['siteId']) {
                $this->applySiteEnabledFilter($query, $options['siteId']);
            } else {
                $query->andWhere(['t.enabled' => true]);
            }

            if (!empty($options['orderBy'])) {
                $query->orderBy($options['orderBy']);
            }

            return $query->all();
        } catch (\Exception $e) {
            Logger::error('Error finding tours for user: ' . $e->getMessage(), 'boarding');
            return [];
        }
    }

    /**
     * Save a tour (create or update)
     * 
     * @param array $data Tour data
     * @return int|false Tour ID on success, false on failure
     */
    public function save(array $data): int|false
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $tourId = $data['id'] ?? null;
            unset($data['id']);

            if ($tourId) {
                $updated = $this->updateTour($tourId, $data);
                if (!$updated) {
                    throw new \Exception('Failed to update tour');
                }
                $result = $tourId;
            } else {
                $result = $this->createTour($data);
                if (!$result) {
                    throw new \Exception('Failed to create tour');
                }
            }

            $transaction->commit();
            return $result;
        } catch (\Exception $e) {
            $transaction->rollBack();
            Logger::error('Error saving tour: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Delete a tour and all related data
     * 
     * @param int $id Tour database ID
     * @return bool Success status
     */
    public function delete(int $id): bool
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $this->deleteCompletions($id);
            $this->deleteUserGroups($id);
            $this->deleteTranslations($id);

            $db->createCommand()
                ->delete('{{%boarding_tours}}', ['id' => $id])
                ->execute();

            $transaction->commit();
            return true;
        } catch (\Exception $e) {
            $transaction->rollBack();
            Logger::error('Error deleting tour: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Get completions for a tour
     * 
     * @param int $tourId Tour ID
     * @return array Completions array
     */
    public function getCompletions(int $tourId): array
    {
        try {
            return (new Query())
                ->select([
                    'u.id',
                    'u.firstName',
                    'u.lastName',
                    'u.username',
                    'tc.dateCreated as completedAt'
                ])
                ->from(['tc' => '{{%boarding_tour_completions}}'])
                ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[tc.userId]]')
                ->where(['tc.tourId' => (string)$tourId])
                ->orderBy(['tc.dateCreated' => SORT_DESC])
                ->all();
        } catch (\Exception $e) {
            Logger::error('Error getting tour completions: ' . $e->getMessage(), 'boarding');
            return [];
        }
    }

    /**
     * Get user groups for a tour
     * 
     * @param int $tourId Tour ID
     * @return array User group IDs
     */
    public function getUserGroups(int $tourId): array
    {
        try {
            return (new Query())
                ->select(['userGroupId'])
                ->from('{{%boarding_tours_usergroups}}')
                ->where(['tourId' => $tourId])
                ->column();
        } catch (\Exception $e) {
            Logger::error('Error getting tour user groups: ' . $e->getMessage(), 'boarding');
            return [];
        }
    }

    /**
     * Get translations for a tour
     * 
     * @param int $tourId Tour ID
     * @return array Translations indexed by site ID
     */
    public function getTranslations(int $tourId): array
    {
        try {
            if (!Craft::$app->getDb()->tableExists('{{%boarding_tours_i18n}}')) {
                return [];
            }

            $results = (new Query())
                ->select(['siteId', 'name', 'description', 'data', 'enabled'])
                ->from('{{%boarding_tours_i18n}}')
                ->where(['tourId' => $tourId])
                ->all();

            $translations = [];
            foreach ($results as $result) {
                $translations[$result['siteId']] = [
                    'name' => $result['name'],
                    'description' => $result['description'],
                    'data' => $result['data'],
                    'enabled' => $result['enabled'] !== null ? (bool)$result['enabled'] : true
                ];
            }

            return $translations;
        } catch (\Exception $e) {
            Logger::error('Error getting tour translations: ' . $e->getMessage(), 'boarding');
            return [];
        }
    }

    /**
     * Mark a tour as completed for a user
     * 
     * @param int $tourId Tour database ID
     * @param int $userId User ID
     * @return bool Success status
     */
    public function markCompleted(int $tourId, int $userId): bool
    {
        try {
            $exists = (new Query())
                ->from('{{%boarding_tour_completions}}')
                ->where(['tourId' => (string)$tourId, 'userId' => $userId])
                ->exists();

            if ($exists) {
                return true;
            }

            Craft::$app->getDb()->createCommand()
                ->insert('{{%boarding_tour_completions}}', [
                    'tourId' => (string)$tourId,
                    'userId' => $userId,
                    'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                    'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                    'uid' => StringHelper::UUID()
                ])
                ->execute();

            return true;
        } catch (\Exception $e) {
            Logger::error('Error marking tour completed: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Bulk load completions for multiple tours
     * 
     * @param array $tourIds Tour IDs
     * @return array Completions indexed by tour ID
     */
    public function bulkLoadCompletions(array $tourIds): array
    {
        if (empty($tourIds)) {
            return [];
        }

        try {
            $results = (new Query())
                ->select([
                    'tc.tourId',
                    'u.id',
                    'u.firstName',
                    'u.lastName',
                    'u.username',
                    'tc.dateCreated as completedAt'
                ])
                ->from(['tc' => '{{%boarding_tour_completions}}'])
                ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[tc.userId]]')
                ->where(['tc.tourId' => $tourIds])
                ->orderBy(['tc.dateCreated' => SORT_DESC])
                ->all();

            $completions = [];
            foreach ($results as $result) {
                $tourId = $result['tourId'];
                unset($result['tourId']);

                if (!isset($completions[$tourId])) {
                    $completions[$tourId] = [];
                }
                $completions[$tourId][] = $result;
            }

            foreach ($tourIds as $tourId) {
                if (!isset($completions[$tourId])) {
                    $completions[$tourId] = [];
                }
            }

            return $completions;
        } catch (\Exception $e) {
            Logger::error('Error bulk loading completions: ' . $e->getMessage(), 'boarding');
            return [];
        }
    }

    /**
     * Bulk load user groups for multiple tours
     * 
     * @param array $tourIds Tour IDs
     * @return array User groups indexed by tour ID
     */
    public function bulkLoadUserGroups(array $tourIds): array
    {
        if (empty($tourIds)) {
            return [];
        }

        try {
            $results = (new Query())
                ->select(['tourId', 'userGroupId'])
                ->from('{{%boarding_tours_usergroups}}')
                ->where(['tourId' => $tourIds])
                ->all();

            $userGroups = [];
            foreach ($results as $result) {
                $tourId = $result['tourId'];
                $userGroupId = (int)$result['userGroupId'];

                if (!isset($userGroups[$tourId])) {
                    $userGroups[$tourId] = [];
                }
                $userGroups[$tourId][] = $userGroupId;
            }

            foreach ($tourIds as $tourId) {
                if (!isset($userGroups[$tourId])) {
                    $userGroups[$tourId] = [];
                }
            }

            return $userGroups;
        } catch (\Exception $e) {
            Logger::error('Error bulk loading user groups: ' . $e->getMessage(), 'boarding');
            return [];
        }
    }

    /**
     * Bulk load translations for multiple tours
     * 
     * @param array $tourIds Tour IDs
     * @return array Translations indexed by tour ID, then site ID
     */
    public function bulkLoadTranslations(array $tourIds): array
    {
        if (empty($tourIds)) {
            return [];
        }

        try {
            if (!Craft::$app->getDb()->tableExists('{{%boarding_tours_i18n}}')) {
                $translations = [];
                foreach ($tourIds as $tourId) {
                    $translations[$tourId] = [];
                }
                return $translations;
            }

            $results = (new Query())
                ->select(['tourId', 'siteId', 'name', 'description', 'data', 'enabled'])
                ->from('{{%boarding_tours_i18n}}')
                ->where(['tourId' => $tourIds])
                ->all();

            $translations = [];
            foreach ($results as $result) {
                $tourId = $result['tourId'];
                $siteId = $result['siteId'];

                if (!isset($translations[$tourId])) {
                    $translations[$tourId] = [];
                }

                $translations[$tourId][$siteId] = [
                    'name' => $result['name'],
                    'description' => $result['description'],
                    'data' => $result['data'],
                    'enabled' => $result['enabled'] !== null ? (bool)$result['enabled'] : true
                ];
            }

            foreach ($tourIds as $tourId) {
                if (!isset($translations[$tourId])) {
                    $translations[$tourId] = [];
                }
            }

            return $translations;
        } catch (\Exception $e) {
            Logger::error('Error bulk loading translations: ' . $e->getMessage(), 'boarding');
            return [];
        }
    }

    /**
     * Check if a tour is enabled for a specific site
     * 
     * @param int $tourId Tour ID
     * @param int $siteId Site ID
     * @return bool Whether the tour is enabled
     */
    public function isEnabledForSite(int $tourId, int $siteId): bool
    {
        try {
            $primarySite = Craft::$app->getSites()->getPrimarySite();

            if ($siteId == $primarySite->id) {
                return (new Query())
                    ->from('{{%boarding_tours}}')
                    ->where(['id' => $tourId, 'enabled' => true])
                    ->exists();
            }

            $translationEnabled = (new Query())
                ->select(['enabled'])
                ->from('{{%boarding_tours_i18n}}')
                ->where(['tourId' => $tourId, 'siteId' => $siteId])
                ->scalar();

            if ($translationEnabled !== false) {
                return (bool)$translationEnabled;
            }

            return (new Query())
                ->from('{{%boarding_tours}}')
                ->where(['id' => $tourId, 'enabled' => true])
                ->exists();
        } catch (\Exception $e) {
            Logger::error('Error checking if tour is enabled for site: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Build the base tour query with standard fields
     * 
     * @param array $options Query options
     * @return Query
     */
    private function buildBaseTourQuery(array $options = []): Query
    {
        $defaultOptions = [
            'includeTranslatable' => true,
            'includeUserGroups' => true
        ];

        $options = array_merge($defaultOptions, $options);

        $query = (new Query())
            ->select([
                't.id',
                't.tourId',
                't.name',
                't.description',
                't.data',
                't.enabled',
                't.dateCreated',
                't.dateUpdated',
                't.uid'
            ])
            ->from(['t' => '{{%boarding_tours}}']);

        if ($options['includeTranslatable'] && DatabaseSchemaHelper::hasTranslatableColumn()) {
            $query->addSelect(['t.translatable']);
        }

        if (DatabaseSchemaHelper::hasProgressPositionColumn()) {
            $query->addSelect(['t.progressPosition']);
        }

        if ($options['includeUserGroups']) {
            $query->addSelect([
                '(SELECT GROUP_CONCAT(DISTINCT tug.[[userGroupId]]) FROM {{%boarding_tours_usergroups}} tug WHERE tug.[[tourId]] = t.[[id]]) as userGroupIds'
            ]);
        }

        return $query;
    }

    /**
     * Apply user group filtering to query
     * 
     * @param Query $query Query to modify
     * @param array $userGroupIds User group IDs
     */
    private function applyUserGroupFilter(Query $query, array $userGroupIds): void
    {
        if (!empty($userGroupIds)) {
            $query->andWhere([
                'or',
                [
                    'exists',
                    (new Query())
                        ->from(['tug1' => '{{%boarding_tours_usergroups}}'])
                        ->where('[[tug1.tourId]] = [[t.id]]')
                        ->andWhere(['tug1.userGroupId' => $userGroupIds])
                ],
                [
                    'not exists',
                    (new Query())
                        ->from(['tug2' => '{{%boarding_tours_usergroups}}'])
                        ->where('[[tug2.tourId]] = [[t.id]]')
                ]
            ]);
        }
    }

    /**
     * Add completion status to query (shows all tours but includes completion info)
     * 
     * @param Query $query Query to modify
     * @param int $userId User ID
     */
    private function addCompletionStatus(Query $query, int $userId): void
    {
        $query->leftJoin(
            ['tc' => '{{%boarding_tour_completions}}'],
            'CAST([[t.id]] AS CHAR) = [[tc.tourId]] AND [[tc.userId]] = :userId',
            [':userId' => $userId]
        )
            ->addSelect(['CASE WHEN [[tc.id]] IS NOT NULL THEN 1 ELSE 0 END AS completed']);
    }

    /**
     * Apply site-specific enabled filtering to query
     * 
     * @param Query $query Query to modify
     * @param int $siteId Site ID
     */
    private function applySiteEnabledFilter(Query $query, int $siteId): void
    {
        try {
            $primarySite = Craft::$app->getSites()->getPrimarySite();

            if ($siteId == $primarySite->id) {
                $query->andWhere(['t.enabled' => true]);
            } else {
                $query->leftJoin(
                    ['ti18n' => '{{%boarding_tours_i18n}}'],
                    '[[t.id]] = [[ti18n.tourId]] AND [[ti18n.siteId]] = :siteId',
                    [':siteId' => $siteId]
                );

                $query->andWhere([
                    'or',
                    ['and', ['not', ['ti18n.id' => null]], ['ti18n.enabled' => true]],
                    ['and', ['ti18n.id' => null], ['t.enabled' => true]]
                ]);
            }
        } catch (\Exception) {
            $query->andWhere(['t.enabled' => true]);
        }
    }

    /**
     * Create a new tour record
     * 
     * @param array $data Tour data
     * @return int|false Tour ID on success, false on failure
     */
    private function createTour(array $data): int|false
    {
        try {
            $now = Db::prepareDateForDb(new \DateTime());

            $tourData = [
                'tourId' => $data['tourId'] ?? 'tour_' . StringHelper::UUID(),
                'name' => $data['name'] ?? '',
                'description' => $data['description'] ?? '',
                'data' => $data['data'] ?? '{}',
                'enabled' => $data['enabled'] ?? true,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID()
            ];

            if (isset($data['translatable']) && DatabaseSchemaHelper::hasTranslatableColumn()) {
                $tourData['translatable'] = $data['translatable'];
            }

            if (isset($data['progressPosition']) && DatabaseSchemaHelper::hasProgressPositionColumn()) {
                $tourData['progressPosition'] = $data['progressPosition'];
            }

            if (isset($data['siteId'])) {
                $tourData['siteId'] = $data['siteId'];
            }

            Craft::$app->getDb()->createCommand()
                ->insert('{{%boarding_tours}}', $tourData)
                ->execute();

            return (int)Craft::$app->getDb()->getLastInsertID();
        } catch (\Exception $e) {
            Logger::error('Error creating tour: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Update an existing tour record
     * 
     * @param int $tourId Tour database ID
     * @param array $data Tour data
     * @return bool Success status
     */
    private function updateTour(int $tourId, array $data): bool
    {
        try {
            $updateData = [
                'dateUpdated' => Db::prepareDateForDb(new \DateTime())
            ];

            $allowedFields = ['name', 'description', 'data', 'enabled', 'translatable', 'progressPosition', 'siteId'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    if ($field === 'translatable' && !DatabaseSchemaHelper::hasTranslatableColumn()) {
                        continue;
                    }
                    if ($field === 'progressPosition' && !DatabaseSchemaHelper::hasProgressPositionColumn()) {
                        continue;
                    }

                    $updateData[$field] = $data[$field];
                }
            }

            $rowsAffected = Craft::$app->getDb()->createCommand()
                ->update('{{%boarding_tours}}', $updateData, ['id' => $tourId])
                ->execute();

            return $rowsAffected > 0;
        } catch (\Exception $e) {
            Logger::error('Error updating tour: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Delete completions for a tour
     * 
     * @param int $tourId Tour ID
     */
    private function deleteCompletions(int $tourId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%boarding_tour_completions}}', ['tourId' => (string)$tourId])
            ->execute();
    }

    /**
     * Delete user groups for a tour
     * 
     * @param int $tourId Tour ID
     */
    private function deleteUserGroups(int $tourId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%boarding_tours_usergroups}}', ['tourId' => $tourId])
            ->execute();
    }

    /**
     * Delete translations for a tour
     * 
     * @param int $tourId Tour ID
     */
    private function deleteTranslations(int $tourId): void
    {
        try {
            if (Craft::$app->getDb()->tableExists('{{%boarding_tours_i18n}}')) {
                Craft::$app->getDb()->createCommand()
                    ->delete('{{%boarding_tours_i18n}}', ['tourId' => $tourId])
                    ->execute();
            }
        } catch (\Exception $e) {
            Logger::error('Error deleting translations: ' . $e->getMessage(), 'boarding');
        }
    }
}
