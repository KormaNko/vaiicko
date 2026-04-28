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

    // Create a new category (accept JSON only, expecting camelCase keys)
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

        if (!$request->isJson()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Expected JSON body']))->setStatusCode(400);
        }

        try {
            $data = $request->json();
            if (is_object($data)) $data = (array)$data;
        } catch (\JsonException $e) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Invalid JSON']]))->setStatusCode(400);
        }

        $name = $data['name'] ?? '';
        $color = $data['color'] ?? null;
        $planFrom = $data['planFrom'] ?? null;
        $planTo = $data['planTo'] ?? null;
        $maxDuration = $data['maxDuration'] ?? null;

        $name = isset($name) ? trim((string)$name) : '';
        $color = isset($color) ? trim((string)$color) : null;
        $planFrom = isset($planFrom) ? trim((string)$planFrom) : null;
        $planTo = isset($planTo) ? trim((string)$planTo) : null;
        $maxDuration = isset($maxDuration) ? trim((string)$maxDuration) : null;

        $errors = [];
        if ($name === '') $errors['name'] = 'Name is required';
        if ($color !== null && $color !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $errors['color'] = 'Color must be hex like #RRGGBB';

        $timeRe = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
        if ($planFrom !== null && $planFrom !== '' && !preg_match($timeRe, $planFrom)) $errors['planFrom'] = 'Invalid time format';
        if ($planTo !== null && $planTo !== '' && !preg_match($timeRe, $planTo)) $errors['planTo'] = 'Invalid time format';

        if ($maxDuration !== null && $maxDuration !== '' && !preg_match('/^\d+$/', $maxDuration)) $errors['maxDuration'] = 'Must be an integer (minutes)';

        if (!empty($errors)) return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400);

        $cat = new Category();
        $cat->setUserId($this->user->getIdentity()->getId());
        $cat->setName($name);
        $cat->setColor($color === '' ? null : $color);
        $cat->setPlanFrom($planFrom === '' ? null : $planFrom);
        $cat->setPlanTo($planTo === '' ? null : $planTo);
        $cat->setMaxDuration($maxDuration === '' ? null : ($maxDuration === null ? null : (int)$maxDuration));
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

        if (!$request->isJson()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Expected JSON body']))->setStatusCode(400);
        }

        try {
            $data = $request->json();
            if (is_object($data)) $data = (array)$data;
        } catch (\JsonException $e) {
            return (new JsonResponse(['status' => 'error', 'errors' => ['body' => 'Invalid JSON']]))->setStatusCode(400);
        }

        $id = $data['id'] ?? null;
        if ($id === null || $id === '') return (new JsonResponse(['status' => 'error', 'message' => 'Missing id']))->setStatusCode(400);

        $cat = Category::getOne((int)$id);
        if (!$cat || $cat->getUserId() !== $this->user->getIdentity()->getId()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Not found']))->setStatusCode(404);
        }

        // Update only fields present in JSON (camelCase keys expected)
        if (array_key_exists('name', (array)$data)) {
            $name = trim((string)$data['name']);
            if ($name === '') return (new JsonResponse(['status' => 'error', 'errors' => ['name' => 'Name required']]))->setStatusCode(400);
            $cat->setName($name);
        }

        if (array_key_exists('color', (array)$data)) {
            $color = $data['color'] === null ? null : trim((string)$data['color']);
            if ($color !== null && $color !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) return (new JsonResponse(['status'=>'error','errors'=>['color'=>'Invalid color']]))->setStatusCode(400);
            $cat->setColor($color === '' ? null : $color);
        }

        $timeRe = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
        if (array_key_exists('planFrom', (array)$data)) {
            $val = $data['planFrom'] === null ? null : trim((string)$data['planFrom']);
            if ($val !== null && $val !== '' && !preg_match($timeRe, $val)) return (new JsonResponse(['status'=>'error','errors'=>['planFrom'=>'Invalid time']]))->setStatusCode(400);
            $cat->setPlanFrom($val === '' ? null : $val);
        }

        if (array_key_exists('planTo', (array)$data)) {
            $val = $data['planTo'] === null ? null : trim((string)$data['planTo']);
            if ($val !== null && $val !== '' && !preg_match($timeRe, $val)) return (new JsonResponse(['status'=>'error','errors'=>['planTo'=>'Invalid time']]))->setStatusCode(400);
            $cat->setPlanTo($val === '' ? null : $val);
        }

        if (array_key_exists('maxDuration', (array)$data)) {
            $val = $data['maxDuration'] === null ? null : trim((string)$data['maxDuration']);
            if ($val !== null && $val !== '' && !preg_match('/^\d+$/', $val)) return (new JsonResponse(['status'=>'error','errors'=>['maxDuration'=>'Must be integer']]))->setStatusCode(400);
            $cat->setMaxDuration($val === '' ? null : ($val === null ? null : (int)$val));
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
