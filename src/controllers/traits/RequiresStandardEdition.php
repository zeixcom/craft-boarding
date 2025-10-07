<?php

namespace zeix\boarding\controllers\traits;

use Craft;
use zeix\boarding\Boarding;
use yii\web\ForbiddenHttpException;

/**
 * RequiresStandardEdition Trait
 *
 * Provides a centralized way to check and enforce Standard Edition requirements
 * across controllers, eliminating code duplication.
 */
trait RequiresStandardEdition
{
    /**
     * Require Standard Edition for the current action
     *
     * @param string $featureName The name of the feature requiring Standard Edition
     * @throws ForbiddenHttpException if not Standard Edition
     */
    protected function requireStandardEdition(string $featureName = 'This feature'): void
    {
        if (!Boarding::getInstance()->is(Boarding::EDITION_STANDARD)) {
            throw new ForbiddenHttpException(
                Craft::t('boarding', '{feature} requires Boarding Standard.', [
                    'feature' => $featureName
                ])
            );
        }
    }

    /**
     * Check if Standard edition is active (non-throwing version)
     *
     * @return bool True if Standard edition is active
     */
    protected function isStandardEdition(): bool
    {
        return Boarding::getInstance()->is(Boarding::EDITION_STANDARD);
    }

    /**
     * Get edition-specific limits and status
     *
     * @return array Edition information including limits and status
     */
    protected function getEditionInfo(): array
    {
        $isStandardEdition = $this->isStandardEdition();

        return [
            'isStandardEdition' => $isStandardEdition,
            'editionName' => $isStandardEdition ? 'Standard' : 'Lite',
            'tourLimit' => $isStandardEdition ? null : Boarding::LITE_TOUR_LIMIT,
            'hasLimits' => !$isStandardEdition,
        ];
    }
}
