<?php

namespace zeix\boarding\helpers;

use Craft;
use craft\web\Request;
use yii\web\NotFoundHttpException;

class SiteHelper
{
    /**
     * Get the site for the current request, with fallback options
     * 
     * @param Request $request The request object
     * @param bool $requireSiteParam Whether to require a site parameter (default: false)
     * @return \craft\models\Site
     * @throws NotFoundHttpException If site parameter is required but not found, or if site parameter exists but site not found
     */
    public static function getSiteForRequest(Request $request, bool $requireSiteParam = false): \craft\models\Site
    {
        $siteHandle = $request->getQueryParam('site');
        if (!$siteHandle) {
            if ($requireSiteParam) {
                throw new NotFoundHttpException('Site parameter is required but not provided');
            }
            $currentSite = Craft::$app->getSites()->getCurrentSite();
            return $currentSite;
        }

        $cleanSiteHandle = self::cleanSiteHandle($siteHandle);

        $site = Craft::$app->getSites()->getSiteByHandle($cleanSiteHandle);

        if (!$site) {
            throw new NotFoundHttpException('Site not found: ' . $cleanSiteHandle);
        }

        Craft::$app->getSites()->setCurrentSite($site);

        Craft::$app->language = $site->language;

        return $site;
    }

    /**
     * Clean the site handle by removing any query parameters that might be appended
     * 
     * @param string $siteHandle The raw site handle
     * @return string The cleaned site handle
     */
    private static function cleanSiteHandle(string $siteHandle): string
    {
        $cleanHandle = $siteHandle;

        $questionMarkPos = strpos($siteHandle, '?');
        if ($questionMarkPos !== false) {
            $cleanHandle = substr($siteHandle, 0, $questionMarkPos);
        }

        return $cleanHandle;
    }
}
