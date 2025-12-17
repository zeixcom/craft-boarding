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
use zeix\boarding\assetbundles\BoardingAsset;
use zeix\boarding\models\Settings;
use zeix\boarding\models\Tour;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\View;
use craft\web\UrlManager;
use craft\events\RegisterUrlRulesEvent;
use zeix\boarding\helpers\SiteHelper;
use yii\base\Event;
use zeix\boarding\events\TourEvent;
use craft\base\Plugin;
use Craft;
use craft\base\Model;
use craft\helpers\Json;

/**
 * Boarding plugin for Craft CMS
 *
 * A plugin that makes onboarding tours available in the Craft CP.
 *
 * @property ToursService $tours
 * @property ImportService $import
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
    public const EDITION_PRO = 'pro';

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
    public string $schemaVersion = '1.0.3';

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
            self::EDITION_PRO,
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
        ]);

        $this->controllerNamespace = 'zeix\\boarding\\controllers';

        // Register element types
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = Tour::class;
            }
        );

        Event::on(
            Tour::class,
            Tour::EVENT_AFTER_SAVE_TOUR,
            function (TourEvent $event) {
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
                ];

                $event->rules = array_merge($event->rules, $rules);
            }
        );

        Craft::$app->onInit(function () {
            if (Craft::$app->request->getIsCpRequest()) {
                /** @var Settings $settings */
                $settings = $this->getSettings();
                $allSites = Craft::$app->getSites()->getAllSites();

                // Config-only CSS variable overrides from config/boarding.php

                $configOverrides = Craft::$app->config->getConfigFromFile('boarding');
                if (!is_array($configOverrides)) {
                    $configOverrides = [];
                }

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

                if (count($allSites) > 1) {
                    $siteSettings = $settings->getAllSettingsForSite($currentSite->id);
                } else {
                    $siteSettings = [
                        'buttonPosition' => $settings->buttonPosition,
                        'buttonLabel' => $settings->buttonLabel,
                        'nextButtonText' => $settings->nextButtonText,
                        'doneButtonText' => $settings->doneButtonText,
                        'backButtonText' => $settings->backButtonText,
                    ];
                }

                $view = Craft::$app->getView();
                $view->registerAssetBundle(BoardingAsset::class);

                $configCssVars = $configOverrides['cssVariables'] ?? [];
                $configSiteCssVars = $configOverrides['siteCssVariables'] ?? [];
                if (!is_array($configCssVars)) {
                    $configCssVars = [];
                }
                if (!is_array($configSiteCssVars)) {
                    $configSiteCssVars = [];
                }

                $siteHandle = $currentSite->handle;
                $perSiteVars = $configSiteCssVars[$siteHandle] ?? [];
                if (!is_array($perSiteVars)) {
                    $perSiteVars = [];
                }

                $cssVars = array_merge($configCssVars, $perSiteVars);
                $cssVars = $this->_normalizeCssVariables($cssVars);

                $cssOverride = $this->_buildCssOverride($cssVars);
                if ($cssOverride !== null) {
                    $view->registerCss($cssOverride, [], 'boarding-css-overrides');

                    if (Craft::$app->getConfig()->getGeneral()->devMode) {
                        Craft::info('Boarding plugin: Registered CSS variable overrides for site ' . $currentSite->handle . ' (vars=' . count($cssVars) . ')', 'boarding');
                        Craft::info('Boarding plugin: CSS variables: ' . Json::encode($cssVars), 'boarding');
                    }
                } elseif (Craft::$app->getConfig()->getGeneral()->devMode) {
                    Craft::info('Boarding plugin: No CSS variable overrides configured for site ' . $currentSite->handle, 'boarding');
                }

                $jsSettings = [
                    'buttonPosition' => $siteSettings['buttonPosition'],
                    'buttonLabel' => $siteSettings['buttonLabel'],
                    'nextButtonText' => $siteSettings['nextButtonText'],
                    'doneButtonText' => $siteSettings['doneButtonText'],
                    'backButtonText' => $siteSettings['backButtonText'],
                    'currentSiteId' => $currentSite->id,
                    'currentSiteHandle' => $currentSite->handle,
                ];

                $view->registerJs('
                    window.boardingSettings = ' . json_encode($jsSettings) . ';
                    ', View::POS_BEGIN);
            }
        });
    }

    /**
     * Normalize + sanitize CSS variables map.
     *
     * @param array $vars Map of CSS variable names to values.
     * @return array Sanitized map with keys in `--var-name` form.
     */
    private function _normalizeCssVariables(array $vars): array
    {
        $result = [];

        foreach ($vars as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            $name = trim($name);
            if ($name === '') {
                continue;
            }

            // Allow keys without leading `--` (we’ll add it)
            if (!str_starts_with($name, '--')) {
                $name = '--' . ltrim($name, '-');
            }

            // Strict var name allowlist
            if (!preg_match('/^--[a-zA-Z0-9_-]+$/', $name)) {
                continue;
            }

            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }

            // Avoid injecting HTML / style tag breaks
            $value = str_ireplace('</style', '', $value);
            $value = str_replace(['<', '>'], '', $value);

            // Prevent breaking out of the declaration
            $value = str_replace(['{', '}', ';'], '', $value);

            $result[$name] = $value;
        }

        return $result;
    }

    private function _buildCssOverride(array $cssVars): ?string
    {
        if (!empty($cssVars)) {
            $declarations = [];
            foreach ($cssVars as $name => $value) {
                $declarations[] = $name . ': ' . $value . ';';
            }
            return ':root{' . implode('', $declarations) . '}';
        }

        return null;
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

        // Add Import subnav for Pro edition
        if ($this->is(self::EDITION_PRO)) {
            $subnav['import'] = [
                'label' => Craft::t('boarding', 'Import'),
                'url' => 'boarding/tours/import',
            ];
        }

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
