<?php

namespace zeix\boarding\controllers\traits;

use Craft;
use yii\web\ForbiddenHttpException;
use zeix\boarding\Boarding;

/**
 * RequiresProEdition Trait
 *
 * Provides a centralized way to check and enforce Pro Edition requirements
 * across controllers, eliminating code duplication.
 */
trait RequiresProEdition
{
    /**
     * Require Pro Edition for the current action
     *
     * @param string $featureName The name of the feature requiring Pro Edition
     * @throws ForbiddenHttpException if not Pro Edition
     */
    protected function requireProEdition(string $featureName = 'This feature'): void
    {
        if (!Boarding::getInstance()->is(Boarding::EDITION_PRO)) {
            throw new ForbiddenHttpException(
                Craft::t('boarding', '{feature} requires Boarding Pro.', [
                    'feature' => $featureName,
                ])
            );
        }
    }

    /**
     * Check if Pro edition is active (non-throwing version)
     *
     * @return bool True if Pro edition is active
     */
    protected function isProEdition(): bool
    {
        return Boarding::getInstance()->is(Boarding::EDITION_PRO);
    }

    /**
     * Get edition-specific limits and status
     *
     * @return array Edition information including limits and status
     */
    protected function getEditionInfo(): array
    {
        $isProEdition = $this->isProEdition();

        return [
            'isProEdition' => $isProEdition,
            'editionName' => $isProEdition ? 'Pro' : 'Lite',
            'tourLimit' => $isProEdition ? null : Boarding::LITE_TOUR_LIMIT,
            'hasLimits' => !$isProEdition,
        ];
    }
}
