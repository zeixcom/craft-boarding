<?php

namespace zeix\boarding\services;

use Craft;
use zeix\boarding\helpers\SiteHelper;

class ExportService extends \craft\base\Component
{
    /**
     * Export tours to JSON format
     *
     * @param array $tours Array of tour data to export
     * @return array Export data structure
     */
    public function exportTours(array $tours): array
    {
        $currentSite = SiteHelper::getSiteForRequest(Craft::$app->getRequest(), true);

        // Clean up tour data for export (remove IDs, timestamps, etc.)
        $cleanedTours = [];
        foreach ($tours as $tour) {
            $cleanedTour = [
                'name' => $tour['name'],
                'tourId' => $tour['tourId'],
                'description' => $tour['description'] ?? '',
                'enabled' => $tour['enabled'] ?? true,
                'translatable' => $tour['translatable'] ?? false,
                'userGroupIds' => $tour['userGroups'] ?? $tour['userGroupIds'] ?? [],
                'steps' => $tour['steps'] ?? [],
                'progressPosition' => $tour['progressPosition'] ?? 'bottom',
                'completedBy' => $tour['completedBy'] ?? [],
            ];

            $cleanedTours[] = $cleanedTour;
        }

        return [
            'boardingExport' => [
                'version' => '1.0',
                'exportDate' => date('c'),
                'site' => [
                    'id' => $currentSite->id,
                    'handle' => $currentSite->handle,
                    'name' => $currentSite->name
                ],
                'tours' => $cleanedTours
            ]
        ];
    }

    /**
     * Generate filename for tour export
     *
     * @param array $tour Tour data
     * @return string Generated filename
     */
    public function generateTourFilename(array $tour): string
    {
        return $tour['name'] . '-' . $tour['tourId'] . '-' . date('Y-m-d-H-i-s') . '.json';
    }

    /**
     * Generate filename for all tours export
     *
     * @return string Generated filename
     */
    public function generateAllToursFilename(): string
    {
        return 'all-tours-' . date('Y-m-d-H-i-s') . '.json';
    }
} 