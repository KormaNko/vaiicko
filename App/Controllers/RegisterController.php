<?php
namespace App\Controllers;

use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use Framework\DB\Connection;

class RegisterController extends BaseController
{
    public function index(Request $request): Response
    {
        header('Access-Control-Allow-Origin: http://localhost:5173');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        return $this->handleRegisterJson($request);
    }

    public function register(Request $request): Response
    {
        return $this->index($request);
    }

    private function handleRegisterJson(Request $request): JsonResponse
    {
        if (!$request->isPost()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }

        try {
            $data = $request->json();
        } catch (\JsonException $e) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Neplatný JSON']]))->setStatusCode(400);
        }

        if (is_object($data)) { $data = (array)$data; }
        if (!is_array($data)) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Prázdne telo požiadavky']]))->setStatusCode(400);
        }

        $firstName = isset($data['firstName']) ? trim((string)$data['firstName']) : '';
        $lastName  = isset($data['lastName'])  ? trim((string)$data['lastName'])  : '';
        $email     = isset($data['email'])     ? trim((string)$data['email'])     : '';
        $password  = isset($data['password'])  ? (string)$data['password']        : '';
        $isStudent = isset($data['isStudent']) ? (int)$data['isStudent']          : 0;

        $errors = [];
        if ($firstName === '') $errors['firstName'] = 'First name is required';
        if ($lastName === '')  $errors['lastName']  = 'Last name is required';
        if ($email === '')     $errors['email']     = 'Email is required';
        elseif (!preg_match('/^\S+@\S+\.\S+$/', $email)) $errors['email'] = 'Invalid email';
        if ($password === '' || mb_strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters';

        if (!empty($errors)) {
            return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);
        }

        try {
            $conn = Connection::getInstance();

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $insertSql = "INSERT INTO `users` (`firstName`, `lastName`, `email`, `password`, `isStudent`)
                          VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insertSql);
            $stmt->execute([$firstName, $lastName, $email, $hash, $isStudent]);

            return (new JsonResponse(['status' => 'ok', 'message' => 'Registration successful']))->setStatusCode(201);
        } catch (\PDOException $e) {
            // SQLSTATE 23000 = integrity constraint violation (duplicate entry)
            if ($e->getCode() === '23000') {
                return (new JsonResponse(['status' => 'error', 'errors' => ['email' => 'Email already registered']]))->setStatusCode(400);
            }
            if (defined('App\\Configuration::SHOW_EXCEPTION_DETAILS') && \App\Configuration::SHOW_EXCEPTION_DETAILS) {
                return (new JsonResponse(['status' => 'error', 'message' => $e->getMessage()]))->setStatusCode(500);
            }
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        } catch (\Throwable $e) {
            if (defined('App\\Configuration::SHOW_EXCEPTION_DETAILS') && \App\Configuration::SHOW_EXCEPTION_DETAILS) {
                return (new JsonResponse(['status' => 'error', 'message' => $e->getMessage()]))->setStatusCode(500);
            }
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }
}
