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

        $this->sendCorsIfNeeded($request); // kvoli react + php
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') { // preflight
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        //metoda ktora to riesi
        return $this->handleLoginJson($request);
    }


    // vola sa pri prihlasovani cez formular
    public function login(Request $request): Response
    {
        return $this->index($request);
    }

    /**
     * Returns information about the current authenticated user.
     * GET /login/me (or route to LoginController::me)
     */
    public function me(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        // Use the AppUser injected into the controller
        if (!$this->user->isLoggedIn()) {
            return new JsonResponse(['authenticated' => false]);
        }

        $identity = $this->user->getIdentity();
        return new JsonResponse([
            'authenticated' => true,
            'id' => $identity->getId(),
            'name' => $identity->getName(),
        ]);
    }

    // spracovanie prihlasovania
    private function handleLoginJson(Request $request): JsonResponse
    {
        //musi byt post
        if (!$request->isPost()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }


        //nacitanie json a kontrola ci je validny
        try {
            $data = $request->json();
        } catch (\JsonException $e) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Neplatný JSON']]))->setStatusCode(400);
        }

        // ak je objekt prevediem na pole
        if (is_object($data)) {
            $data = (array)$data;
        }

        // kontrola ci je pole a nie je prazdne
        if (!is_array($data) || count($data) === 0) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Prázdne telo požiadavky']]))->setStatusCode(400);
        }

        // ziskanie a normalizacia vstupov
        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        $password = isset($data['password']) ? (string)$data['password'] : '';

        // validacia vstupov
        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Email je povinný';
            //tu je metodka od chetgpt ktora validuje email pefktne
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Neplatný email';
        }
        if ($password === '') {
            $errors['password'] = 'Heslo je povinné';
        }
        // ak su chyby vratim ich
        if (!empty($errors)) {
            return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);
        }
        // pokus o prihlasenie
        try {
            //vytvorim spojenie
            $conn = Connection::getInstance();
            //pripravim si sql na hladanie usera podla emailu ale zatial ako ?
            $sql = "SELECT id, firstName, lastName, email, password FROM users WHERE email = ? LIMIT 1";
            //pripravim DB na hladanie usera zatial ako ? kvoli sql injection
            $stmt = $conn->prepare($sql);
            //spustim sql s tym ze ? nahradim hodnotou email z requestu
            $stmt->execute([$email]);
            //ziskam usera z DB ak je nejaky
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            //ak user neexistuje alebo heslo nesedi vratim chybu
            if (!$user) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Neplatné prihlasovacie údaje']))->setStatusCode(401);
            }

            // overenie hesla pomocou password_verify takze hash musi byt v DB
            if (!password_verify($password, $user['password'])) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Neplatné prihlasovacie údaje']))->setStatusCode(401);
            }


            //bud spusti ale vrati existuju session
            $session = $this->app->getSession();
            // regeneracia session id po prihlaseni pre bezpecnost
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            // vytvorenie identity a ulozenie do session toto mi poradilo cisto AI nemal som ani tucha ako sa to robi
            $identity = new DbIdentity((int)$user['id'], $user['firstName'] ?? '', $user['lastName'] ?? '', $user['email'] ?? '');
            //Ulož prihláseného používateľa do session teda keby tu nieje tak by ma to vkuse odhlasovalo
            $session->set(Configuration::IDENTITY_SESSION_KEY, $identity);

            //
            $csrf = null;
            try {
                //vygenerujem 32 nahodnych bitov a prevediem na hex format
                $csrf = bin2hex(random_bytes(32));
                //ulozim csrf token do session od teraz server má tajný token viazany na pouzivatela
                $session->set('csrf_token', $csrf);
            } catch (\Throwable $e) {
               //login sa podari ale CSRF ochrana len nebude aktívna
            }

            //uspesne prihlasenie - vrátime aj id aby frontend mohol okamžite nastaviť currentUser
            $payload = [
                'status' => 'ok',
                'message' => 'Prihlásenie úspešné',
                'id' => $identity->getId(),
                'name' => $identity->getName()
            ];

            return (new JsonResponse($payload))->setStatusCode(200);

        } catch (\Throwable $e) {
            // v pripade chyby vraciam
            return (new JsonResponse([
                'status' => 'error',
                'message' => 'Internal Server Error'
            ]))->setStatusCode(500);
        }
    }
}