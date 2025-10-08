<?php

namespace zeix\boarding\controllers;

use Craft;
use craft\helpers\Html;
use craft\web\Controller;
use yii\web\Response;
use zeix\boarding\Boarding;
use zeix\boarding\helpers\SiteHelper;
use zeix\boarding\utils\Logger;
use zeix\boarding\handlers\ErrorHandler;

class ToursController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {

        return parent::beforeAction($action);
    }

    /**
     * Tour index page
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-boarding');

        $site = SiteHelper::getSiteForRequestAuto($this->request);
        $tourIndexData = Boarding::getInstance()->tours->getToursForIndex($site);

        return $this->renderTemplate('boarding/tours/index', $tourIndexData);
    }

    /**
     * Tour edit page
     */
    public function actionEdit(?string $id = null): Response
    {
        if ($id) {
            $this->requirePermission('boarding:edittours');
        } else {
            $this->requirePermission('boarding:createtours');
        }

        $site = SiteHelper::getSiteForRequestAuto($this->request);
        $tourEditData = Boarding::getInstance()->tours->getTourForEdit($id ? (int)$id : null, $site);

        return $this->renderTemplate('boarding/tours/edit', $tourEditData);
    }

    /**
     * Get all tours including completed ones.
     */
    public function actionGetAllTours(): Response
    {
        $result = ErrorHandler::tryExecute(function () {
            $tours = Boarding::getInstance()->tours->getAllTours();
            return [
                'success' => true,
                'tours' => $tours
            ];
        }, [
            'action' => 'getAllTours'
        ]);

        return $this->asJson($result);
    }

    /**
     * Set a tour as completed for the current user.
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

        $user = Craft::$app->getUser()->getIdentity();

        $result = ErrorHandler::tryExecute(function () use ($tourId, $user) {
            $success = Boarding::getInstance()->tours->markTourCompleted($tourId, $user->id);

            if (!$success) {
                throw new \Exception(Craft::t('boarding', 'Couldn\'t mark tour as completed.'));
            }

            return [
                'success' => true,
                'message' => Craft::t('boarding', 'Tour marked as completed')
            ];
        }, [
            'action' => 'markCompleted',
            'tourId' => $tourId,
            'userId' => $user->id ?? null
        ]);

        return $this->asJson($result);
    }

    /**
     * Delete a tour.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('boarding:deletetours');

        $id = $this->request->getRequiredBodyParam('id');
        $isJsonRequest = $this->request->getAcceptsJson();

        $result = ErrorHandler::tryExecute(function () use ($id) {
            if (!Boarding::getInstance()->tours->deleteTour($id)) {
                throw new \Exception(Craft::t('boarding', 'Couldn\'t delete tour.'));
            }

            return [
                'success' => true,
                'message' => Craft::t('boarding', 'Tour deleted.')
            ];
        }, [
            'action' => 'deleteTour',
            'tourId' => $id
        ]);

        if ($isJsonRequest) {
            return $this->asJson($result);
        }

        if ($result['success']) {
            Craft::$app->getSession()->setNotice($result['message']);
        } else {
            Craft::$app->getSession()->setError($result['error']);
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Save a tour.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $id = $this->request->getBodyParam('id');
        if ($id) {
            $this->requirePermission('boarding:edittours');
        } else {
            $this->requirePermission('boarding:createtours');

            if (!Boarding::getInstance()->is(Boarding::EDITION_STANDARD)) {
                $existingTourCount = count(Boarding::getInstance()->tours->getAllTours());
                if ($existingTourCount >= Boarding::LITE_TOUR_LIMIT) {
                    Craft::$app->getSession()->setError(
                        Craft::t('boarding', 'You have reached the maximum of {limit} tours allowed in Boarding Lite. Upgrade to Boarding Standard for unlimited tours.', [
                            'limit' => Boarding::LITE_TOUR_LIMIT
                        ])
                    );
                    return $this->redirect('boarding/tours');
                }
            }
        }

        $allSites = Craft::$app->getSites()->getAllSites();
        $isMultiSite = count($allSites) > 1;
        $currentSite = SiteHelper::getSiteForRequestAuto($this->request);

        $translatable = $this->request->getBodyParam('translatable', false);
        if ($isMultiSite && $translatable && !Boarding::getInstance()->is(Boarding::EDITION_STANDARD)) {
            throw new \yii\web\ForbiddenHttpException(Craft::t('boarding', 'Multi-site translation features require Boarding Standard Edition.'));
        }

        $name = Html::encode($this->request->getBodyParam('name'));
        $description = Html::encode($this->request->getBodyParam('description', ''));
        $enabled = (bool)$this->request->getBodyParam('enabled', true);
        $siteEnabled = $this->request->getBodyParam('siteEnabled', []);
        $userGroupIds = $this->sanitizeUserGroupIds($this->request->getBodyParam('userGroupIds', []));
        $steps = $this->request->getBodyParam('steps', []);
        $progressPosition = $this->validateProgressPosition($this->request->getBodyParam('progressPosition', 'bottom'));

        $processedSteps = Boarding::getInstance()->tours->processStepsData($steps);

        $tourEnabled = $enabled;

        if ($isMultiSite) {
            $primarySite = Craft::$app->getSites()->getPrimarySite();

            if ($currentSite->id == $primarySite->id) {
                if (!empty($siteEnabled) && isset($siteEnabled[$currentSite->id])) {
                    $tourEnabled = (bool)$siteEnabled[$currentSite->id];
                } else {
                    $tourEnabled = (bool)$enabled;
                }
            } else {
                if ($id) {
                    $existingTour = Boarding::getInstance()->tours->getTourById((int)$id);
                    if ($existingTour) {
                        $tourEnabled = (bool)$existingTour['enabled'];
                    } else {
                        $tourEnabled = true;
                    }
                } else {
                    $tourEnabled = true;
                }
            }
        }

        $tourData = [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'enabled' => $tourEnabled,
            'translatable' => (bool)$translatable && $isMultiSite,
            'userGroupIds' => $userGroupIds,
            'steps' => $processedSteps,
            'progressPosition' => $progressPosition,
            'siteId' => $currentSite->id,
            'siteEnabled' => $siteEnabled
        ];

        $success = Boarding::getInstance()->tours->saveTour($tourData);

        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('boarding', 'Tour saved.'));
            return $this->redirectToPostedUrl();
        } else {
            Craft::$app->getSession()->setError(Craft::t('boarding', 'Couldn\'t save tour.'));
            return null;
        }
    }

    /**
     * Duplicate a tour.
     */
    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('boarding:createtours');

        $tourId = (int)$this->request->getRequiredBodyParam('id');
        $siteHandle = $this->request->getParam('site');

        if ($siteHandle !== null && !is_string($siteHandle)) {
            throw new \yii\web\BadRequestHttpException('Invalid site handle');
        }

        try {
            $tour = Boarding::getInstance()->tours->getTourById((int)$tourId);
            if (!$tour) {
                throw new \Exception('Tour not found');
            }

            $newTourId = Boarding::getInstance()->tours->duplicateTour($tour['id']);

            if ($newTourId) {
                if ($this->request->getAcceptsJson()) {
                    return $this->asJson([
                        'success' => true,
                        'newTourId' => $newTourId
                    ]);
                }

                Craft::$app->getSession()->setNotice(Craft::t('boarding', 'Tour duplicated.'));

                if ($siteHandle) {
                    return $this->redirect("boarding/tours?site={$siteHandle}");
                }

                return $this->redirectToPostedUrl();
            }

            throw new \Exception('Failed to duplicate tour');
        } catch (\Exception $e) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }

            Craft::$app->getSession()->setError(Craft::t('boarding', 'Couldn\'t duplicate tour: {error}', [
                'error' => $e->getMessage()
            ]));

            if ($siteHandle) {
                return $this->redirect("boarding/tours?site={$siteHandle}");
            }

            return $this->redirectToPostedUrl();
        }
    }

    /**
     * Get tours for the current user.
     * 
     * This method provides tours filtered for the current user, taking into account
     * user groups and completion status.
     */
    public function actionGetToursForCurrentUser(): Response
    {
        $user = Craft::$app->user->getIdentity();
        if (!$user) {
            return $this->asJson([
                'success' => false,
                'error' => 'User not authenticated'
            ]);
        }

        try {
            $tours = Boarding::getInstance()->tours->getToursForCurrentUser();

            return $this->asJson([
                'success' => true,
                'tours' => $tours
            ]);
        } catch (\Exception $e) {
            Logger::error('Error getting tours for current user', [
                'userId' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validate progress position value.
     *
     * @param mixed $position Position value from request
     * @return string Valid progress position
     */
    private function validateProgressPosition($position): string
    {
        $validPositions = ['off', 'top', 'bottom', 'header', 'footer'];

        if (!is_string($position) || !in_array($position, $validPositions, true)) {
            return 'bottom';
        }

        return $position;
    }

    /**
     * Sanitize user group IDs array.
     *
     * @param mixed $userGroupIds User group IDs from request
     * @return array Sanitized array of integers
     */
    private function sanitizeUserGroupIds($userGroupIds): array
    {
        if (!is_array($userGroupIds)) {
            return [];
        }

        return array_values(array_filter(array_map(function($id) {
            return is_numeric($id) ? (int)$id : null;
        }, $userGroupIds), function($id) {
            return $id !== null && $id > 0;
        }));
    }
}
