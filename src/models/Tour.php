<?php

namespace zeix\boarding\models;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Edit;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use zeix\boarding\elements\db\TourQuery;
use zeix\boarding\records\TourRecord;
use zeix\boarding\events\TourEvent;
use zeix\boarding\utils\Logger;
use craft\elements\User;
use Exception;
use zeix\boarding\Boarding;

/**
 * Tour model representing a boarding tour.
 */
class Tour extends Element
{
    /**
     * @event TourEvent The event that is triggered after a tour is saved.
     */
    public const EVENT_AFTER_SAVE_TOUR = 'afterSaveTour';

    // Propagation method constants
    public const PROPAGATION_METHOD_NONE = 'none';
    public const PROPAGATION_METHOD_ALL = 'all';
    public const PROPAGATION_METHOD_SITE_GROUP = 'siteGroup';
    public const PROPAGATION_METHOD_CUSTOM = 'custom';
    public const PROPAGATION_METHOD_LANGUAGE = 'language';

    public ?string $tourId = null;
    public ?string $description = null;

    /**
     * @var bool Whether the tour is enabled
     */
    public bool $enabled = true;

    /**
     * @var string How the tour propagates across sites
     */
    public string $propagationMethod = self::PROPAGATION_METHOD_NONE;

    public string $progressPosition = 'off';

    /**
     * @var bool Whether the tour should automatically start for users who haven't completed it
     */
    public bool $autoplay = false;

    public array $userGroupIds = [];
    private array $_steps = [];
    private ?string $_rawData = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if (!$this->tourId) {
            $this->tourId = 'tour_' . \craft\helpers\StringHelper::UUID();
        }

        if (!$this->progressPosition) {
            $this->progressPosition = 'off';
        }

