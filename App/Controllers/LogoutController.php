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
            // Perform logout via configured authenticator (this will destroy session on server side)
            $this->app->getAuthenticator()->logout();

            // Explicitly expire the session cookie to ensure clients cannot reuse it.
            if (function_exists('session_get_cookie_params')) {
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

            return (new JsonResponse(['status' => 'ok', 'message' => 'Logged out']))->setStatusCode(200);
        } catch (\Throwable $e) {
            if (defined('App\\Configuration::SHOW_EXCEPTION_DETAILS') && Configuration::SHOW_EXCEPTION_DETAILS) {
                return (new JsonResponse(['status' => 'error', 'message' => $e->getMessage()]))->setStatusCode(500);
            }
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }
}
