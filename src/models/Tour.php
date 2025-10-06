<?php

namespace zeix\boarding\models;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Json;
use zeix\boarding\elements\db\TourQuery;
use zeix\boarding\records\TourRecord;
use zeix\boarding\events\TourEvent;
use Exception;

/**
 * Tour model representing a boarding tour.
 */
class Tour extends Element
{
    /**
     * @event TourEvent The event that is triggered after a tour is saved.
     */
    public const EVENT_AFTER_SAVE_TOUR = 'afterSaveTour';

    public ?string $tourId = null;
    public ?string $description = null;

    /**
     * @var bool Whether the tour is enabled
     */
    public bool $enabled = true;

    /**
     * @var bool Whether the tour is translatable across sites
     */
    public bool $translatable = false;

    public string $progressPosition = 'off';
    public array $userGroupIds = [];
    private array $_steps = [];


    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('boarding', 'Tour');
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('boarding', 'Tours');
    }

    /**
     * @inheritdoc
     */
    public static function refHandle(): ?string
    {
        return 'tour';
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasUris(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function find(): ElementQueryInterface
    {
        return new TourQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(?string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('boarding', 'All tours'),
                'criteria' => []
            ]
        ];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sources[] = [
                'key' => 'site:' . $site->id,
                'label' => $site->name,
                'criteria' => [
                    'siteId' => $site->id
                ]
            ];
        }

        return $sources;
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(?string $source = null): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['tourId', 'title'], 'required'];
        $rules[] = [['enabled', 'translatable'], 'boolean'];
        $rules[] = ['progressPosition', 'in', 'range' => ['off', 'top', 'bottom']];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getSupportedSites(): array
    {
        if ($this->translatable) {
            return array_map(function ($site) {
                return [
                    'siteId' => $site->id,
                    'enabledByDefault' => true
                ];
            }, Craft::$app->getSites()->getAllSites());
        }

        return [
            ['siteId' => $this->siteId, 'enabledByDefault' => true]
        ];
    }

    /**
     * Get tour steps.
     * 
     * @return array
     */
    public function getSteps(): array
    {
        if (empty($this->_steps) && !empty($this->_rawData)) {
            $this->_steps = Json::decode($this->_rawData, true)['steps'] ?? [];
        }

        return $this->_steps;
    }

    /**
     * Set tour steps.
     * 
     * @param array $steps
     */
    public function setSteps(array $steps): void
    {
        $this->_steps = $steps;
    }


    /**
     * Get the raw data to be stored in the database.
     * 
     * @return string
     */
    public function getRawData(): string
    {
        return Json::encode([
            'steps' => $this->_steps
        ]);
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew): void
    {
        if (!$isNew) {
            $record = TourRecord::findOne($this->id);
            if (!$record) {
                throw new Exception('Invalid tour ID: ' . $this->id);
            }
        } else {
            $record = new TourRecord();
            $record->id = $this->id;
        }

        $record->tourId = $this->tourId;
        $record->description = $this->description;
        $record->enabled = $this->enabled;
        $record->translatable = $this->translatable;
        $record->progressPosition = $this->progressPosition;
        $record->data = $this->getRawData();

        if (property_exists($record, 'siteId')) {
            $record->siteId = $this->siteId;
        }

        $record->save(false);

        parent::afterSave($isNew);

        // Trigger event for external handling instead of directly accessing services
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_TOUR)) {
            $this->trigger(self::EVENT_AFTER_SAVE_TOUR, new TourEvent([
                'tour' => $this,
                'isNew' => $isNew,
            ]));
        }
    }

    public function getIsEditable(): bool
    {
        return true;
    }

    public function getCpEditUrl(): ?string
    {
        return 'boarding/tours/' . $this->id;
    }

    public function getFieldLayout(): ?\craft\models\FieldLayout
    {
        return null;
    }

    public function beforeSave(bool $isNew): bool
    {
        if (isset($this->data) && is_array($this->data)) {
            $this->data = Json::encode($this->data);
        }

        return parent::beforeSave($isNew);
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'name' => ['label' => Craft::t('boarding', 'Name')],
            'description' => ['label' => Craft::t('boarding', 'Description')],
            'site' => ['label' => Craft::t('boarding', 'Site')],
            'dateCreated' => ['label' => Craft::t('boarding', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('boarding', 'Date Updated')],
        ];
    }

    public function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'site':
                return Craft::$app->getSites()->getSiteById($this->siteId)->name;
            default:
                return parent::tableAttributeHtml($attribute);
        }
    }
}
