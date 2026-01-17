<?php

namespace App\Controllers;

use App\Configuration;
use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\RedirectResponse;
use Framework\Http\Responses\Response;

/**
 * AppController
 * Shared base controller for application controllers that require authentication.
 */
abstract class AppController extends BaseController
{
    /**
     * Require authenticated user for the current request.
     * - If authenticated -> return null and caller continues.
     * - If not authenticated:
     *   - For AJAX/JSON requests -> return JsonResponse with 401
     *   - For normal browser requests -> return RedirectResponse to login URL
     *
     * @param Request $request
     * @return Response|null
     */
    protected function requireAuth(Request $request): ?Response
    {
        if ($this->user->isLoggedIn()) {
            return null;
        }

        // API / AJAX expecting JSON
        if ($request->isAjax() || $request->wantsJson() || $request->isJson()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Unauthorized']))->setStatusCode(401);
        }

        // Browser request -> redirect to login
        return new RedirectResponse(Configuration::LOGIN_URL);
    }

    /**
     * Small helper to add CORS headers for JSON API endpoints if needed.
     */
    protected function sendCorsIfNeeded(Request $request): void
    {
        $allowed = [
            'http://localhost:5173',
            'http://localhost:3000',
        ];
        $origin = $request->server('HTTP_ORIGIN') ?? '';
        if (in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        }
    }
}
