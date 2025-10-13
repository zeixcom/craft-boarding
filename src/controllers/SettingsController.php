<?php

namespace zeix\boarding\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use zeix\boarding\Boarding;
use zeix\boarding\helpers\SiteHelper;

class SettingsController extends Controller
{
    /**
     * Show settings page
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('boarding:managetoursettings');

        $plugin = Boarding::getInstance();
        $allSites = Craft::$app->getSites()->getAllSites();
        $isProEdition = $plugin->is(Boarding::EDITION_PRO);
        $isMultiSite = count($allSites) > 1;
        
        /** @var \zeix\boarding\models\Settings $settings */
        $settings = $plugin->getSettings();

        $requireSiteParam = $isMultiSite && $isProEdition;
        $currentSite = $requireSiteParam ? SiteHelper::getSiteForRequest($this->request, true) : Craft::$app->getSites()->getPrimarySite();

        $siteSettings = $settings->getSettingsForSite($currentSite->id);
        $siteButtonTexts = $settings->getButtonTextsForSite($currentSite->id);

        return $this->renderTemplate('boarding/settings/index', [
            'settings' => $settings,
            'currentSite' => $currentSite,
            'allSites' => $allSites,
            'siteSettings' => $siteSettings,
            'siteButtonTexts' => $siteButtonTexts,
            'isProEdition' => $isProEdition,
            'isMultiSite' => $isMultiSite
        ]);
    }

    /**
     * Save plugin settings
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('boarding:managetoursettings');

        $plugin = Boarding::getInstance();
        /** @var \zeix\boarding\models\Settings $settings */
        $settings = $plugin->getSettings();
        $allSites = Craft::$app->getSites()->getAllSites();
        $isProEdition = $plugin->is(Boarding::EDITION_PRO);
        $isMultiSite = count($allSites) > 1;

        $requireSiteParam = $isMultiSite && $isProEdition;
        $currentSite = $requireSiteParam ? SiteHelper::getSiteForRequest($this->request, true) : Craft::$app->getSites()->getPrimarySite();

        $postedSettings = $this->request->getBodyParam('settings', []);
        $siteButtonTexts = $this->request->getBodyParam('siteButtonTexts', []);
        $siteGeneralSettings = $this->request->getBodyParam('siteSettings', []);

        if ($isMultiSite && $isProEdition) {
            if (!empty($siteButtonTexts)) {
                $settings->setButtonTextsForSite($currentSite->id, $siteButtonTexts);
                $postedSettings['siteButtonTexts'] = $settings->siteButtonTexts;
            }
            if (!empty($siteGeneralSettings)) {
                $settings->setSettingsForSite($currentSite->id, $siteGeneralSettings);
                $postedSettings['siteSettings'] = $settings->siteSettings;
            }
        }

        $settings->setAttributes($postedSettings, false);

        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('boarding', "Couldn't save settings."));
            return null;
        }

        $result = Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->getAttributes());

        if ($result) {
            Craft::$app->getSession()->setNotice(Craft::t('boarding', 'Settings saved.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('boarding', "Couldn't save settings."));
        }

        return $this->redirectToPostedUrl();
    }
}
