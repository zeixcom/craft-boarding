<?php

/**
 * Boarding module for Craft CMS 5.x
 *
 * A module that makes onboarding tours available in the Craft CP.
 *
 * @link      https://zeix.com
 * @copyright Copyright (c) 2025 Fabian Häfliger
 */

namespace zeix\boarding;

use zeix\boarding\services\ToursService;
use zeix\boarding\services\ImportService;
use zeix\boarding\services\ExportService;
use zeix\boarding\assetbundles\BoardingAsset;
use zeix\boarding\models\Settings;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\View;
use craft\web\UrlManager;
use craft\events\RegisterUrlRulesEvent;
use zeix\boarding\helpers\SiteHelper;
use yii\base\Event;
use craft\base\Plugin;
use Craft;
use craft\base\Model;

/**
 * Boarding plugin for Craft CMS
 *
 * A plugin that makes onboarding tours available in the Craft CP.
 *
 * @property ToursService $tours
 * @property ImportService $import
 * @property \zeix\boarding\services\ExportService $export
 * @method static Boarding getInstance()
 */
class Boarding extends Plugin
{
    /**
     * @var string
     */
    public const EDITION_LITE = 'lite';

    /**
     * @var string
     */
    public const EDITION_STANDARD = 'standard';

    /**
     * @var int Maximum number of tours allowed in Lite edition
     */
    public const LITE_TOUR_LIMIT = 3;

    /**
     * @var self|null
     */
    private static ?self $plugin = null;

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.2';

    /**
     * @var bool
     */
    public bool $hasCpSection = true;

    /**
     * @var bool
     */
    public bool $hasCpSettings = false;

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_STANDARD,
        ];
    }


    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('boarding/settings', [
            'settings' => $this->getSettings(),
        ]);
    }


    /**
     * Initialize plugin
     */
    public function init(): void
    {
        parent::init();

        Craft::setAlias('@boarding', $this->getBasePath());

        $this->setComponents([
            'tours' => ToursService::class,
            'import' => ImportService::class,
            'export' => ExportService::class,
        ]);

        $this->controllerNamespace = 'zeix\\boarding\\controllers';

        // Register tour save event handler
        Event::on(
            \zeix\boarding\models\Tour::class,
            \zeix\boarding\models\Tour::EVENT_AFTER_SAVE_TOUR,
            function (\zeix\boarding\events\TourEvent $event) {
                Boarding::getInstance()->tours->saveTourUserGroups(
                    $event->tour->id,
                    $event->tour->userGroupIds
                );
            }
        );

        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['boarding'] = $this->getBasePath() . '/templates';
            }
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('boarding', 'Onboarding Tours'),
                    'permissions' => $this->_registerPermissions(),
                ];
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $rules = [
                    'boarding' => 'boarding/tours/index',
                    'boarding/tours' => 'boarding/tours/index',
                    'boarding/tours/new' => 'boarding/tours/edit',
                    'boarding/tours/edit/<id:\d+>' => 'boarding/tours/edit',
                    'boarding/settings' => 'boarding/settings/index',
                    'boarding/tours/import' => 'boarding/import/import',
                    'boarding/tours/import-tours' => 'boarding/import/import-tours',
                    'boarding/tours/export-tour' => 'boarding/export/export-tour',
                    'boarding/tours/export-all-tours' => 'boarding/export/export-all-tours'
                ];

                $event->rules = array_merge($event->rules, $rules);
            }
        );

        /** @var Settings $settings */
        $settings = $this->getSettings();
        $allSites = Craft::$app->getSites()->getAllSites();

        if (Craft::$app->request instanceof \craft\console\Request) {
            $currentSite = Craft::$app->getSites()->getPrimarySite();
        } else {
            $requireSiteParam = count($allSites) > 1;
            try {
                $currentSite = SiteHelper::getSiteForRequest(Craft::$app->request, $requireSiteParam);
            } catch (\Exception $e) {
                if (Craft::$app->getConfig()->getGeneral()->devMode) {
                    Craft::warning('Boarding plugin: Site resolution failed - ' . $e->getMessage(), 'boarding');
                }

                $currentSite = Craft::$app->getSites()->getCurrentSite();
            }
        }

        // Only register assets and JS settings for web requests
        if (!Craft::$app->request instanceof \craft\console\Request) {
            if (count($allSites) > 1) {
                $siteSettings = $settings->getAllSettingsForSite($currentSite->id);
            } else {
                $siteSettings = [
                    'defaultBehavior' => $settings->defaultBehavior,
                    'buttonPosition' => $settings->buttonPosition,
                    'buttonLabel' => $settings->buttonLabel,
                    'nextButtonText' => $settings->nextButtonText,
                    'doneButtonText' => $settings->doneButtonText,
                    'backButtonText' => $settings->backButtonText,
                ];
            }

            $view = Craft::$app->getView();
            $view->registerAssetBundle(BoardingAsset::class);

            $jsSettings = [
                'buttonPosition' => $siteSettings['buttonPosition'],
                'defaultBehavior' => $siteSettings['defaultBehavior'],
                'buttonLabel' => $siteSettings['buttonLabel'],
                'nextButtonText' => $siteSettings['nextButtonText'],
                'doneButtonText' => $siteSettings['doneButtonText'],
                'backButtonText' => $siteSettings['backButtonText'],
                'currentSiteId' => $currentSite->id,
                'currentSiteHandle' => $currentSite->handle,
                'isMultiSite' => count($allSites) > 1,
            ];

            $view->registerJs('
                window.boardingSettings = ' . json_encode($jsSettings) . ';
                ', View::POS_BEGIN);
        }
    }

    /**
     * Get the plugin's CP nav item
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $item['icon'] = '@boarding/icon.svg';
        $item['url'] = 'boarding/tours';

        $subnav = [];

        $currentUser = Craft::$app->getUser();
        if ($currentUser && $currentUser->checkPermission('boarding:manageTourSettings')) {
            $subnav['settings'] = [
                'label' => Craft::t('boarding', 'Settings'),
                'url' => 'boarding/settings',
                'icon' => '@boarding/icon.svg'
            ];
        }
        $item['subnav'] = $subnav;

        return $item;
    }

    /**
     * Get the plugin's name
     */
    public static function displayName(): string
    {
        return Craft::t('boarding', 'Boarding');
    }

    /**
     * Get the plugin's description
     */
    public static function description(): string
    {
        return Craft::t('boarding', 'Makes onboarding tours available in the Craft CP.');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect('boarding/settings');
    }

    public static function getInstance(): self
    {
        if (self::$plugin === null) {
            self::$plugin = parent::getInstance();
        }

        return self::$plugin;
    }

    private function _registerPermissions(): array
    {
        return [
            'boarding:createtours' => [
                'label' => Craft::t('boarding', 'Create tours')
            ],
            'boarding:edittours' => [
                'label' => Craft::t('boarding', 'Edit tours')
            ],
            'boarding:deletetours' => [
                'label' => Craft::t('boarding', 'Delete tours')
            ],
            'boarding:managetoursettings' => [
                'label' => Craft::t('boarding', 'Manage tour settings')
            ],
        ];
    }
}
