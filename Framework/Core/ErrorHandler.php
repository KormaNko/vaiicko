<?php

namespace Framework\Core;

use App\Configuration;
use Framework\Http\HttpException;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use Framework\Http\Responses\ViewResponse;

/**
 * Class ErrorHandler
 *
 * This class implements the IHandleError interface and is responsible for managing error handling within the
 * application. It processes exceptions thrown during the application's execution and generates appropriate responses
 * based on the type of request and the application's configuration.
 */
class ErrorHandler implements IHandleError
{
    public function handleError(App $app, HttpException $exception): Response
    {
        // log the exception to a file for easier debugging
        try {
            $this->logException($exception);
        } catch (\Throwable $e) {
            // best effort logging only; never rethrow from the handler
        }

        // response error in JSON only if client wants to
        if ($app->getRequest()->wantsJson()) {
            function getExceptionStack(\Throwable $throwable): array
            {
                $stack = [];
                while ($throwable != null) {
                    $entry = [];
                    $entry['message'] = $throwable->getMessage();
                    $entry['trace'] = $throwable->getTraceAsString();
                    $stack[] = $entry;

                    $throwable = $throwable->getPrevious();
                }

                return $stack;
            }

            $data = [
                'code' => $exception->getCode(),
                'status' => $exception->getMessage(),
            ];

            if (Configuration::SHOW_EXCEPTION_DETAILS) {
                $data['stack'] = getExceptionStack($exception);
            }

            return (new JsonResponse($data))
                ->setStatusCode($exception->getCode());
        } else {
            $data = [
                "exception" => $exception,
                "showDetail" => Configuration::SHOW_EXCEPTION_DETAILS
            ];

            return (new ViewResponse($app, "_Error/error", $data))
                ->setStatusCode($exception->getCode());
        }
    }

    /**
     * Append exception details to a log file under storage/logs/errors.log (best-effort).
     */
    private function logException(HttpException $exception): void
    {
        $root = dirname(__DIR__, 2); // points to project root (App/ .. we'll go up)
        $logDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        $logFile = $logDir . DIRECTORY_SEPARATOR . 'errors.log';

        $now = date('Y-m-d H:i:s');
        $lines = [];
        $lines[] = "[{$now}] Exception: code=" . $exception->getCode() . " message=" . $exception->getMessage();

        $t = $exception;
        $i = 0;
        while ($t !== null) {
            $lines[] = "--- Throwable #{$i} " . get_class($t) . " ---";
            $lines[] = "Message: " . $t->getMessage();
            $lines[] = "File: " . $t->getFile() . ":" . $t->getLine();
            $lines[] = "Trace:";
            $lines[] = $t->getTraceAsString();
            $t = $t->getPrevious();
            $i++;
        }

        $lines[] = "\n";

        @file_put_contents($logFile, implode(PHP_EOL, $lines), FILE_APPEND | LOCK_EX);
    }
}
