<?php

namespace zeix\boarding\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use zeix\boarding\Boarding;
use zeix\boarding\config\ImportConfig;

/**
 * ImportService handles tour import functionality
 */
class ImportService extends Component
{
    /**
     * Process the tours import
     * 
     * @param array $tours Array of tour data to import
     * @return array Import results with counts and errors
     */
    public function processToursImport(array $tours): array
    {
        $results = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        foreach ($tours as $index => $tourData) {
            try {
                if (empty($tourData['name']) || empty($tourData['tourId'])) {
                    $results['errors'][] = Craft::t('boarding', 'Tour #{index}: Missing required name or tourId', [
                        'index' => $index + 1
                    ]);
                    $results['skipped']++;
                    continue;
                }

                $tourData = array_merge([
                    'description' => '',
                    'enabled' => true,
                    'translatable' => false,
                    'userGroupIds' => [],
                    'steps' => [],
                    'progressPosition' => 'bottom',
                    'completedBy' => []
                ], $tourData);

                $tourData['enabled'] = (bool)($tourData['enabled'] ?? true);
                $tourData['translatable'] = (bool)($tourData['translatable'] ?? false);

                if (!isset($tourData['progressPosition']) || !in_array($tourData['progressPosition'], ImportConfig::VALID_PROGRESS_POSITIONS)) {
                    $tourData['progressPosition'] = 'bottom';
                }

                if (isset($tourData['userGroupIds'])) {
                    if (is_string($tourData['userGroupIds'])) {
                        $tourData['userGroupIds'] = array_filter(array_map('intval', explode(',', $tourData['userGroupIds'])));
                    } elseif (!is_array($tourData['userGroupIds'])) {
                        $tourData['userGroupIds'] = [(int)$tourData['userGroupIds']];
                    }
                }

                if (Boarding::getInstance()->tours->saveTour($tourData)) {
                    if (!empty($tourData['completedBy'])) {
                        $this->importTourCompletions($tourData['tourId'], $tourData['completedBy'], $results, $index);
                    }
                    $results['imported']++;
                } else {
                    $results['errors'][] = Craft::t('boarding', 'Tour #{index}: Failed to save "{name}"', [
                        'index' => $index + 1,
                        'name' => $tourData['name']
                    ]);
                    $results['skipped']++;
                }
            } catch (\Exception $e) {
                $results['errors'][] = Craft::t('boarding', 'Tour #{index}: {error}', [
                    'index' => $index + 1,
                    'error' => $e->getMessage()
                ]);
                $results['skipped']++;
            }
        }

        return $results;
    }

    /**
     * Build a detailed import message from results
     * 
     * @param array $results Import results
     * @return string Formatted message
     */
    public function buildDetailedImportMessage(array $results): string
    {
        $message = Craft::t('boarding', 'Import completed: {imported} imported, {skipped} skipped', [
            'imported' => $results['imported'],
            'skipped' => $results['skipped']
        ]);

        if (!empty($results['errors'])) {
            $message .= '. ' . Craft::t('boarding', 'Errors: {count}', [
                'count' => count($results['errors'])
            ]);
        }

        return $message;
    }

    /**
     * Validate import data structure
     *
     * @param array $data Import data
     * @return array Validation errors (empty if valid)
     */
    public function validateImportData(array $data): array
    {
        $errors = [];

        // Check basic structure
        if (!$data || !isset($data['boardingExport'])) {
            $errors[] = Craft::t('boarding', 'Invalid export file format. This doesn\'t appear to be a valid Boarding plugin export.');
            return $errors;
        }

        $exportData = $data['boardingExport'];

        // Check if tours array exists
        if (!isset($exportData['tours'])) {
            $errors[] = Craft::t('boarding', 'Export file is missing tours data');
            return $errors;
        }

        $tours = $exportData['tours'];

        // Check if tours is an array
        if (!is_array($tours)) {
            $errors[] = Craft::t('boarding', 'Tours data must be an array');
            return $errors;
        }

        // Check if tours array is empty
        if (empty($tours)) {
            $errors[] = Craft::t('boarding', 'No tours found in the export file');
            return $errors;
        }

        // Validate tour count doesn't exceed limit
        if (count($tours) > ImportConfig::MAX_TOURS_PER_IMPORT) {
            $errors[] = Craft::t('boarding', 'Import contains too many tours. Maximum allowed is {max}.', [
                'max' => ImportConfig::MAX_TOURS_PER_IMPORT
            ]);
        }

        // Validate each tour structure
        foreach ($tours as $index => $tour) {
            $tourErrors = $this->validateTourStructure($tour, $index);
            $errors = array_merge($errors, $tourErrors);
        }

        return $errors;
    }

