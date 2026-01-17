<?php


namespace App\Controllers;

use App\Auth\DbIdentity;
use App\Configuration;
use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use Framework\DB\Connection;

class LoginController extends AppController
{
    public function index(Request $request): Response
    {
        // Use centralized CORS helper
        $this->sendCorsIfNeeded($request);

        // Preflight handling
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        // In standard case run auth logic
        return $this->handleLoginJson($request);
    }

    public function login(Request $request): Response
    {
        return $this->index($request);
    }

    private function handleLoginJson(Request $request): JsonResponse
    {
        if (!$request->isPost()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }

        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);

        try {
            $data = $request->json();
        } catch (\JsonException $e) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Neplatný JSON']]))->setStatusCode(400);
        }

        if (is_object($data)) {
            $data = (array)$data;
        }

        if (!is_array($data) || count($data) === 0) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Prázdne telo požiadavky']]))->setStatusCode(400);
        }

        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        $password = isset($data['password']) ? (string)$data['password'] : '';

        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Email je povinný';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Neplatný email';
        }
        if ($password === '') {
            $errors['password'] = 'Heslo je povinné';
        }

        if (!empty($errors)) {
            return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);
        }

        try {
            $conn = Connection::getInstance();
            $sql = "SELECT id, firstName, lastName, email, password FROM `users` WHERE `email` = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$email]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Neplatné prihlasovacie údaje']))->setStatusCode(401);
            }

            if (!password_verify($password, $user['password'])) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Neplatné prihlasovacie údaje']))->setStatusCode(401);
            }

            if (isset($user['email_verified_at']) && $user['email_verified_at'] === null) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Email nie je overený']))->setStatusCode(403);
            }

            $session = $this->app->getSession();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            $identity = new DbIdentity((int)$user['id'], $user['firstName'] ?? '', $user['lastName'] ?? '', $user['email'] ?? '');
            $session->set(Configuration::IDENTITY_SESSION_KEY, $identity);

            return (new JsonResponse(['status' => 'ok', 'message' => 'Prihlásenie úspešné', 'name' => $identity->getName()] ))->setStatusCode(200);
        } catch (\Throwable $e) {
            if (defined('App\\Configuration::SHOW_EXCEPTION_DETAILS') && \App\Configuration::SHOW_EXCEPTION_DETAILS) {
                return (new JsonResponse(['status' => 'error', 'message' => $e->getMessage()]))->setStatusCode(500);
            }
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }
}
