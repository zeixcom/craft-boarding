<?php

namespace zeix\boarding\controllers;

use Craft;
use craft\helpers\Html;
use craft\web\Controller;
use yii\web\Response;
use zeix\boarding\Boarding;
use zeix\boarding\exceptions\TourException;
use zeix\boarding\helpers\SiteHelper;
use zeix\boarding\models\Tour;
use yii\web\NotFoundHttpException;

/**
 * Tours Controller - Simplified version
 *
 * Handles HTTP requests for tour operations.
 */
class ToursController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * Tour index page
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-boarding');
        return $this->renderTemplate('boarding/tours/index');
    }

    /**
     * Tour edit page
     */
    public function actionEdit(?string $id = null): Response
    {
        if ($id) {
            $this->requirePermission('boarding:edittours');
            $tour = Tour::find()->id($id)->status(null)->one();
            if (!$tour) {
                throw new NotFoundHttpException('Tour not found');
            }
        } else {
            $this->requirePermission('boarding:createtours');
            $tour = null;
        }

        $site = SiteHelper::getSiteForRequestAuto($this->request);
        $data = Boarding::getInstance()->tours->getTourForEdit($id ? (int)$id : null, $site);

        // Get available sites for this tour (sites where elements_sites entries exist)
        $availableSites = [];
        if ($id) {
            $siteIds = (new \craft\db\Query())
                ->select('siteId')
                ->from('{{%elements_sites}}')
                ->where(['elementId' => $id])
                ->column();

            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                if (in_array($site->id, $siteIds)) {
                    $availableSites[] = $site;
                }
            }
        } else {
            // New tour: only current site is available
            $availableSites = [$site];
        }

        return $this->renderTemplate('boarding/tours/edit', [
            'tour' => $tour,
            'primarySite' => $data['primarySite'],
            'currentSite' => $data['currentSite'],
            'isProEdition' => $data['isProEdition'],
            'availableSites' => $availableSites,
        ]);
    }

    /**
     * Get all tours
     */
    public function actionGetAllTours(): Response
    {
        try {
            $tours = Boarding::getInstance()->tours->getAllTours();
            return $this->asJson(['success' => true, 'tours' => $tours]);
        } catch (\Exception $e) {
            Craft::error('Failed to get all tours: ' . $e->getMessage(), 'boarding');
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('boarding', 'Failed to load tours. Please try again.')
            ]);
        }
    }

    /**
     * Get tours for the current user
     */
    public function actionGetToursForCurrentUser(): Response
    {
        try {
            $tours = Boarding::getInstance()->tours->getToursForCurrentUser();
            return $this->asJson(['success' => true, 'tours' => $tours]);
        } catch (\Exception $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Mark tour as completed
     */
    public function actionMarkCompleted(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $tourId = $this->request->getBodyParam('tourId');
        if (!$tourId) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('boarding', 'No tour ID provided')
            ]);
        }

        try {
            Boarding::getInstance()->tours->markTourCompleted($tourId);
            return $this->asJson([
                'success' => true,
                'message' => Craft::t('boarding', 'Tour marked as completed')
            ]);
        } catch (TourException $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Save a tour
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $id = $this->request->getBodyParam('id');
        $this->checkPermissions($id);

        if (!$id && !$this->checkTourLimit()) {
            return $this->redirect('boarding/tours');
        }

        try {
            $tour = $this->prepareTour($id);
            $this->populateTourFromRequest($tour);

            if (!Craft::$app->getElements()->saveElement($tour)) {
                Craft::$app->getSession()->setError(Craft::t('boarding', 'Couldn\'t save tour.'));
                Craft::$app->getUrlManager()->setRouteParams(['tour' => $tour]);
                return null;
            }

            $this->saveRelatedData($tour);

            Craft::$app->getSession()->setNotice(Craft::t('boarding', 'Tour saved.'));
            return $this->redirectToPostedUrl();
        } catch (TourException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return null;
        }
    }

    // ==========================================
    // PRIVATE HELPER METHODS
    // ==========================================

    /**
     * Check permissions for save operation
     */
    private function checkPermissions(?string $id): void
    {
        if ($id) {
            $this->requirePermission('boarding:edittours');
        } else {
            $this->requirePermission('boarding:createtours');
        }
    }

    /**
     * Check if tour limit has been reached (Lite edition)
     */
    private function checkTourLimit(): bool
    {
        if (Boarding::getInstance()->is(Boarding::EDITION_PRO)) {
            return true;
        }

        $existingTourCount = Tour::find()->count();
        if ($existingTourCount >= Boarding::LITE_TOUR_LIMIT) {
            Craft::$app->getSession()->setError(
                Craft::t('boarding', 'You have reached the maximum of {limit} tours allowed in Boarding Lite. Upgrade to Boarding Pro for unlimited tours.', [
                    'limit' => Boarding::LITE_TOUR_LIMIT
                ])
            );
            return false;
        }

        return true;
    }

    /**
     * Prepare tour model (new or existing)
     */
    private function prepareTour(?string $id): Tour
    {
        if ($id) {
            $tour = Tour::find()->id($id)->status(null)->one();
            if (!$tour) {
                throw TourException::notFound($id);
            }
            return $tour;
        }

        return new Tour();
    }

    /**
     * Populate tour from request data
     */
    private function populateTourFromRequest(Tour $tour): void
    {
        $currentSite = SiteHelper::getSiteForRequestAuto($this->request);

        $tour->title = Html::encode($this->request->getBodyParam('name'));
        $tour->slug = $tour->id ? $tour->slug : \craft\helpers\ElementHelper::generateSlug($tour->title);
        $tour->description = Html::encode($this->request->getBodyParam('description', ''));
        $tour->enabled = (bool)$this->request->getBodyParam('enabled', true);
        $tour->propagationMethod = $this->request->getBodyParam('propagationMethod', Tour::PROPAGATION_METHOD_NONE);
        $tour->progressPosition = $this->request->getBodyParam('progressPosition', 'bottom');
        $tour->autoplay = (bool)$this->request->getBodyParam('autoplay', false);
        $tour->siteId = $currentSite->id;

        if (!$tour->tourId) {
            $tour->tourId = 'tour_' . \craft\helpers\StringHelper::UUID();
        }

        $steps = $this->request->getBodyParam('steps', []);
        $processedSteps = Boarding::getInstance()->tours->processStepsForSave($tour, $steps);
        $tour->setSteps($processedSteps);

        $tour->userGroupIds = $this->sanitizeUserGroupIds($this->request->getBodyParam('userGroupIds', []));
    }

    /**
     * Save related data (user groups, translations)
     */
    private function saveRelatedData(Tour $tour): void
    {
        // Save user groups
        Boarding::getInstance()->tours->saveTourUserGroups($tour->id, $tour->userGroupIds);

        // Save translations if applicable
        if (in_array($tour->propagationMethod, ['language', 'siteGroup', 'custom'])) {
            $currentSite = SiteHelper::getSiteForRequestAuto($this->request);
            $primarySite = Craft::$app->getSites()->getPrimarySite();
            $steps = $this->request->getBodyParam('steps', []);

            Boarding::getInstance()->tours->saveTranslationsForTour(
                $tour->id,
                $steps,
                $currentSite,
                $primarySite,
                $tour->propagationMethod
            );
        }
    }

    /**
     * Sanitize user group IDs
     */
    private function sanitizeUserGroupIds($userGroupIds): array
    {
        if (!is_array($userGroupIds)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($id) {
            return is_numeric($id) ? (int)$id : null;
        }, $userGroupIds), function ($id) {
            return $id !== null && $id > 0;
        }));
    }
}
