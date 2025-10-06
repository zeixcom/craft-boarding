<?php

namespace zeix\boarding\handlers;

use Craft;
use Throwable;
use zeix\boarding\exceptions\BoardingException;
use zeix\boarding\exceptions\TourNotFoundException;
use zeix\boarding\exceptions\TourSaveException;
use zeix\boarding\exceptions\TourValidationException;
use zeix\boarding\exceptions\TourAccessException;
use zeix\boarding\exceptions\DatabaseException;
use zeix\boarding\utils\Logger;

/**
 * Centralized error handler for the Boarding plugin
 * 
 * Provides consistent error handling, logging, and user-friendly error messages
 */
class ErrorHandler
{
    /**
     * @var array Statistics tracking
     */
    private static array $stats = [
        'handled_exceptions' => 0,
        'by_category' => [],
        'by_type' => []
    ];

    /**
     * @var array Custom exception handlers
     */
    private static array $customHandlers = [];

    /**
     * Handle any exception with centralized error processing
     * 
     * @param Throwable $exception Exception to handle
     * @param array $context Additional context
     * @return array Error response data
     */
    public static function handle(Throwable $exception, array $context = []): array
    {
        self::$stats['handled_exceptions']++;
        
        if ($exception instanceof BoardingException) {
            return self::handleBoardingException($exception, $context);
        }

        if ($exception instanceof \PDOException || $exception instanceof \yii\db\Exception) {
            return self::handleDatabase(DatabaseException::queryFailed(
                $exception->getMessage(),
                $context,
                $exception
            ), $context);
        }

        return self::handleGenericException($exception, $context);
    }

    /**
     * Handle BoardingException instances
     * 
     * @param BoardingException $exception Boarding-specific exception
     * @param array $context Additional context
     * @return array Error response data
     */
    public static function handleBoardingException(BoardingException $exception, array $context = []): array
    {
        $category = $exception->getCategory();
        self::updateStats($category, get_class($exception));
        
        $fullContext = array_merge($context, $exception->getContext());
        
        if ($exception->shouldLog()) {
            Logger::error($exception->getMessage(), array_merge([
                'category' => $category,
                'userMessage' => $exception->getUserMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ], $fullContext), 'boarding');
        }

        if (isset(self::$customHandlers[$category])) {
            $handler = self::$customHandlers[$category];
            return $handler($exception, $fullContext);
        }

        return match($category) {
            'tour_not_found' => $exception instanceof TourNotFoundException 
                ? self::handleTourNotFound($exception, $fullContext)
                : self::handleGenericBoardingException($exception, $fullContext),
            'tour_save' => $exception instanceof TourSaveException 
                ? self::handleTourSave($exception, $fullContext)
                : self::handleGenericBoardingException($exception, $fullContext),
            'tour_validation' => $exception instanceof TourValidationException 
                ? self::handleTourValidation($exception, $fullContext)
                : self::handleGenericBoardingException($exception, $fullContext),
            'tour_access' => $exception instanceof TourAccessException 
                ? self::handleTourAccess($exception, $fullContext)
                : self::handleGenericBoardingException($exception, $fullContext),
            'database' => $exception instanceof DatabaseException 
                ? self::handleDatabase($exception, $fullContext)
                : self::handleGenericBoardingException($exception, $fullContext),
            default => self::handleGenericBoardingException($exception, $fullContext)
        };
    }

    /**
     * Handle tour not found exceptions
     * 
     * @param TourNotFoundException $exception
     * @param array $context
     * @return array
     */
    public static function handleTourNotFound(TourNotFoundException $exception, array $context = []): array
    {
        return [
            'success' => false,
            'error' => $exception->getUserMessage(),
            'code' => 404,
            'category' => 'tour_not_found',
            'context' => Craft::$app->getConfig()->getGeneral()->devMode ? $context : []
        ];
    }

    /**
     * Handle tour save exceptions
     * 
     * @param TourSaveException $exception
     * @param array $context
     * @return array
     */
    public static function handleTourSave(TourSaveException $exception, array $context = []): array
    {
        return [
            'success' => false,
            'error' => $exception->getUserMessage(),
            'code' => 500,
            'category' => 'tour_save',
            'context' => Craft::$app->getConfig()->getGeneral()->devMode ? $context : []
        ];
    }

    /**
     * Handle tour validation exceptions
     * 
     * @param TourValidationException $exception
     * @param array $context
     * @return array
     */
    public static function handleTourValidation(TourValidationException $exception, array $context = []): array
    {
        return [
            'success' => false,
            'error' => $exception->getUserMessage(),
            'code' => 422,
            'category' => 'validation_error',
            'validationErrors' => $exception->getValidationErrors(),
            'context' => Craft::$app->getConfig()->getGeneral()->devMode ? $context : []
        ];
    }