    /**
     * Validate individual tour structure
     *
     * @param mixed $tour Tour data
     * @param int $index Tour index for error messages
     * @return array Validation errors
     */
    private function validateTourStructure($tour, int $index): array
    {
        $errors = [];
        $tourNum = $index + 1;

        // Check if tour is an array
        if (!is_array($tour)) {
            $errors[] = Craft::t('boarding', 'Tour #{num}: Invalid tour data structure', [
                'num' => $tourNum
            ]);
            return $errors;
        }

        // Validate required fields
        if (empty($tour['name']) || !is_string($tour['name'])) {
            $errors[] = Craft::t('boarding', 'Tour #{num}: Missing or invalid name field', [
                'num' => $tourNum
            ]);
        }

        if (empty($tour['tourId']) || !is_string($tour['tourId'])) {
            $errors[] = Craft::t('boarding', 'Tour #{num}: Missing or invalid tourId field', [
                'num' => $tourNum
            ]);
        }

        // Validate steps if present
        if (isset($tour['steps'])) {
            if (!is_array($tour['steps'])) {
                $errors[] = Craft::t('boarding', 'Tour #{num}: Steps must be an array', [
                    'num' => $tourNum
                ]);
            } elseif (count($tour['steps']) > ImportConfig::MAX_STEPS_PER_TOUR) {
                $errors[] = Craft::t('boarding', 'Tour #{num}: Too many steps. Maximum allowed is {max}.', [
                    'num' => $tourNum,
                    'max' => ImportConfig::MAX_STEPS_PER_TOUR
                ]);
            }
        }

        // Validate progressPosition if present
        if (isset($tour['progressPosition']) &&
            !in_array($tour['progressPosition'], ImportConfig::VALID_PROGRESS_POSITIONS)) {
            $errors[] = Craft::t('boarding', 'Tour #{num}: Invalid progressPosition value "{value}"', [
                'num' => $tourNum,
                'value' => $tour['progressPosition']
            ]);
        }

        // Validate boolean fields
        if (isset($tour['enabled']) && !is_bool($tour['enabled']) &&
            !in_array($tour['enabled'], [0, 1, '0', '1', true, false])) {
            $errors[] = Craft::t('boarding', 'Tour #{num}: Invalid enabled value', [
                'num' => $tourNum
            ]);
        }

        if (isset($tour['translatable']) && !is_bool($tour['translatable']) &&
            !in_array($tour['translatable'], [0, 1, '0', '1', true, false])) {
            $errors[] = Craft::t('boarding', 'Tour #{num}: Invalid translatable value', [
                'num' => $tourNum
            ]);
        }

        return $errors;
    }

    /**
     * Import completion data for a tour
     * 
     * @param string $tourId Tour ID
     * @param array $completions Array of completion data
     * @param array &$results Results array to add errors to
     * @param int $tourIndex Tour index for error messages
     * @return void
     */
    private function importTourCompletions(string $tourId, array $completions, array &$results, int $tourIndex): void
    {
        try {
            // Find the tour database ID
            $tour = (new Query())
                ->select(['id'])
                ->from('{{%boarding_tours}}')
                ->where(['tourId' => $tourId])
                ->one();

            if (!$tour) {
                $results['errors'][] = Craft::t('boarding', 'Tour #{index}: Could not find saved tour to import completions', [
                    'index' => $tourIndex + 1
                ]);
                return;
            }

            $tourDbId = (int)$tour['id'];
            $completionCount = 0;
            $errorCount = 0;

            foreach ($completions as $completion) {
                try {
                    if (empty($completion['username'])) {
                        $errorCount++;
                        continue;
                    }

                    $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($completion['username']);
                    if (!$user) {
                        $errorCount++;
                        continue;
                    }

                    $completedAt = $this->parseCompletionDate($completion['completedAt'] ?? null);

                    $exists = (new Query())
                        ->from('{{%boarding_tour_completions}}')
                        ->where(['tourId' => $tourDbId, 'userId' => $user->id])
                        ->exists();

                    if (!$exists) {
                        Craft::$app->getDb()->createCommand()
                            ->insert('{{%boarding_tour_completions}}', [
                                'tourId' => $tourDbId,
                                'userId' => $user->id,
                                'dateCreated' => $completedAt,
                                'dateUpdated' => $completedAt,
                                'uid' => StringHelper::UUID()
                            ])
                            ->execute();

                        $completionCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                }
            }

            if ($errorCount > 0) {
                $results['errors'][] = Craft::t('boarding', 'Tour #{index}: {errorCount} completion(s) could not be imported, {successCount} imported successfully', [
                    'index' => $tourIndex + 1,
                    'errorCount' => $errorCount,
                    'successCount' => $completionCount
                ]);
            }
        } catch (\Exception $e) {
            $results['errors'][] = Craft::t('boarding', 'Tour #{index}: Error importing completions: {error}', [
                'index' => $tourIndex + 1,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Parse completion date from various formats
     * 
     * @param mixed $dateValue Date value from import
     * @return string Formatted date for database
     */
    private function parseCompletionDate($dateValue): string
    {
        if (empty($dateValue)) {
            return Db::prepareDateForDb(new \DateTime());
        }

        try {
            if (is_string($dateValue)) {
                $date = new \DateTime($dateValue);
            } elseif (is_numeric($dateValue)) {
                $date = new \DateTime();
                $date->setTimestamp($dateValue);
            } else {
                $date = new \DateTime();
            }

            return Db::prepareDateForDb($date);
        } catch (\Exception $e) {
            return Db::prepareDateForDb(new \DateTime());
        }
    }
}
