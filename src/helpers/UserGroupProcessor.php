<?php

namespace zeix\boarding\helpers;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use zeix\boarding\records\TourUserGroupRecord;
use zeix\boarding\utils\Logger;

/**
 * UserGroupProcessor - Optimized user group management for tours
 *
 * This class provides efficient batch operations for managing tour user group
 * assignments, eliminating performance bottlenecks from individual saves.
 */
class UserGroupProcessor
{
    /**
     * Save user group assignments for a tour using optimized batch operations
     *
     * @param int $tourId Tour database ID
     * @param array $userGroupIds Array of user group IDs (mixed format support)
     * @return bool Success status
     */
    public static function saveTourUserGroups(int $tourId, array $userGroupIds = []): bool
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $cleanUserGroupIds = self::processUserGroupIds($userGroupIds);

            self::deleteExistingAssignments($tourId);

            if (!empty($cleanUserGroupIds)) {
                self::batchInsertAssignments($tourId, $cleanUserGroupIds);
            }

            $transaction->commit();
            return true;
        } catch (\Exception $e) {
            $transaction->rollBack();
            Logger::error('Failed to save tour user groups: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Save user groups for multiple tours in a single batch operation
     *
     * @param array $tourUserGroups Array of [tourId => [userGroupIds]]
     * @return bool Success status
     */
    public static function batchSaveTourUserGroups(array $tourUserGroups): bool
    {
        if (empty($tourUserGroups)) {
            return true;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $tourIds = array_keys($tourUserGroups);

            TourUserGroupRecord::deleteAll(['tourId' => $tourIds]);

            $allRecords = [];
            foreach ($tourUserGroups as $tourId => $userGroupIds) {
                $cleanUserGroupIds = self::processUserGroupIds($userGroupIds);

                foreach ($cleanUserGroupIds as $groupId) {
                    $allRecords[] = [
                        'tourId' => $tourId,
                        'userGroupId' => $groupId,
                        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                        'uid' => StringHelper::UUID(),
                    ];
                }
            }

            if (!empty($allRecords)) {
                $db->createCommand()->batchInsert(
                    TourUserGroupRecord::tableName(),
                    ['tourId', 'userGroupId', 'dateCreated', 'dateUpdated', 'uid'],
                    array_map(function($record) {
                        return [
                            $record['tourId'],
                            $record['userGroupId'],
                            $record['dateCreated'],
                            $record['dateUpdated'],
                            $record['uid'],
                        ];
                    }, $allRecords)
                )->execute();
            }

            $transaction->commit();
            return true;
        } catch (\Exception $e) {
            $transaction->rollBack();
            Logger::error('Failed to batch save tour user groups: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }

    /**
     * Process and clean user group IDs from various input formats
     *
     * @param array $userGroupIds Raw user group IDs (mixed format)
     * @return array Clean integer array of user group IDs
     */
    public static function processUserGroupIds(array $userGroupIds): array
    {
        if (empty($userGroupIds)) {
            return [];
        }

        $cleanUserGroupIds = [];

        $flattenedIds = self::flattenUserGroupIds($userGroupIds);

        foreach ($flattenedIds as $item) {
            $cleanId = self::normalizeUserGroupId($item);
            if ($cleanId !== null) {
                $cleanUserGroupIds[] = $cleanId;
            }
        }

        return array_unique($cleanUserGroupIds);
    }

    /**
     * Delete existing user group assignments for a tour
     *
     * @param int $tourId Tour ID
     * @return void
     */
    private static function deleteExistingAssignments(int $tourId): void
    {
        try {
            TourUserGroupRecord::deleteAll(['tourId' => $tourId]);
        } catch (\Exception $e) {
            Logger::error('Failed to delete existing user group assignments: ' . $e->getMessage(), 'boarding');
            throw $e;
        }
    }

    /**
     * Batch insert user group assignments using raw SQL for optimal performance
     *
     * @param int $tourId Tour ID
     * @param array $cleanUserGroupIds Clean user group IDs
     * @return void
     */
    private static function batchInsertAssignments(int $tourId, array $cleanUserGroupIds): void
    {
        $db = Craft::$app->getDb();
        $tableName = TourUserGroupRecord::tableName();

        $rows = [];
        $now = Db::prepareDateForDb(new \DateTime());

        foreach ($cleanUserGroupIds as $groupId) {
            $rows[] = [
                $tourId,           // tourId
                $groupId,          // userGroupId
                $now,              // dateCreated
                $now,              // dateUpdated
                StringHelper::UUID(), // uid
            ];
        }

        $db->createCommand()->batchInsert(
            $tableName,
            ['tourId', 'userGroupId', 'dateCreated', 'dateUpdated', 'uid'],
            $rows
        )->execute();
    }

    /**
     * Flatten nested user group ID arrays
     *
     * @param array $userGroupIds Potentially nested array
     * @return array Flattened array
     */
    private static function flattenUserGroupIds(array $userGroupIds): array
    {
        $flattened = [];

        foreach ($userGroupIds as $item) {
            if (is_array($item)) {
                $flattened = array_merge($flattened, self::flattenUserGroupIds($item));
            } else {
                $flattened[] = $item;
            }
        }

        return $flattened;
    }

    /**
     * Normalize a single user group ID to integer or null
     *
     * @param mixed $item Raw user group ID
     * @return int|null Normalized ID or null if invalid
     */
    private static function normalizeUserGroupId($item): ?int
    {
        if ($item === '' || $item === null) {
            return null;
        }

        if (is_numeric($item)) {
            $intValue = (int)$item;
            return $intValue > 0 ? $intValue : null;
        }

        if (is_string($item)) {
            $trimmed = trim($item);
            if (is_numeric($trimmed)) {
                $intValue = (int)$trimmed;
                return $intValue > 0 ? $intValue : null;
            }
        }

        return null;
    }

    /**
     * Validate user group assignments for a tour
     *
     * @param int $tourId Tour ID
     * @param array $expectedGroupIds Expected group IDs
     * @return bool Whether assignments match expectations
     */
    public static function validateTourUserGroups(int $tourId, array $expectedGroupIds = []): bool
    {
        try {
            $actualGroupIds = TourUserGroupRecord::find()
                ->select(['userGroupId'])
                ->where(['tourId' => $tourId])
                ->column();

            $actualGroupIds = array_map('intval', $actualGroupIds);
            $expectedGroupIds = array_map('intval', $expectedGroupIds);

            sort($actualGroupIds);
            sort($expectedGroupIds);

            return $actualGroupIds === $expectedGroupIds;
        } catch (\Exception $e) {
            Logger::error('Failed to validate tour user groups: ' . $e->getMessage(), 'boarding');
            return false;
        }
    }
}
