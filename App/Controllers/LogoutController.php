<?php

namespace App\Controllers;

use App\Configuration;
use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;

/**
 * LogoutController
 *
 * Provides a simple JSON endpoint to log out the current user (destroys session).
 * Designed to be called from a SPA (CORS + preflight handled).
 */
class LogoutController extends BaseController
{
    public function index(Request $request): Response
    {
        // Allowed origins - adjust to your frontend origin(s)
        $allowedOrigins = [
            'http://localhost:5173',
            'http://localhost:3000',
        ];

        $origin = $request->server('HTTP_ORIGIN') ?? '';
        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');

        // Preflight
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        // Only allow POST for logout
        if ($request->server('REQUEST_METHOD') !== 'POST') {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }

        try {
            // Perform logout via configured authenticator (this will destroy session on server side)
            $this->app->getAuthenticator()->logout();

            // Explicitly expire the session cookie to ensure clients cannot reuse it.
            if (function_exists('session_get_cookie_params')) {
                $params = session_get_cookie_params();
                // Ensure we call setcookie with the same params (domain/path/secure/httponly)
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
                // Fallback: clear session cookie by name
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
