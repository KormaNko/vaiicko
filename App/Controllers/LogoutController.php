<?php

namespace App\Controllers;

use App\Configuration;
use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;

class LogoutController extends AppController
{
    public function index(Request $request): Response
    {
        // Use centralized CORS helper
        $this->sendCorsIfNeeded($request);

        // Preflight
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        if ($request->server('REQUEST_METHOD') !== 'POST') {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }

        try {
            //vymaže identity zo session teda server zbudni kto som
            $this->app->getAuthenticator()->logout();

            //iba kontrola kvoli starsej vezii php
            if (function_exists('session_get_cookie_params')) {
                //ziskam cookies a vymazem ich
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'] ?? '/',
                    $params['domain'] ?? '',
                    $params['secure'] ?? false,
                    $params['httponly'] ?? false
                );
            } else {
                setcookie(session_name(), '', time() - 42000, '/');
            }

            return (new JsonResponse([
                'status' => 'ok',
                'message' => 'Logged out'
            ]))->setStatusCode(200);

        } catch (\Throwable $e) {
            return (new JsonResponse([
                'status' => 'error',
                'message' => 'Internal Server Error'
            ]))->setStatusCode(500);
        }
    }
}
