<?php

namespace zeix\boarding\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use zeix\boarding\Boarding;
use zeix\boarding\controllers\traits\RequiresProEdition;

class ExportController extends Controller
{
    use RequiresProEdition;

    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;


    public function actionExportTour(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-boarding');
        $this->requireProEdition('Import/Export features');

        $tourId = $this->request->getRequiredBodyParam('id');
        try {
            $tour = Boarding::getInstance()->tours->getTourById($tourId);
            if (!$tour) {
                throw new \Exception('Tour not found');
            }
            $exportData = Boarding::getInstance()->export->exportTours([$tour]);
            $filename = Boarding::getInstance()->export->generateTourFilename($tour);
            $this->response->headers->set('Content-Type', 'application/json');
            $this->response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
            return $this->asJson($exportData);
        } catch (\Exception $e) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            Craft::$app->getSession()->setError(Craft::t('boarding', 'Couldn\'t export tour: {error}', [
                'error' => $e->getMessage()
            ]));
            return $this->redirectToPostedUrl();
        }
    }

    public function actionExportAllTours(): Response
    {
        $this->requirePermission('accessPlugin-boarding');
        $this->requireProEdition('Import/Export features');

        try {
            $tours = Boarding::getInstance()->tours->getAllTours();
            if (empty($tours)) {
                throw new \Exception('No tours to export');
            }
            $exportData = Boarding::getInstance()->export->exportTours($tours);
            $filename = Boarding::getInstance()->export->generateAllToursFilename();
            $this->response->headers->set('Content-Type', 'application/json');
            $this->response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
            return $this->asJson($exportData);
        } catch (\Exception $e) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            Craft::$app->getSession()->setError(Craft::t('boarding', 'Couldn\'t export tours: {error}', [
                'error' => $e->getMessage()
            ]));
            return $this->redirectToPostedUrl();
        }
    }
}
