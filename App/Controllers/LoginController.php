<?php


namespace App\Controllers;

use App\Auth\DbIdentity;
use App\Configuration;
use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;
use Framework\DB\Connection;

/**
 * LoginController
 *
 * Účel:
 * - Spracúva prihlasovanie používateľov cez JSON API.
 * - Očakáva POST s JSON telom: { email: string, password: string }.
 * - Po úspešnom overení uloží do session inštanciu DbIdentity.
 *
 * Dôvody rozdelenia:
 * - Metóda index() poskytuje CORS hlavičky a spracuje preflight (OPTIONS).
 * - Samotná autentifikácia je v private metóde handleLoginJson() aby sa logika oddelila od HTTP/CORS handlingu.
 *
 * Bezpečnostné poznámky:
 * - Heslá sa neukladajú do session ani do identity objektu.
 * - Po úspešnom prihlásení sa volá session_regenerate_id(true) na zamedzenie session fixation útokov.
 * - Odpovede pri chybných prihlasovacích údajoch sú úmyselne generické (neprezrádzajú, či email existuje).
 */
class LoginController extends BaseController
{
    /**
     * index(Request): hlavný vstup pre login endpoint
     * - Pridá CORS hlavičky (povolený origin je nastavený tu na dev frontend).
     * - Spracuje OPTIONS preflight a v prípade POST deleguje na handler, ktorý vráti JsonResponse.
     *
     * Parametre:
     * - $request: objekt Request, poskytuje prístup k server/GET/POST a JSON telu.
     *
     * Návratová hodnota:
     * - JsonResponse s HTTP statusom 200 pre preflight alebo výsledkom prihlásenia.
     */
    public function index(Request $request): Response
    {
        // CORS hlavičky: povoliť požiadavky z dev frontendu (prípadne upraviť pre produkciu)
        header('Access-Control-Allow-Origin: http://localhost:5173');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        // Preflight handling: ak prehliadač posiela OPTIONS, vrátime rýchlu odpoveď bez ďalšej logiky
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        // V štandardnom prípade spustíme autentifikačnú logiku
        return $this->handleLoginJson($request);
    }

    /**
     * login(Request): alias pre index()
     * - Umožňuje volanie cez rôzne route akcie (?a=login alebo ?a=index).
     */
    public function login(Request $request): Response
    {
        return $this->index($request);
    }

    /**
     * handleLoginJson(Request): hlavná autentifikačná logika
     * - Očakáva POST.
     * - Načíta JSON z tela: ak nie je platný JSON vráti 400.
     * - Server-side validácia: email povinný, email musí byť v platnom tvare; heslo povinné.
     * - Vyhľadá používateľa v DB podľa emailu a overí heslo pomocou password_verify().
     * - Ak je úspech: regeneruje session id, uloží DbIdentity do session a vráti 200 + meno používateľa.
     * - Chybové stavy:
     *    400 - chybná požiadavka / validačné chyby / neplatný JSON
     *    401 - neplatné prihlasovacie údaje (email/heslo)
     *    403 - email nie je overený (ak sa v DB používa taký stĺpec)
     *    500 - interná chyba
     */
    private function handleLoginJson(Request $request): JsonResponse
    {
        // Povolené len POST požiadavky
        if (!$request->isPost()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }

        // V dev móde aktivujeme zobrazovanie chýb (v produkcii túto časť odstrániť)
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);

        // Načítanie a dekódovanie JSON tela
        try {
            // $request->json() číta php://input a hádže JsonException pri chybách
            $data = $request->json();
        } catch (\JsonException $e) {
            // Ak JSON nie je platný, vrátime jasnú chybu 400 s popisom
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Neplatný JSON']]))->setStatusCode(400);
        }

        // $data môže byť stdClass alebo pole; prevedieme ho na asociatívne pole pre jednoduchý prístup
        if (is_object($data)) {
            $data = (array)$data;
        }

        // Prázdne alebo neexistujúce telo je chybou
        if (!is_array($data) || count($data) === 0) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Prázdne telo požiadavky']]))->setStatusCode(400);
        }

        // Extrahujeme očakávané polia a zabezpečíme ich typy
        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        $password = isset($data['password']) ? (string)$data['password'] : '';

        // Server-side validácia: vždy bezpečné mať validáciu mimo frontendu
        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Email je povinný';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Neplatný email';
        }
        if ($password === '') {
            $errors['password'] = 'Heslo je povinné';
        }

        // Ak sú validačné chyby, vrátime ich vo formáte { status: 'error', errors: { field: message } }
        if (!empty($errors)) {
            return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);
        }

        // Overenie používateľa v databáze
        try {
            $conn = Connection::getInstance();
            // Vyberáme len potrebné stĺpce; heslo je uložené zahashované
            $sql = "SELECT id, firstName, lastName, email, password FROM `users` WHERE `email` = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$email]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Ak používateľ neexistuje, vrátime 401 bez špecifikácie
            if (!$user) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Neplatné prihlasovacie údaje']))->setStatusCode(401);
            }

            // Overíme heslo: password_verify umožňuje pracovať s rôznymi algoritmami
            if (!password_verify($password, $user['password'])) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Neplatné prihlasovacie údaje']))->setStatusCode(401);
            }

            // Voliteľné: ak DB obsahuje email_verified_at a hodnota je NULL, používateľa odmietneme
            if (isset($user['email_verified_at']) && $user['email_verified_at'] === null) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Email nie je overený']))->setStatusCode(403);
            }

            // Získame session a zabezpečíme regeneráciu session ID po prihlásení
            $session = $this->app->getSession();
            if (session_status() === PHP_SESSION_ACTIVE) {
                // regeneračné volanie znižuje riziko session fixation
                session_regenerate_id(true);
            }

            // Vytvoríme identity objekt (DTO) a uložíme ho do session pre ďalšie požiadavky
            $identity = new DbIdentity((int)$user['id'], $user['firstName'] ?? '', $user['lastName'] ?? '', $user['email'] ?? '');
            $session->set(Configuration::IDENTITY_SESSION_KEY, $identity);

            // Úspešné prihlásenie - vrátime meno používateľa pre UI
            return (new JsonResponse(['status' => 'ok', 'message' => 'Prihlásenie úspešné', 'name' => $identity->getName()] ))->setStatusCode(200);
        } catch (\Throwable $e) {
            // Pri vývojovom režime môžeme posielať detailné chyby (zapnuté cez Configuration)
            if (defined('App\\Configuration::SHOW_EXCEPTION_DETAILS') && \App\Configuration::SHOW_EXCEPTION_DETAILS) {
                return (new JsonResponse(['status' => 'error', 'message' => $e->getMessage()]))->setStatusCode(500);
            }
            // Inak generic 500
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }
}
