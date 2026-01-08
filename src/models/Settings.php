<?php

namespace zeix\boarding\models;

use craft\base\Model;

/**
 * Settings model for the Boarding plugin
 */
class Settings extends Model
{
    /**
     * @var string|null The position of the tours button ('header', 'sidebar', 'none')
     */
    public ?string $buttonPosition = 'header';


    /**
     * @var string The label for the tours button
     */
    public ?string $buttonLabel = 'Available Tours';

    /**
     * @var string Default text for the 'Next' button in tours (used as fallback)
     */
    public ?string $nextButtonText = 'Next';

    /**
     * @var string Default text for the 'Done' button in tours (used as fallback)
     */
    public ?string $doneButtonText = 'Done';

    /**
     * @var string Default text for the 'Back' button in tours (used as fallback)
     */
    public ?string $backButtonText = 'Back';

    /**
     * @var array Site-specific button text settings
     */
    public array $siteButtonTexts = [];

    /**
     * @var array Site-specific general settings
     */
    public array $siteSettings = [];

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'buttonPosition' => 'Button Position',
            'buttonLabel' => 'Button Label',
            'nextButtonText' => 'Next Button Text',
            'doneButtonText' => 'Done Button Text',
            'backButtonText' => 'Back Button Text',
            'siteButtonTexts' => 'Site Button Texts',
            'siteSettings' => 'Site Settings',
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            ['buttonPosition', 'default', 'value' => 'header'],
            ['buttonPosition', 'in', 'range' => ['header', 'sidebar', 'none']],
            ['buttonLabel', 'default', 'value' => 'Tours'],
            ['nextButtonText', 'default', 'value' => 'Next'],
            ['doneButtonText', 'default', 'value' => 'Done'],
            ['backButtonText', 'default', 'value' => 'Back'],
            [['nextButtonText', 'doneButtonText', 'backButtonText'], 'string', 'max' => 50],
            ['siteButtonTexts', 'safe'],
            ['siteSettings', 'safe'],
        ];
    }

    /**
     * Get button texts for a specific site
     *
     * @param int $siteId Site ID
     * @return array Button texts for the site
     */
    public function getButtonTextsForSite(int $siteId): array
    {
        $siteTexts = $this->siteButtonTexts[$siteId] ?? [];
        
        return [
            'nextButtonText' => $siteTexts['nextButtonText'] ?? $this->nextButtonText,
            'doneButtonText' => $siteTexts['doneButtonText'] ?? $this->doneButtonText,
            'backButtonText' => $siteTexts['backButtonText'] ?? $this->backButtonText,
        ];
    }

    /**
     * Set button texts for a specific site
     *
     * @param int $siteId Site ID
     * @param array $buttonTexts Button texts to set
     */
    public function setButtonTextsForSite(int $siteId, array $buttonTexts): void
    {
        if (!isset($this->siteButtonTexts[$siteId])) {
            $this->siteButtonTexts[$siteId] = [];
        }

        if (isset($buttonTexts['nextButtonText'])) {
            $this->siteButtonTexts[$siteId]['nextButtonText'] = $buttonTexts['nextButtonText'];
        }
        if (isset($buttonTexts['doneButtonText'])) {
            $this->siteButtonTexts[$siteId]['doneButtonText'] = $buttonTexts['doneButtonText'];
        }
        if (isset($buttonTexts['backButtonText'])) {
            $this->siteButtonTexts[$siteId]['backButtonText'] = $buttonTexts['backButtonText'];
        }
    }

    /**
     * Get general settings for a specific site
     *
     * @param int $siteId Site ID
     * @return array General settings for the site
     */
    public function getSettingsForSite(int $siteId): array
    {
        $siteSettings = $this->siteSettings[$siteId] ?? [];
        
        return [
            'buttonPosition' => $siteSettings['buttonPosition'] ?? $this->buttonPosition,
            'buttonLabel' => $siteSettings['buttonLabel'] ?? $this->buttonLabel,
        ];
    }

    /**
     * Set general settings for a specific site
     *
     * @param int $siteId Site ID
     * @param array $settings General settings to set
     */
    public function setSettingsForSite(int $siteId, array $settings): void
    {
        if (!isset($this->siteSettings[$siteId])) {
            $this->siteSettings[$siteId] = [];
        }

        if (isset($settings['buttonPosition'])) {
            $this->siteSettings[$siteId]['buttonPosition'] = $settings['buttonPosition'];
        }
        if (isset($settings['buttonLabel'])) {
            $this->siteSettings[$siteId]['buttonLabel'] = $settings['buttonLabel'];
        }
    }

    /**
     * Get all settings for a specific site (combines general and button text settings)
     *
     * @param int $siteId Site ID
     * @return array All settings for the site
     */
    public function getAllSettingsForSite(int $siteId): array
    {
        return array_merge(
            $this->getSettingsForSite($siteId),
            $this->getButtonTextsForSite($siteId)
        );
    }
}