    /**
     * Handle tour access exceptions
     * 
     * @param TourAccessException $exception
     * @param array $context
     * @return array
     */
    public static function handleTourAccess(TourAccessException $exception, array $context = []): array
    {
        return [
            'success' => false,
            'error' => $exception->getUserMessage(),
            'code' => 403,
            'category' => 'access_denied',
            'context' => Craft::$app->getConfig()->getGeneral()->devMode ? $context : []
        ];
    }

    /**
     * Handle database exceptions
     * 
     * @param DatabaseException $exception
     * @param array $context
     * @return array
     */
    public static function handleDatabase(DatabaseException $exception, array $context = []): array
    {
        return [
            'success' => false,
            'error' => $exception->getUserMessage(),
            'code' => 500,
            'category' => 'database_error',
            'context' => Craft::$app->getConfig()->getGeneral()->devMode ? $context : []
        ];
    }

    /**
     * Handle generic BoardingException instances
     * 
     * @param BoardingException $exception
     * @param array $context
     * @return array
     */
    public static function handleGenericBoardingException(BoardingException $exception, array $context = []): array
    {
        return [
            'success' => false,
            'error' => $exception->getUserMessage(),
            'code' => $exception->getCode() ?: 500,
            'category' => $exception->getCategory(),
            'context' => Craft::$app->getConfig()->getGeneral()->devMode ? $context : []
        ];
    }

    /**
     * Handle generic (non-Boarding) exceptions
     * 
     * @param Throwable $exception
     * @param array $context
     * @return array
     */
    public static function handleGenericException(Throwable $exception, array $context = []): array
    {
        self::updateStats('generic', get_class($exception));
        
        Logger::error($exception->getMessage(), array_merge([
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ], $context), 'boarding');

        return [
            'success' => false,
            'error' => 'An unexpected error occurred. Please try again.',
            'code' => 500,
            'category' => 'unexpected_error',
            'context' => Craft::$app->getConfig()->getGeneral()->devMode ? array_merge($context, [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]) : []
        ];
    }

    /**
     * Wrap a callable with error handling
     * 
     * @param callable $callback Callback to execute
     * @param array $context Context for error handling
     * @return mixed Result of callback or error array
     */
    public static function wrap(callable $callback, array $context = [])
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            return self::handle($e, $context);
        }
    }

    /**
     * Execute a callable and return success/error response
     * 
     * @param callable $callback Callback to execute
     * @param array $context Context for error handling
     * @return array Always returns array with success key
     */
    public static function tryExecute(callable $callback, array $context = []): array
    {
        try {
            $result = $callback();
            
            if (is_array($result) && array_key_exists('success', $result)) {
                return $result;
            }
            
            return [
                'success' => true,
                'data' => $result
            ];
        } catch (Throwable $e) {
            return self::handle($e, $context);
        }
    }

    /**
     * Register a custom handler for a specific exception category
     * 
     * @param string $category Exception category
     * @param callable $handler Handler function
     */
    public static function registerHandler(string $category, callable $handler): void
    {
        self::$customHandlers[$category] = $handler;
    }

    /**
     * Reset error handling statistics
     */
    public static function resetStats(): void
    {
        self::$stats = [
            'handled_exceptions' => 0,
            'by_category' => [],
            'by_type' => []
        ];
    }

    /**
     * Update statistics tracking
     * 
     * @param string $category Exception category
     * @param string $type Exception class name
     */
    private static function updateStats(string $category, string $type): void
    {
        if (!isset(self::$stats['by_category'][$category])) {
            self::$stats['by_category'][$category] = 0;
        }
        self::$stats['by_category'][$category]++;

        if (!isset(self::$stats['by_type'][$type])) {
            self::$stats['by_type'][$type] = 0;
        }
        self::$stats['by_type'][$type]++;
    }

    /**
     * Create a specific exception type quickly
     * 
     * @param string $type Exception type (tour_not_found, tour_save, etc.)
     * @param string $message Error message
     * @param array $context Context data
     * @return BoardingException
     */
    public static function createException(string $type, string $message, array $context = []): BoardingException
    {
        return match($type) {
            'tour_not_found' => new TourNotFoundException($context['tourId'] ?? 'unknown', $context),
            'tour_save' => new TourSaveException($message, $context),
            'tour_validation' => new TourValidationException([$message], $context),
            'tour_access' => new TourAccessException($message, $context),
            'database' => new DatabaseException($message, $context),
            default => new BoardingException($message, 0, null, $context)
        };
    }
}