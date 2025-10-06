<?php

namespace zeix\boarding\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use zeix\boarding\Boarding;
use zeix\boarding\config\ImportConfig;
use zeix\boarding\controllers\traits\RequiresProEdition;

class ImportController extends Controller
{
    use RequiresProEdition;

    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function actionImport(): Response
    {
        $this->requirePermission('boarding:createtours');
        $this->requireProEdition('Import/Export features');

        return $this->renderTemplate('boarding/tours/import', [
            'title' => Craft::t('boarding', 'Import Tours')
        ]);
    }

    public function actionImportTours(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('boarding:createtours');
        $this->requireProEdition('Import/Export features');

        try {
            $uploadedFile = \yii\web\UploadedFile::getInstanceByName('importFile');
            if (!$uploadedFile) {
                throw new \Exception(Craft::t('boarding', 'No file uploaded'));
            }

            if (!in_array($uploadedFile->extension, ImportConfig::ALLOWED_EXTENSIONS)) {
                throw new \Exception(Craft::t('boarding', 'Invalid file type. Please upload a JSON file.'));
            }

            if ($uploadedFile->size > ImportConfig::MAX_FILE_SIZE_BYTES) {
                throw new \Exception(Craft::t('boarding', 'File too large. Maximum size is {size}MB.', [
                    'size' => ImportConfig::MAX_FILE_SIZE_MB
                ]));
            }

            $mimeType = mime_content_type($uploadedFile->tempName);
            if (!in_array($mimeType, ImportConfig::ALLOWED_MIME_TYPES)) {
                throw new \Exception(Craft::t('boarding', 'Invalid file MIME type. Expected JSON file but got {type}.', [
                    'type' => $mimeType
                ]));
            }

            $jsonContent = file_get_contents($uploadedFile->tempName);
            if ($jsonContent === false) {
                throw new \Exception(Craft::t('boarding', 'Could not read uploaded file'));
            }

            $importData = json_decode($jsonContent, true, ImportConfig::MAX_JSON_DEPTH);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception(Craft::t('boarding', 'Invalid JSON format: {error}', [
                    'error' => json_last_error_msg()
                ]));
            }
            
            $validationErrors = Boarding::getInstance()->import->validateImportData($importData);
            if (!empty($validationErrors)) {
                throw new \Exception(implode(' ', $validationErrors));
            }

            $exportData = $importData['boardingExport'];
            $tours = $exportData['tours'] ?? [];

            $results = Boarding::getInstance()->import->processToursImport($tours);
            $message = Boarding::getInstance()->import->buildDetailedImportMessage($results);
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => true,
                    'results' => $results,
                    'message' => $message
                ]);
            }
            Craft::$app->getSession()->setNotice($message);
            return $this->redirect('boarding/tours');
        } catch (\Exception $e) {
            $errorMessage = Craft::t('boarding', 'Import failed: {error}', [
                'error' => $e->getMessage()
            ]);
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'error' => $errorMessage
                ]);
            }
            Craft::$app->getSession()->setError($errorMessage);
            return $this->redirect('boarding/tours/import');
        }
    }
}