        if ($this->id) {
            $this->userGroupIds = (new \craft\db\Query())
                ->select(['userGroupId'])
                ->from('{{%boarding_tours_usergroups}}')
                ->where(['tourId' => $this->id])
                ->column();
        }
    }


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
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function statuses(): array
    {
        return [
            'enabled' => Craft::t('app', 'Enabled'),
            'disabled' => Craft::t('app', 'Disabled'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): ?string
    {
        return $this->enabled ? 'enabled' : 'disabled';
    }

    /**
     * @inheritdoc
     */
    public static function find(): TourQuery
    {
        return new TourQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(?string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('boarding', 'All tours'),
                'criteria' => [
                    'status' => null
                ]
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(?string $source = null): array
    {
        $actions = [];

        // Edit action
        $actions[] = Edit::class;

        // Delete action
        $actions[] = Craft::$app->getElements()->createAction([
            'type' => Delete::class,
            'confirmationMessage' => Craft::t('boarding', 'Are you sure you want to delete the selected tours?'),
            'successMessage' => Craft::t('boarding', 'Tours deleted.'),
        ]);

        return $actions;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['title'], 'required'];
        $rules[] = [['tourId'], 'required', 'on' => [self::SCENARIO_DEFAULT]];
        $rules[] = [['enabled', 'autoplay'], 'boolean'];
        $rules[] = ['progressPosition', 'in', 'range' => ['off', 'top', 'bottom', 'header', 'footer']];
        $rules[] = ['propagationMethod', 'in', 'range' => [
            self::PROPAGATION_METHOD_NONE,
            self::PROPAGATION_METHOD_ALL,
            self::PROPAGATION_METHOD_SITE_GROUP,
            self::PROPAGATION_METHOD_CUSTOM,
            self::PROPAGATION_METHOD_LANGUAGE,
        ]];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function afterValidate(): void
    {
        parent::afterValidate();

        if ($this->hasErrors()) {
            $errors = $this->getErrors();
            // Log to file
            Craft::error('Tour validation errors: ' . print_r([
                'errors' => $errors,
                'tourId' => $this->tourId,
                'title' => $this->title,
                'progressPosition' => $this->progressPosition,
                'enabled' => $this->enabled,
            ], true), 'boarding');

            // Also log to console for web requests
            if (Craft::$app->getRequest()->getIsCpRequest()) {
                Craft::$app->getSession()->setNotice('Validation errors: ' . json_encode($errors));
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function getSupportedSites(): array
    {
        $sites = match ($this->propagationMethod) {
            self::PROPAGATION_METHOD_ALL => $this->getAllSitesConfig(),
            self::PROPAGATION_METHOD_SITE_GROUP => $this->getSiteGroupConfig(),
            self::PROPAGATION_METHOD_CUSTOM => $this->getCustomSitesConfig(),
            self::PROPAGATION_METHOD_LANGUAGE => $this->getLanguageSitesConfig(),
            default => [['siteId' => $this->siteId, 'enabledByDefault' => true]],
        };

        return $sites;
    }

    /**
     * Get configuration for all sites (same content everywhere)
     */
    private function getAllSitesConfig(): array
    {
        return array_map(function ($site) {
            return [
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'propagate' => true, // Same content across all sites
            ];
        }, Craft::$app->getSites()->getAllSites());
    }

    /**
     * Get configuration for site group (unique content per site)
     * Returns all sites from the same site group as the current site
     */
    private function getSiteGroupConfig(): array
    {
        $currentSiteId = $this->siteId ?: Craft::$app->getSites()->getCurrentSite()->id;
        $currentSite = Craft::$app->getSites()->getSiteById($currentSiteId);

        if (!$currentSite) {
            return [[
                'siteId' => $currentSiteId,
                'enabledByDefault' => true,
            ]];
        }

        // Get all sites in the same group
        $allSites = Craft::$app->getSites()->getAllSites();
        $sitesInGroup = [];

        foreach ($allSites as $site) {
            if ($site->groupId === $currentSite->groupId) {
                $sitesInGroup[] = [
                    'siteId' => $site->id,
                    'enabledByDefault' => true,
                ];
            }
        }

        return $sitesInGroup ?: [[
            'siteId' => $currentSiteId,
            'enabledByDefault' => true,
        ]];
    }

    /**
     * Get configuration for custom selected sites
     */
    private function getCustomSitesConfig(): array
    {
        return [['siteId' => $this->siteId, 'enabledByDefault' => true]];
    }

    /**
     * Get configuration for sites with same language
     */
    private function getLanguageSitesConfig(): array
    {
        $currentSite = Craft::$app->getSites()->getSiteById($this->siteId);
        if (!$currentSite) {
            return [['siteId' => $this->siteId, 'enabledByDefault' => true]];
        }

        // For new tours, only return the current site to avoid duplicate key errors
        // The ensureElementSitesEntries() method will add other language sites after save
        if (!$this->id) {
            return [[
                'siteId' => $this->siteId,
                'enabledByDefault' => true,
            ]];
        }

        $currentLanguage = $currentSite->language;
        $sitesWithSameLanguage = [];

        $allSites = Craft::$app->getSites()->getAllSites();
        foreach ($allSites as $site) {
            if ($site->language === $currentLanguage) {
                $sitesWithSameLanguage[] = [
                    'siteId' => $site->id,
                    'enabledByDefault' => true,
                ];
            }
        }

        return $sitesWithSameLanguage;
    }

    /**
     * Set raw data from database.
     *
     * @param string|null $data
     */
    public function setData(?string $data): void
    {
        $this->_rawData = $data;
    }

    /**
     * Get raw data.
     *
     * @return string|null
     */
    public function getData(): ?string
    {
        return $this->_rawData;
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
     * Get user groups (alias for userGroupIds for backward compatibility).
     *
     * @return array
     */
    public function getUserGroups(): array
    {
        return $this->userGroupIds;
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
        if (empty($this->_steps) && !empty($this->_rawData)) {
            $decoded = Json::decode($this->_rawData, true);
            $this->_steps = $decoded['steps'] ?? [];
        }

        $encoded = Json::encode([
            'steps' => $this->_steps
        ]);

        return $encoded;
    }

    /**
     * @inheritdoc
     */
    public function beforeValidate(): bool
    {
        if (!$this->slug) {
            $this->slug = \craft\helpers\ElementHelper::generateSlug($this->title ?: 'tour');
        }

        if ($this->duplicateOf) {
            $this->tourId = 'tour_' . \craft\helpers\StringHelper::UUID();
        }

        return parent::beforeValidate();
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew): void
    {
        $record = TourRecord::findOne($this->id);

        if (!$record) {
            $record = new TourRecord();
            $record->id = $this->id;
        } elseif ($this->propagating) {
            parent::afterSave($isNew);

            if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_TOUR)) {
                $this->trigger(self::EVENT_AFTER_SAVE_TOUR, new TourEvent([
                    'tour' => $this,
                    'isNew' => $isNew,
                ]));
            }

            return;
        }

        $currentData = $this->getRawData();

        $record->tourId = $this->tourId;
        $record->name = $this->title;
        $record->description = $this->description;
        $record->enabled = $this->enabled;

        $record->propagationMethod = $this->propagationMethod;
        $record->progressPosition = $this->progressPosition;
        $record->autoplay = $this->autoplay;
        $record->data = $currentData;

        // For language propagation, don't change the siteId - keep it as the primary site
        // This ensures we update the single shared record regardless of which site we're editing from
        if (property_exists($record, 'siteId') && $this->propagationMethod !== self::PROPAGATION_METHOD_LANGUAGE) {
            $record->siteId = $this->siteId;
        }

        $record->save(false);


        parent::afterSave($isNew);

        if (!$this->propagating && in_array($this->propagationMethod, [
            self::PROPAGATION_METHOD_LANGUAGE,
            self::PROPAGATION_METHOD_SITE_GROUP
        ])) {
            $this->ensureElementSitesEntries();
        }

        if (!$this->propagating && $this->propagationMethod === self::PROPAGATION_METHOD_LANGUAGE) {
            $this->propagateDataToLanguageSites();
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_TOUR)) {
            $this->trigger(self::EVENT_AFTER_SAVE_TOUR, new TourEvent([
                'tour' => $this,
                'isNew' => $isNew,
            ]));
        }
    }

    /**
     * Ensure elements_sites entries exist for all supported sites
     */
    private function ensureElementSitesEntries(): void
    {
        $supportedSites = $this->getSupportedSites();

        foreach ($supportedSites as $siteConfig) {
            $siteId = $siteConfig['siteId'];

            // Check if elements_sites entry exists
            $exists = (new \craft\db\Query())
                ->select('id')
                ->from('{{%elements_sites}}')
                ->where([
                    'elementId' => $this->id,
                    'siteId' => $siteId,
                ])
                ->exists();

            if (!$exists) {
                try {
                    $slug = \craft\helpers\ElementHelper::generateSlug($this->title);

                    $slugCount = 0;
                    $testSlug = $slug;
                    while ((new \craft\db\Query())
                        ->from('{{%elements_sites}}')
                        ->where([
                            'siteId' => $siteId,
                            'slug' => $testSlug,
                        ])
                        ->andWhere(['!=', 'elementId', $this->id])
                        ->exists()
                    ) {
                        $slugCount++;
                        $testSlug = $slug . '-' . $slugCount;
                    }

                    Craft::$app->getDb()->createCommand()->insert('{{%elements_sites}}', [
                        'elementId' => $this->id,
                        'siteId' => $siteId,
                        'slug' => $testSlug,
                        'uri' => null,
                        'enabled' => $this->enabled,
                        'dateCreated' => new \yii\db\Expression('NOW()'),
                        'dateUpdated' => new \yii\db\Expression('NOW()'),
                        'uid' => \craft\helpers\StringHelper::UUID(),
                    ])->execute();
                } catch (\yii\db\IntegrityException $e) {
                    // Entry already exists (race condition with Craft's own creation)
                    // This is fine - silently continue
                }
            }
        }
    }

    /**
     * Propagate data (steps) to all sites with the same language
     */
    private function propagateDataToLanguageSites(): void
    {
        $currentSite = Craft::$app->getSites()->getSiteById($this->siteId);
        if (!$currentSite) {
            return;
        }

        $currentLanguage = $currentSite->language;
        $currentData = $this->getRawData();

        $allSites = Craft::$app->getSites()->getAllSites();
        foreach ($allSites as $site) {
            if ($site->language === $currentLanguage && $site->id !== $this->siteId) {
                Craft::$app->getDb()->createCommand()->update(
                    '{{%boarding_tours}}',
                    ['data' => $currentData],
                    [
                        'id' => $this->id,
                        'siteId' => $site->id,
                    ]
                )->execute();
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function afterDelete(): void
    {
        if ($this->id) {
            TourRecord::deleteAll(['id' => $this->id]);
        }

        parent::afterDelete();
    }

    public function canView(User $user): bool
    {
        return $user->can('accessPlugin-boarding');
    }

    public function canSave(User $user): bool
    {
        if ($this->id) {
            return $user->can('boarding:edittours');
        }
        return $user->can('boarding:createtours');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('boarding:deletetours');
    }

    public function canDuplicate(User $user): bool
    {
        if (!$user->can('boarding:createtours')) {
            return false;
        }

        // Check tour limit for Lite edition
        $boarding = Boarding::getInstance();
        if (!$boarding->is(Boarding::EDITION_PRO)) {
            $existingTourCount = self::find()->count();
            if ($existingTourCount >= Boarding::LITE_TOUR_LIMIT) {
                return false;
            }
        }

        return true;
    }

    public function getIsEditable(): bool
    {
        return Craft::$app->getUser()->checkPermission('boarding:edittours');
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('boarding/tours/edit/' . $this->id);
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

        // Always ensure tourId exists
        if (!$this->tourId) {
            $this->tourId = 'tour_' . \craft\helpers\StringHelper::UUID();
        }

        // Ensure progressPosition has a value
        if (!$this->progressPosition) {
            $this->progressPosition = 'off';
        }

        // Generate slug if not set
        if ($isNew && !$this->slug && $this->title) {
            $this->slug = \craft\helpers\ElementHelper::generateSlug($this->title);
        }

        // Ensure title is set
        if (!$this->title) {
            $this->title = 'New Tour';
        }

        return parent::beforeSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function afterPropagate(bool $isNew): void
    {
        parent::afterPropagate($isNew);

        if ($isNew && !empty($this->userGroupIds)) {
            Boarding::getInstance()->tours->saveTourUserGroups($this->id, $this->userGroupIds);
        }

        // For propagating tours (language, all, siteGroup), we need to manually sync
        // the title and description across all supported sites because Craft doesn't
        // automatically propagate these base element properties even with propagate: true
        if (in_array($this->propagationMethod, [
            self::PROPAGATION_METHOD_ALL,
            self::PROPAGATION_METHOD_LANGUAGE,
            self::PROPAGATION_METHOD_SITE_GROUP
        ])) {
            $this->propagateTitleAndDescription();
        }
    }

    /**
     * Propagate title and description to all supported sites
     * This is necessary because Craft doesn't automatically propagate these fields
     * We load each site's element and update title only (steps are already in the shared boarding_tours table)
     */
    private function propagateTitleAndDescription(): void
    {
        $supportedSites = $this->getSupportedSites();
        $currentTitle = $this->title;
        $currentDescription = $this->description;

        foreach ($supportedSites as $siteInfo) {
            $siteId = $siteInfo['siteId'];

            if ($siteId == $this->siteId) {
                continue;
            }

            $tourForSite = self::find()
                ->id($this->id)
                ->siteId($siteId)
                ->status(null)
                ->one();

            if ($tourForSite && ($tourForSite->title !== $currentTitle || $tourForSite->description !== $currentDescription)) {
                $tourForSite->title = $currentTitle;
                $tourForSite->description = $currentDescription;

                $tourForSite->propagating = true;

                Craft::$app->getElements()->saveElement($tourForSite, false, false);
            }
        }
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'description' => ['label' => Craft::t('boarding', 'Description')],
            'propagationMethod' => ['label' => Craft::t('boarding', 'Propagation Method')],
            'progressPosition' => ['label' => Craft::t('boarding', 'Progress Position')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return [
            'description',
            'dateCreated',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'dateCreated' => Craft::t('app', 'Date Created'),
            'dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    public function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'progressPosition':
                return ucfirst($this->progressPosition);
            default:
                return parent::tableAttributeHtml($attribute);
        }
    }
}
