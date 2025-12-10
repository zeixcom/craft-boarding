<?php

namespace zeix\boarding\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use zeix\boarding\Boarding;
use zeix\boarding\config\ImportConfig;
use zeix\boarding\repositories\TourRepository;
use zeix\boarding\models\Tour;

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

                // Check if tour with this tourId already exists
                $tourRepository = new TourRepository();
                $existingTour = $tourRepository->findByTourId($tourData['tourId']);

                if ($existingTour) {
                    $tourData['id'] = $existingTour['id'];
                }

                $tourData = array_merge([
                    'description' => '',
                    'enabled' => true,
                    'propagationMethod' => 'none',
                    'userGroupIds' => [],
                    'steps' => [],
                    'progressPosition' => 'bottom',
                    'autoplay' => false,
                    'completedBy' => []
                ], $tourData);

                $tourData['enabled'] = (bool)($tourData['enabled'] ?? true);
                $tourData['propagationMethod'] = $tourData['propagationMethod'] ?? 'none';
                $tourData['autoplay'] = (bool)($tourData['autoplay'] ?? false);

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

                // Save tour as a proper Craft element
                try {
                    if ($existingTour) {
                        $tour = Tour::find()->id($existingTour['id'])->status(null)->one();
                        if (!$tour) {
                            throw new \Exception('Could not load existing tour');
                        }
                    } else {
                        $tour = new Tour();
                    }

                    // Map data to tour element properties
                    $tour->title = $tourData['name'];
                    $tour->tourId = $tourData['tourId'];
                    $tour->description = $tourData['description'];
                    $tour->enabled = $tourData['enabled'];
                    $tour->propagationMethod = $tourData['propagationMethod'];
                    $tour->progressPosition = $tourData['progressPosition'];
                    $tour->autoplay = $tourData['autoplay'];
                    $tour->userGroupIds = $tourData['userGroupIds'];
                    // Tour model's data property expects a JSON string with steps
                    $tour->data = json_encode(['steps' => $tourData['steps']]);

                    // Save via Craft's element system
                    if (Craft::$app->getElements()->saveElement($tour)) {
                        if (!empty($tourData['completedBy'])) {
                            $this->importTourCompletions($tourData['tourId'], $tourData['completedBy'], $results, $index);
                        }

                        if ($existingTour) {
                            $results['updated']++;
                        } else {
                            $results['imported']++;
                        }
                    } else {
                        $errors = $tour->errors;
                        $errorMsg = !empty($errors) ? json_encode($errors) : 'Unknown error';
                        $results['errors'][] = Craft::t('boarding', 'Tour #{index}: Failed to save "{name}": {error}', [
                            'index' => $index + 1,
                            'name' => $tourData['name'],
                            'error' => $errorMsg
                        ]);
                        $results['skipped']++;
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = Craft::t('boarding', 'Tour #{index}: Exception saving "{name}": {error}', [
                        'index' => $index + 1,
                        'name' => $tourData['name'],
                        'error' => $e->getMessage()
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
        $parts = [];

        if ($results['imported'] > 0) {
            $parts[] = Craft::t('boarding', '{count} imported', ['count' => $results['imported']]);
        }

        if ($results['updated'] > 0) {
            $parts[] = Craft::t('boarding', '{count} updated', ['count' => $results['updated']]);
        }

        if ($results['skipped'] > 0) {
            $parts[] = Craft::t('boarding', '{count} skipped', ['count' => $results['skipped']]);
        }

        $message = Craft::t('boarding', 'Import completed: {summary}', [
            'summary' => implode(', ', $parts) ?: '0 tours processed'
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
        if (
            isset($tour['progressPosition']) &&
            !in_array($tour['progressPosition'], ImportConfig::VALID_PROGRESS_POSITIONS)
        ) {
            $errors[] = Craft::t('boarding', 'Tour #{num}: Invalid progressPosition value "{value}"', [
                'num' => $tourNum,
                'value' => $tour['progressPosition']
            ]);
        }

        // Validate boolean fields
        if (
            isset($tour['enabled']) && !is_bool($tour['enabled']) &&
            !in_array($tour['enabled'], [0, 1, '0', '1', true, false])
        ) {
            $errors[] = Craft::t('boarding', 'Tour #{num}: Invalid enabled value', [
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

    /**
     * Parse CSV file and convert to tour array format
     *
     * @param string $filePath Path to CSV file
     * @return array Tours array
     * @throws \Exception If CSV parsing fails
     */
    public function parseCsvFile(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \Exception(Craft::t('boarding', 'Could not open CSV file'));
        }

        $tours = [];
        $headers = [];
        $rowNumber = 0;

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // First row is headers
                if ($rowNumber === 1) {
                    $headers = array_map('trim', $row);
                    continue;
                }

                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Create associative array from headers and row data
                $tourData = array_combine($headers, $row);
                if ($tourData === false) {
                    throw new \Exception(Craft::t('boarding', 'CSV row {row} has mismatched column count', [
                        'row' => $rowNumber
                    ]));
                }

                // Parse and transform CSV data to expected format
                $tour = $this->transformCsvRowToTour($tourData);
                if ($tour) {
                    $tours[] = $tour;
                }
            }
        } finally {
            fclose($handle);
        }

        return $tours;
    }

    /**
     * Transform a CSV row into a tour data array
     *
     * @param array $row CSV row data
     * @return array|null Tour data or null if invalid
     */
    private function transformCsvRowToTour(array $row): ?array
    {
        // Fields to ignore (Craft element system fields that shouldn't be imported)
        $systemFields = [
            'id',
            'canonicalid',
            'fieldlayoutid',
            'uid',
            'archived',
            'datelastmerged',
            'datecreated',
            'dateupdated',
            'sitesettingsid',
            'siteid',
            'slug',
            'uri',
            'content',
            'enabledforsite'
        ];

        // Map CSV column names to expected tour fields (case-insensitive)
        $columnMap = [
            'title' => 'name',
            'name' => 'name',
            'tourid' => 'tourId',
            'tour id' => 'tourId',
            'description' => 'description',
            'enabled' => 'enabled',
            'propagationmethod' => 'propagationMethod',
            'propagation method' => 'propagationMethod',
            'progressposition' => 'progressPosition',
            'progress position' => 'progressPosition',
            'autoplay' => 'autoplay',
            'usergroupids' => 'userGroupIds',
            'user group ids' => 'userGroupIds',
            'usergroups' => 'userGroupIds',
            'steps' => 'steps',
            'data' => 'data',  // Craft element export uses 'data' column
        ];

        $tour = [];

        // Map columns to tour fields, skipping system fields
        foreach ($row as $key => $value) {
            $normalizedKey = strtolower(trim(str_replace([' ', '-', '_', "\xEF\xBB\xBF"], '', $key))); // Also remove BOM

            // Skip system fields
            if (in_array($normalizedKey, $systemFields)) {
                continue;
            }

            $mappedKey = $columnMap[$normalizedKey] ?? null;

            if ($mappedKey) {
                $tour[$mappedKey] = trim($value);
            }
        }

        // Skip if missing required fields
        if (empty($tour['name'])) {
            return null;
        }

        // Generate tourId if not provided
        if (empty($tour['tourId'])) {
            $tour['tourId'] = 'tour_' . StringHelper::UUID();
        }

        // Parse boolean fields
        if (isset($tour['enabled'])) {
            $tour['enabled'] = $this->parseBooleanValue($tour['enabled']);
        }

        if (isset($tour['autoplay'])) {
            $tour['autoplay'] = $this->parseBooleanValue($tour['autoplay']);
        }

        // Parse data field if present (Craft element export format)
        if (isset($tour['data']) && !empty($tour['data'])) {
            $decoded = json_decode($tour['data'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['steps'])) {
                $tour['steps'] = $decoded['steps'];
            } else {
                $tour['steps'] = [];
            }
            unset($tour['data']); // Remove the data field after parsing
        }

        // Parse steps JSON if present (direct steps column)
        if (isset($tour['steps']) && is_string($tour['steps']) && !empty($tour['steps'])) {
            $decoded = json_decode($tour['steps'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $tour['steps'] = $decoded;
            } else {
                $tour['steps'] = [];
            }
        }

        // Parse userGroupIds
        if (isset($tour['userGroupIds']) && !empty($tour['userGroupIds'])) {
            if (is_string($tour['userGroupIds'])) {
                $tour['userGroupIds'] = array_filter(array_map('intval', explode(',', $tour['userGroupIds'])));
            }
        }

        return $tour;
    }

    /**
     * Parse a boolean value from various formats
     *
     * @param mixed $value Value to parse
     * @return bool Boolean value
     */
    private function parseBooleanValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return in_array($lower, ['1', 'true', 'yes', 'on', 'enabled']);
        }

        return (bool)$value;
    }

    /**
     * Parse XML file and convert to tour array format
     *
     * @param string $filePath Path to XML file
     * @return array Tours array
     * @throws \Exception If XML parsing fails
     */
    public function parseXmlFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception(Craft::t('boarding', 'XML file not found'));
        }

        // Read XML content
        $xmlContent = file_get_contents($filePath);
        if ($xmlContent === false) {
            throw new \Exception(Craft::t('boarding', 'Could not read XML file'));
        }

        // Suppress XML parsing errors and handle them manually
        libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($xmlContent);

            if ($xml === false) {
                $errors = libxml_get_errors();
                $errorMsg = !empty($errors) ? $errors[0]->message : 'Unknown XML parsing error';
                libxml_clear_errors();
                throw new \Exception(Craft::t('boarding', 'Invalid XML format: {error}', [
                    'error' => trim($errorMsg)
                ]));
            }

            $tours = [];

            // Check if XML has the expected Craft element export structure
            // Craft exports elements with a root element and child elements
            foreach ($xml->children() as $element) {
                $tour = $this->transformXmlElementToTour($element);
                if ($tour) {
                    $tours[] = $tour;
                }
            }

            libxml_clear_errors();

            return $tours;
        } catch (\Exception $e) {
            libxml_clear_errors();
            throw $e;
        }
    }

    /**
     * Transform an XML element into a tour data array
     *
     * @param \SimpleXMLElement $element XML element
     * @return array|null Tour data or null if invalid
     */
    private function transformXmlElementToTour(\SimpleXMLElement $element): ?array
    {
        $tour = [];

        // System fields to ignore (same as CSV)
        $systemFields = [
            'id',
            'canonicalId',
            'fieldLayoutId',
            'uid',
            'archived',
            'dateLastMerged',
            'dateCreated',
            'dateUpdated',
            'siteSettingsId',
            'siteId',
            'slug',
            'uri',
            'content',
            'enabledForSite'
        ];

        // Map XML elements/attributes to tour fields
        $fieldMap = [
            'title' => 'name',
            'name' => 'name',
            'tourId' => 'tourId',
            'description' => 'description',
            'enabled' => 'enabled',
            'propagationMethod' => 'propagationMethod',
            'progressPosition' => 'progressPosition',
            'autoplay' => 'autoplay',
            'userGroupIds' => 'userGroupIds',
            'data' => 'data',
        ];

        // Process each child element
        foreach ($element->children() as $child) {
            $fieldName = $child->getName();

            // Skip system fields
            if (in_array($fieldName, $systemFields)) {
                continue;
            }

            $mappedName = $fieldMap[$fieldName] ?? null;

            if ($mappedName) {
                $value = (string)$child;
                $tour[$mappedName] = $value;
            }
        }

        // Also check for attributes (some exports use attributes)
        foreach ($element->attributes() as $attrName => $attrValue) {
            if (in_array($attrName, $systemFields)) {
                continue;
            }

            $mappedName = $fieldMap[$attrName] ?? null;

            if ($mappedName && !isset($tour[$mappedName])) {
                $tour[$mappedName] = (string)$attrValue;
            }
        }

        // Skip if missing required name field
        if (empty($tour['name'])) {
            return null;
        }

        // Generate tourId if not provided
        if (empty($tour['tourId'])) {
            $tour['tourId'] = 'tour_' . StringHelper::UUID();
        }

        // Parse boolean fields
        if (isset($tour['enabled'])) {
            $tour['enabled'] = $this->parseBooleanValue($tour['enabled']);
        }

        if (isset($tour['autoplay'])) {
            $tour['autoplay'] = $this->parseBooleanValue($tour['autoplay']);
        }

        // Parse data field if present (contains steps JSON)
        if (isset($tour['data']) && !empty($tour['data'])) {
            $decoded = json_decode($tour['data'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['steps'])) {
                $tour['steps'] = $decoded['steps'];
            } else {
                $tour['steps'] = [];
            }
            unset($tour['data']);
        }

        // Parse userGroupIds
        if (isset($tour['userGroupIds']) && !empty($tour['userGroupIds'])) {
            if (is_string($tour['userGroupIds'])) {
                $tour['userGroupIds'] = array_filter(array_map('intval', explode(',', $tour['userGroupIds'])));
            }
        }

        return $tour;
    }
}
