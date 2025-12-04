<?php


namespace App\Controllers;

use App\Auth\DbIdentity;
use App\Configuration;
use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use Framework\DB\Connection;

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

        // Only require that a password is present for login; do not enforce length here so existing accounts with
        // shorter passwords can still authenticate. Password length should be enforced at registration/change time.
        if ($password === '') {
            $errors['password'] = 'Heslo je povinné';
        }

        if (!empty($errors)) {
            return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);
        }

        // Authenticate against DB
        try {
            $conn = Connection::getInstance();
            // Note: do not select email_verified_at if it doesn't exist in the schema
            $sql = "SELECT id, firstName, lastName, email, password FROM `users` WHERE `email` = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$email]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user) {
                // don't reveal whether email exists
                return (new JsonResponse(['status' => 'error', 'message' => 'Neplatné prihlasovacie údaje']))->setStatusCode(401);
            }

            // verify password
            if (!password_verify($password, $user['password'])) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Neplatné prihlasovacie údaje']))->setStatusCode(401);
            }

            // optional: if the DB has an email_verified_at column and it's null, the account isn't verified
            if (isset($user['email_verified_at']) && $user['email_verified_at'] === null) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Email nie je overený']))->setStatusCode(403);
            }

            // ensure session is started and prevent session fixation
            $session = $this->app->getSession();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            // create identity and store in session
            $identity = new DbIdentity((int)$user['id'], $user['firstName'] ?? '', $user['lastName'] ?? '', $user['email'] ?? '');

            // store identity in session under framework key
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
