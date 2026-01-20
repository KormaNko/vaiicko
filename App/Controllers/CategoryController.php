<?php

namespace App\Controllers;

use App\Models\Category;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;

class CategoryController extends AppController
{
    // List categories for current user
    public function index(Request $request): Response
    {
        $this->sendCorsIfNeeded($request); // zistujem origin a nastavujem CORS hlavičky zase kvoli tomu ze react + php
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200); // no kuknem co mi za metodu prehliadac poslal ak je to iba otazka vraciam mu ze backend zije
        }
        // klasika zistujem ci je pouzivatel prihlaseny
        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        //ziskam si id konkretne prave prihlaseneho uzivatela
        $userId = $this->user->getIdentity()->getId();
        // ziskam vsetky kategorie daneho uzivatela zoradene podla mena vzostupne
        $cats = Category::getAll('user_id = ?', [$userId], 'name ASC');
        // posielam data v json formate
        return new JsonResponse(['status' => 'ok', 'data' => $cats]);
    }


    public function detail(Request $request): Response
    {

        $this->sendCorsIfNeeded($request);
        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $id = $request->get('id');
        // id neprislo alebo je prazdne
        if ($id === null || $id === '') {
            return (new JsonResponse(['status' => 'error', 'message' => 'Missing id']))->setStatusCode(400);
        }

        $cat = Category::getOne((int)$id);
        // ak kategoria neexistuje alebo nepatri prihlasenemu uzivatelovi vraciam rovnaku vec to mi poradil copilot ze je to lepsie pre bezpecnost
        if (!$cat || $cat->getUserId() !== $this->user->getIdentity()->getId()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Not found']))->setStatusCode(404);
        }
        //ak vsetko v cajku vraciam
        return new JsonResponse(['status' => 'ok', 'data' => $cat]);
    }

    // Create a new category (accept JSON or form-encoded)
    public function create(Request $request): Response
    {
        $this->sendCorsIfNeeded($request); // kuknem od koho to prislo ak je to nejaky nepovoleny nic mu nedam ak je to moj react frontend tak mu vratim

        if ($request->server('REQUEST_METHOD') === 'OPTIONS') { // prehliadac sa pyta ci moze poslat request tak mu poviem ze ano
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        if (!$request->isPost()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }


        $data = null;
        if ($request->isJson()) { // zistujem ci je to json request
            try { // skusim dekodovat json
                $data = $request->json();
                if (is_object($data)) $data = (array)$data; // ak je to objekt prevediem na pole
            } catch (\JsonException $e) {
                // ak sa nepodarilo dekodovat json vratim chybu
                return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Invalid JSON']]))->setStatusCode(400);
            }
        }

        // ziskam hodnoty z json alebo z form hodnot
        $name = $data['name'] ?? $request->value('name');
        $color = $data['color'] ?? $request->value('color');

        //normalizujem vstupy
        $name = isset($name) ? trim((string)$name) : '';
        $color = isset($color) ? trim((string)$color) : null;

        // validacia vstupov
        $errors = [];
        // ak je meno prazdne pridam chybu
        if ($name === '') $errors['name'] = 'Name is required';
        // ak je farba zadana a nie je v spravnom formate pridam chybu
        if ($color !== null && $color !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $errors['color'] = 'Color must be hex like #RRGGBB';   // komplet vygenerovany riadok od AI
        if (!empty($errors)) return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400); // ak je chyba vypisem ju


        //vytvaram novu kategoriu
        $cat = new Category();
        $cat->setUserId($this->user->getIdentity()->getId());
        $cat->setName($name);
        $cat->setColor($color === '' ? null : $color);
        $cat->setCreatedAt(date('Y-m-d H:i:s'));
        $cat->setUpdatedAt(date('Y-m-d H:i:s'));

        try {
            //vkladam do DB
            $cat->save();
            return (new JsonResponse(['status' => 'ok', 'data' => $cat]))->setStatusCode(201);
        } catch (\Throwable $e) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }

    // updatujem kategoriu ktora uz existuje
    public function update(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        if (!$request->isPost()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }

       //klasika skusam ci je to json
        $data = null;
        if ($request->isJson()) {
            try {
                $data = $request->json();
                if (is_object($data)) $data = (array)$data;
            } catch (\JsonException $e) {
                return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Invalid JSON']]))->setStatusCode(400);
            }
        }

        // ci viem vobec co mam editovat
        $id = $data['id'] ?? $request->value('id');
        if ($id === null || $id === '') return (new JsonResponse(['status' => 'error', 'message' => 'Missing id']))->setStatusCode(400);

        // ziskam kategoriu a overim ci patri prihlasenemu uzivatelovi
        $cat = Category::getOne((int)$id);
        if (!$cat || $cat->getUserId() !== $this->user->getIdentity()->getId()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Not found']))->setStatusCode(404);
        }

        // overujem ci prislo meno najskor v json a potom v form hodnotach
        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if ($name === '') return (new JsonResponse(['status' => 'error', 'errors' => ['name' => 'Name required']]))->setStatusCode(400);
            $cat->setName($name);
        } else {
            // also accept form value
            if ($request->hasValue('name')) {
                $name = trim((string)$request->value('name'));
                if ($name === '') return (new JsonResponse(['status' => 'error', 'errors' => ['name' => 'Name required']]))->setStatusCode(400);
                $cat->setName($name);
            }
        }

        if (array_key_exists('color', (array)$data)) {
            $color = $data['color'] === null ? null : trim((string)$data['color']);
            if ($color !== null && $color !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) return (new JsonResponse(['status'=>'error','errors'=>['color'=>'Invalid color']]))->setStatusCode(400);
            $cat->setColor($color === '' ? null : $color);
        } else {
            if ($request->hasValue('color')) {
                $color = $request->value('color') === null ? null : trim((string)$request->value('color'));
                if ($color !== null && $color !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) return (new JsonResponse(['status'=>'error','errors'=>['color'=>'Invalid color']]))->setStatusCode(400);
                $cat->setColor($color === '' ? null : $color);
            }
        }

        $cat->setUpdatedAt(date('Y-m-d H:i:s'));
        try {
            $cat->save();
            return new JsonResponse(['status' => 'ok', 'data' => $cat]);
        } catch (\Throwable $e) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }

    // Delete category (accept JSON or form-encoded)
    public function delete(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        if (!$request->isPost()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Method not allowed']))->setStatusCode(405);
        }


        $data = null;
        if ($request->isJson()) {
            try {
                $data = $request->json();
                if (is_object($data)) $data = (array)$data;
            } catch (\JsonException $e) {
                return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Invalid JSON']]))->setStatusCode(400);
            }
        }

        $id = $data['id'] ?? $request->value('id');
        if ($id === null || $id === '') return (new JsonResponse(['status' => 'error', 'message' => 'Missing id']))->setStatusCode(400);

        $cat = Category::getOne((int)$id);
        if (!$cat || $cat->getUserId() !== $this->user->getIdentity()->getId()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Not found']))->setStatusCode(404);
        }

        try {
            $cat->delete();
            return new JsonResponse(['status' => 'ok']);
        } catch (\Throwable $e) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Internal Server Error']))->setStatusCode(500);
        }
    }
}
