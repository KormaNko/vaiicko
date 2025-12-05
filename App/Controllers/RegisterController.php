<?php
namespace App\Controllers;

use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use Framework\DB\Connection;

// Tento kontrolér spracováva registráciu používateľov cez JSON API.
// Má endpoint pre preflight/CORS a hlavnú metódu, ktorá validuje vstup a vloží užívateľa do DB.
class RegisterController extends BaseController
{
    // index(): prida CORS hlavičky a spracuje preflight, následne deleguje na JSON register handler
    public function index(Request $request): Response
    {
        // CORS hlavičky - vysvetlenie po riadkoch:
        // 1) Access-Control-Allow-Origin: povolený pôvod (origin) z ktorého môže JS bežať a volať túto API.
        //    - Tu je nastavené na development frontend 'http://localhost:5173'.
        //    - V produkcii by toto malo byť nastavené na tvoj frontend doménu alebo vypnuté pre `*`.
        header('Access-Control-Allow-Origin: http://localhost:5173');

        // 2) Access-Control-Allow-Methods: ktoré HTTP metódy sú povolené pri CORS požiadavkách.
        //    - Uvádzame POST, GET a OPTIONS (OPTIONS je vyžadované pre preflight požiadavky).
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

        // 3) Access-Control-Allow-Headers: zoznam hlavičiek, ktoré klient môže posielať pri actual request.
        //    - Tu povolíme 'Content-Type' (pre JSON), 'Authorization' (ak budete posielať token) a 'X-Requested-With'.
        //    - Ak klient posiela ďalšie custom hlavičky, treba ich sem pridať.
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            // Preflight odpoveď: prehliadač čaká jednoducho, či je CORS povolený - nepotrebujeme vykonať žiadnu logiku.
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        // Handler spracujúci samotnú registráciu
        return $this->handleRegisterJson($request);
    }

    // register(): alias pre index() - zjednodušuje volanie cez router
    public function register(Request $request): Response
    {
        return $this->index($request);
    }

    // handleRegisterJson(): načíta JSON telo, overí polia a vloží záznam do users tabuľky
    private function handleRegisterJson(Request $request): JsonResponse
    {
        if (!$request->isPost()) {
            // Registrácia musí byť cez POST (bezpečnejšie a konzistentné s REST praktikami)
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }

        try {
            // Dekódujeme JSON z php://input - ak nie je platný JSON, vyhodí sa JsonException
            $data = $request->json();
        } catch (\JsonException $e) {
            // Nevalidný JSON - vrátime HTTP 400 (Bad Request) a popis chyby
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Neplatný JSON']]))->setStatusCode(400);
        }

        // Ak $data je objekt (stdClass), prevedieme ho na asociatívne pole kvôli jednoduchšiemu prístupu
        if (is_object($data)) { $data = (array)$data; }
        if (!is_array($data)) {
            // Ak telo nie je ani objekt ani pole, vrátime chybu
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Prázdne telo požiadavky']]))->setStatusCode(400);
        }

        // Extrahujeme a trimujeme vstupy. Použiť explicitné pretypovanie je bezpečnejšie..
        $firstName = isset($data['firstName']) ? trim((string)$data['firstName']) : '';
        $lastName  = isset($data['lastName'])  ? trim((string)$data['lastName'])  : '';
        $email     = isset($data['email'])     ? trim((string)$data['email'])     : '';
        $password  = isset($data['password'])  ? (string)$data['password']        : '';
        $isStudent = isset($data['isStudent']) ? (int)$data['isStudent']          : 0;

        // Server-side validácia vstupov - vždy kontrolovať aj na serveri (frontend môže klamať)
        $errors = [];
        if ($firstName === '') $errors['firstName'] = 'First name is required';
        if ($lastName === '')  $errors['lastName']  = 'Last name is required';
        if ($email === '')     $errors['email']     = 'Email is required';
        elseif (!preg_match('/^\S+@\S+\.\S+$/', $email)) $errors['email'] = 'Invalid email';
        if ($password === '' || mb_strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters';

        if (!empty($errors)) {
            // Ak existujú validačné chyby, vrátime ich ako pole pre frontend, ktorý ich zobrazí pri poliach
            return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);
        }

        try {
            // Vložíme používateľa do DB
            $conn = Connection::getInstance();

            // Bezpečné uloženie hesla: nikdy neukladať heslo v plain-text
            // Používame password_hash s DEFAULT algoritmom (aktuálne BCrypt / Argon, podľa PHP verzie)
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Používame prepared statement so zástupnými parametrami (?) - zabraňuje SQL injection
            $insertSql = "INSERT INTO `users` (`firstName`, `lastName`, `email`, `password`, `isStudent`)
                          VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insertSql);
            $stmt->execute([$firstName, $lastName, $email, $hash, $isStudent]);

            // Úspešná registrácia - vrátime status 201 Created a stručnú správu
            return (new JsonResponse(['status' => 'ok', 'message' => 'Registration successful']))->setStatusCode(201);
        } catch (\PDOException $e) {
            // Ak dôjde k porušeniu unikátnosti (duplicitný email), databáza obvykle vráti SQLSTATE 23000
            if ($e->getCode() === '23000') {
                // Odovzdáme čitateľnú chybu pre pole 'email'
                return (new JsonResponse(['status' => 'error', 'errors' => ['email' => 'Email already registered']]))->setStatusCode(400);
            }
            // V dev režime môžeme vrátiť detailnú chybu, inak len generickú 500
            if (defined('App\\Configuration::SHOW_EXCEPTION_DETAILS') && \App\Configuration::SHOW_EXCEPTION_DETAILS) {
                return (new JsonResponse(['status' => 'error', 'message' => $e->getMessage()]))->setStatusCode(500);
            }
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        } catch (\Throwable $e) {
            // Záchyt iných neočakávaných výnimiek
            if (defined('App\\Configuration::SHOW_EXCEPTION_DETAILS') && \App\Configuration::SHOW_EXCEPTION_DETAILS) {
                return (new JsonResponse(['status' => 'error', 'message' => $e->getMessage()]))->setStatusCode(500);
            }
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }
}
