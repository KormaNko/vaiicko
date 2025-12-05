<?php

namespace App\Controllers;

use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use Framework\DB\Connection;

// Tento kontrolér spravuje CRUD operácie pre entitu "users" cez JSON API.
// Poskytuje zoznam, detail, vytváranie, aktualizáciu a mazanie používateľov.
class UsersController extends BaseController
{
    // index(): pridá CORS hlavičky a spracuje preflight (OPTIONS), potom deleguje na list()
    public function index(Request $request): Response
    {
        // CORS hlavičky povolené pre frontend (localhost:5173)
        header('Access-Control-Allow-Origin: http://localhost:5173');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        // Preflight odpoveď (prehliadač posiela OPTIONS pred skutočnou požiadavkou)
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        // Štandardne zobrazíme zoznam používateľov
        return $this->list($request);
    }

    // list(): načíta všetkých používateľov z DB a vráti ako JSON pole
    public function list(Request $request): Response
    {
        try {
            $conn = Connection::getInstance();
            // Vyberieme len verejné stĺpce (bez hesla)
            $sql = "SELECT id, firstName, lastName, email, isStudent, created_at FROM `users` ORDER BY id DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute([]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return new JsonResponse(['status' => 'ok', 'data' => $rows]);
        } catch (\Throwable $e) {
            // Pri neočakávanej chybe vrátime 500
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }

    // detail(): získa jedného používateľa podľa id (query param `id`) a vráti detail
    public function detail(Request $request): Response
    {
        $id = $request->get('id');
        if ($id === null || $id === '') {
            // Chýba id v požiadavke
            return (new JsonResponse(['status' => 'error', 'message' => 'Missing id']))->setStatusCode(400);
        }
        try {
            $conn = Connection::getInstance();
            $sql = "SELECT id, firstName, lastName, email, isStudent, created_at FROM `users` WHERE id = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return (new JsonResponse(['status' => 'error', 'message' => 'Not found']))->setStatusCode(404);
            return new JsonResponse(['status' => 'ok', 'data' => $row]);
        } catch (\Throwable $e) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }

    // create(): očakáva POST s JSON telom, validuje polia a vloží nového používateľa
    public function create(Request $request): Response
    {
        if (!$request->isPost()) {
            // Vytváranie musí byť POST
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }

        try {
            // Načítame JSON z tela
            $data = $request->json();
        } catch (\JsonException $e) {
            // Nekorektný JSON
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Invalid JSON']]))->setStatusCode(400);
        }
        if (is_object($data)) $data = (array)$data;
        $firstName = trim((string)($data['firstName'] ?? ''));
        $lastName = trim((string)($data['lastName'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $isStudent = isset($data['isStudent']) ? (int)$data['isStudent'] : 0;

        // Jednoduchá server-side validácia
        $errors = [];
        if ($firstName === '') $errors['firstName'] = 'First name is required';
        if ($lastName === '') $errors['lastName'] = 'Last name is required';
        if ($email === '') $errors['email'] = 'Email is required';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email';
        if ($password === '' || mb_strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters';

        if (!empty($errors)) {
            // Ak sú chyby, vrátime ich a status 400
            return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);
        }

        try {
            $conn = Connection::getInstance();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO `users` (firstName, lastName, email, password, isStudent) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$firstName, $lastName, $email, $hash, $isStudent]);
            $id = $conn->lastInsertId();
            // Vrátime id nového používateľa a status 201
            return (new JsonResponse(['status' => 'ok', 'id' => $id]))->setStatusCode(201);
        } catch (\PDOException $e) {
            // Duplicitný email alebo iné integrity chyby spracujeme tu
            $code = $e->getCode();
            $sqlstate = $e->errorInfo[0] ?? null;
            $errno = $e->errorInfo[1] ?? null;
            if ($sqlstate === '23000' || $errno === 1062) {
                return (new JsonResponse(['status' => 'error', 'errors' => ['email' => 'Email already registered']]))->setStatusCode(400);
            }
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        } catch (\Throwable $e) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }

    // update(): očakáva POST + id v query a JSON telo s voliteľnými poľami na aktualizáciu
    public function update(Request $request): Response
    {
        if (!$request->isPost()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }
        $id = $request->get('id');
        if ($id === null || $id === '') return (new JsonResponse(['status' => 'error', 'message' => 'Missing id']))->setStatusCode(400);

        try {
            $data = $request->json();
        } catch (\JsonException $e) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Invalid JSON']]))->setStatusCode(400);
        }
        if (is_object($data)) $data = (array)$data;

        // Iba tie polia, ktoré sú prítomné v tele, sa budú aktualizovať
        $firstName = isset($data['firstName']) ? trim((string)$data['firstName']) : null;
        $lastName = isset($data['lastName']) ? trim((string)$data['lastName']) : null;
        $email = isset($data['email']) ? trim((string)$data['email']) : null;
        $password = isset($data['password']) ? (string)$data['password'] : null;
        $isStudent = isset($data['isStudent']) ? (int)$data['isStudent'] : null;

        $errors = [];
        if ($firstName !== null && $firstName === '') $errors['firstName'] = 'First name is required';
        if ($lastName !== null && $lastName === '') $errors['lastName'] = 'Last name is required';
        if ($email !== null) {
            if ($email === '') $errors['email'] = 'Email is required';
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email';
        }
        if ($password !== null && $password !== '' && mb_strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters';

        if (!empty($errors)) return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);

        try {
            $conn = Connection::getInstance();

            // Dynamicky zostavíme SET podľa prítomných parametrov
            $sets = [];
            $params = [];
            if ($firstName !== null) { $sets[] = '`firstName` = ?'; $params[] = $firstName; }
            if ($lastName !== null) { $sets[] = '`lastName` = ?'; $params[] = $lastName; }
            if ($email !== null) { $sets[] = '`email` = ?'; $params[] = $email; }
            if ($password !== null && $password !== '') { $sets[] = '`password` = ?'; $params[] = password_hash($password, PASSWORD_DEFAULT); }
            if ($isStudent !== null) { $sets[] = '`isStudent` = ?'; $params[] = $isStudent; }

            if (empty($sets)) {
                // Ak nie sú žiadne polia na aktualizáciu, nič sa nedeje
                return (new JsonResponse(['status' => 'ok', 'message' => 'Nothing to update']))->setStatusCode(200);
            }

            $params[] = $id; // where param
            $sql = "UPDATE `users` SET " . implode(', ', $sets) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                // Žiadne záznamy neboli zmenené - buď neexistuje záznam alebo údaje neboli odlišné
                return (new JsonResponse(['status' => 'error', 'message' => 'Not found or not modified']))->setStatusCode(404);
            }
            // Úspešná aktualizácia
            return (new JsonResponse(['status' => 'ok', 'message' => 'Updated']))->setStatusCode(200);
        } catch (\PDOException $e) {
            $sqlstate = $e->errorInfo[0] ?? null;
            $errno = $e->errorInfo[1] ?? null;
            if ($sqlstate === '23000' || $errno === 1062) {
                // Duplicitný email pri aktualizácii
                return (new JsonResponse(['status' => 'error', 'errors' => ['email' => 'Email already registered']]))->setStatusCode(400);
            }
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        } catch (\Throwable $e) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }

    // delete(): očakáva POST + id v query a vymaže záznam používateľa
    public function delete(Request $request): Response
    {
        if (!$request->isPost()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }
        $id = $request->get('id');
        if ($id === null || $id === '') return (new JsonResponse(['status' => 'error', 'message' => 'Missing id']))->setStatusCode(400);

        try {
            $conn = Connection::getInstance();
            $sql = "DELETE FROM `users` WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) return (new JsonResponse(['status' => 'error', 'message' => 'Not found']))->setStatusCode(404);
            // Úspešne vymazané
            return (new JsonResponse(['status' => 'ok', 'message' => 'Deleted']))->setStatusCode(200);
        } catch (\Throwable $e) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }
}
