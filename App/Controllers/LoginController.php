<?php


namespace App\Controllers;

use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;

class LoginController extends BaseController
{
    // Framework requires an index action that accepts Request and returns a Response
    public function index(Request $request): Response
    {
        // Keep CORS headers here (headers don't produce output so Response->send is safe)
        header('Access-Control-Allow-Origin: http://localhost:5173');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        // Handle preflight
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        // Delegate to a dedicated handler that returns a JsonResponse
        return $this->handleLoginJson($request);
    }

    // Allow router to call either ?a=index or ?a=login
    public function login(Request $request): Response
    {
        return $this->index($request);
    }

    // Keep the actual login logic in a private method that returns JsonResponse
    private function handleLoginJson(Request $request): JsonResponse
    {
        // Only POST is allowed for login
        if (!$request->isPost()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }

        // Turn on errors in development (guard or remove in production)
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);

        // Read JSON body safely
        try {
            $data = $request->json();
        } catch (\JsonException $e) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Neplatný JSON']]))->setStatusCode(400);
        }

        // Convert object result (stdClass) to associative array so we can use array access
        if (is_object($data)) {
            $data = (array)$data;
        }

        if (!is_array($data) || count($data) === 0) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Prázdne telo požiadavky']]))->setStatusCode(400);
        }

        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        $password = isset($data['password']) ? (string)$data['password'] : '';

        // Server-side validation
        $errors = [];

        if ($email === '') {
            $errors['email'] = 'Email je povinný';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Neplatný email';
        }

        if ($password === '') {
            $errors['password'] = 'Heslo je povinné';
        } elseif (mb_strlen($password) < 8) {
            $errors['password'] = 'Heslo musí mať aspoň 8 znakov';
        }

        if (!empty($errors)) {
            return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);
        }

        // TODO: Replace this placeholder with real authentication (DB lookup, password_verify, sessions)
        return new JsonResponse(['status' => 'ok', 'message' => 'Serverová kontrola prešla']);
    }
}
