<?php

namespace App\Controllers;

use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use Framework\DB\Connection;

class UsersController extends AppController
{

    /**
     * index(Request): vstupná metóda pre endpoint users
     * - Pridáva CORS hlavičky (pre frontend počas vývoja).
     * - Spracuje OPTIONS preflight (rýchla odpoveď) a následne deleguje na list().
     *
     * Dôležité: hlavičky sú umiestnené tu, pretože JSON endpointy sa volajú z iného origin-u počas vývoja.
     */
    public function index(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);

        // Ak prehliadač posiela preflight OPTIONS, vrátime jednoduchú odpoveď 200 OK.
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        // Štandardne zobraziť zoznam používateľov
        return $this->list($request);
    }

    /**
     * list(Request): načíta zoznam všetkých používateľov
     * - SELECT vyberá len bezpečné veřejné stĺpce (bez hesla).
     * - Výsledkom je JSON s kľúčom 'data' obsahujúcim pole záznamov.
     * - Pri chybe v DB vrátime 500.
     */
    public function list(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        try {
            $conn = Connection::getInstance();
            // Vyberáme len verejné stĺpce, heslo nikdy nevraciame
            $sql = "SELECT id, firstName, lastName, email, isStudent, created_at FROM `users` ORDER BY id DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute([]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            // Vraciame pole riadkov (môže byť aj prázdne)
            return new JsonResponse(['status' => 'ok', 'data' => $rows]);
        } catch (\Throwable $e) {
            // Interná chyba: neodhaľujeme detaily (okrem dev módu, ak je zapnuté SHOW_EXCEPTION_DETAILS inde)
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }

    /**
     * detail(Request): získa detail jedného používateľa podľa query param `id`
     * - Očakáva ?id=123 v URL (Request::get('id')).
     * - Ak chýba id, vrátime 400 Bad Request.
     * - Ak záznam neexistuje, vrátime 404 Not Found.
     */
    public function detail(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $id = $request->get('id');
        if ($id === null || $id === '') {
            // Chýba povinný parameter id
            return (new JsonResponse(['status' => 'error', 'message' => 'Missing id']))->setStatusCode(400);
        }
        try {
            $conn = Connection::getInstance();
            $sql = "SELECT id, firstName, lastName, email, isStudent, created_at FROM `users` WHERE id = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            // Execute s parametrom zabezpečí správne ošetrenie hodnoty
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                // Ak neexistuje, jasne to povieme s 404
                return (new JsonResponse(['status' => 'error', 'message' => 'Not found']))->setStatusCode(404);
            }
            // Úspech: vrátime detail záznamu
            return new JsonResponse(['status' => 'ok', 'data' => $row]);
        } catch (\Throwable $e) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }

    /**
     * create(Request): vytvorí nového používateľa
     * - Očakáva POST s JSON telom obsahujúcim polia firstName, lastName, email, password, isStudent (voliteľné).
     * - Validácia: všetky potrebné polia sú kontrolované server-side.
     * - Heslo sa hash-uje cez password_hash pred uložením.
     * - Pri duplicitnom emaile vrátime 400 s chybou pre pole 'email'.
     */
    public function create(Request $request): Response
    {
        // registration is public but still needs CORS and OPTIONS handling
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        if (!$request->isPost()) {
            // Metóda musí byť POST
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }

        try {
            // Načítame a dekódujeme JSON z tela požiadavky
            $data = $request->json();
        } catch (\JsonException $e) {
            // Neplatný JSON -> 400
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Invalid JSON']]))->setStatusCode(400);
        }
        if (is_object($data)) $data = (array)$data;

        // Bezpečne konvertujeme a trimujeme vstupy
        $firstName = trim((string)($data['firstName'] ?? ''));
        $lastName = trim((string)($data['lastName'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $isStudent = isset($data['isStudent']) ? (int)$data['isStudent'] : 0;

        // Jednoduchá server-side validácia; návrh: rozšíriť podľa požiadaviek (regex, dĺžky, atď.)
        $errors = [];
        if ($firstName === '') $errors['firstName'] = 'First name is required';
        if ($lastName === '') $errors['lastName'] = 'Last name is required';
        if ($email === '') $errors['email'] = 'Email is required';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email';
        if ($password === '' || mb_strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters';

        if (!empty($errors)) {
            // Vrátime štruktúrované chyby pre frontend
            return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);
        }

        try {
            $conn = Connection::getInstance();
            // Hashujeme heslo pred uložením
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO `users` (firstName, lastName, email, password, isStudent) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$firstName, $lastName, $email, $hash, $isStudent]);
            $id = $conn->lastInsertId();
            // Úspešne vytvorené -> vrátime ID a status 201
            return (new JsonResponse(['status' => 'ok', 'id' => $id]))->setStatusCode(201);
        } catch (\PDOException $e) {
            // Kontrolujeme SQLSTATE alebo errno pre identifikovanie duplicitného záznamu
            $sqlstate = $e->errorInfo[0] ?? null;
            $errno = $e->errorInfo[1] ?? null;
            if ($sqlstate === '23000' || $errno === 1062) {
                // Duplicitný email: vrátime user-friendly chybu pre pole 'email'
                return (new JsonResponse(['status' => 'error', 'errors' => ['email' => 'Email already registered']]))->setStatusCode(400);
            }
            // Iná databázová chyba -> 500
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        } catch (\Throwable $e) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }

    /**
     * update(Request): aktualizuje existujúceho používateľa
     * - Očakáva POST a query param `id` (napr. ?id=123) a JSON telo s voliteľnými poľami.
     * - Dynamicky budujeme SET podľa polí prítomných v JSON.
     * - Ak nie sú žiadne polia na aktualizáciu, vrátime 200 s "Nothing to update".
     * - Ak rowCount() === 0 po UPDATE, môže to znamenať A) záznam s id neexistuje alebo B) údaje boli rovnaké
     *   (žiadna zmena). Momentálne to vracia 404 s textom 'Not found or not modified' — podľa preferencie frontendu
     *   to môžeš zmeniť, aby sa rozlišovalo medzi 404 a 304/200.
     */
    public function update(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        $resp = $this->requireAuth($request);
        if ($resp) return $resp;
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

        // Server-side validácia len pre poskytnuté polia
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
                // Žiadne polia na aktualizáciu -> nič sa nemení
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

    /**
     * delete(Request): vymaže používateľa podľa id
     * - Očakáva POST + query param `id`.
     * - Ak záznam neexistuje, vráti 404.
     */
    public function delete(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

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
