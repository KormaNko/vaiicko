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

        // new fields (accept snake_case or camelCase keys)
        $planFrom = $data['plan_from'] ?? $data['planFrom'] ?? $request->value('plan_from') ?? $request->value('planFrom');
        $planTo = $data['plan_to'] ?? $data['planTo'] ?? $request->value('plan_to') ?? $request->value('planTo');
        $maxDuration = $data['max_duration'] ?? $data['maxDuration'] ?? $request->value('max_duration') ?? $request->value('maxDuration');
        // atomic_task removed from categories table

        //normalizujem vstupy
        $name = isset($name) ? trim((string)$name) : '';
        $color = isset($color) ? trim((string)$color) : null;

        $planFrom = isset($planFrom) ? trim((string)$planFrom) : null;
        $planTo = isset($planTo) ? trim((string)$planTo) : null;
        $maxDuration = isset($maxDuration) ? trim((string)$maxDuration) : null;
        // atomicTask handling removed

        // validacia vstupov
        $errors = [];
        // ak je meno prazdne pridam chybu
        if ($name === '') $errors['name'] = 'Name is required';
        // ak je farba zadana a nie je v spravnom formate pridam chybu
        if ($color !== null && $color !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $errors['color'] = 'Color must be hex like #RRGGBB';

        // simple time validation HH:MM or HH:MM:SS
        $timeRe = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
        if ($planFrom !== null && $planFrom !== '' && !preg_match($timeRe, $planFrom)) $errors['planFrom'] = 'Invalid time format';
        if ($planTo !== null && $planTo !== '' && !preg_match($timeRe, $planTo)) $errors['planTo'] = 'Invalid time format';

        // maxDuration should be integer or empty
        if ($maxDuration !== null && $maxDuration !== '' && !preg_match('/^\d+$/', $maxDuration)) $errors['maxDuration'] = 'Must be an integer (minutes)';

        // atomicTask removed from categories (column dropped) - no validation here

        if (!empty($errors)) return (new JsonResponse(['status' => 'error', 'errors' => $errors]))->setStatusCode(400); // ak je chyba vypisem ju


        //vytvaram novu kategoriu
        $cat = new Category();
        $cat->setUserId($this->user->getIdentity()->getId());
        $cat->setName($name);
        $cat->setColor($color === '' ? null : $color);

        // set new fields on model (empty string -> null)
        $cat->setPlanFrom($planFrom === '' ? null : $planFrom);
        $cat->setPlanTo($planTo === '' ? null : $planTo);
        $cat->setMaxDuration($maxDuration === '' ? null : ($maxDuration === null ? null : (int)$maxDuration));
        // atomicTask removed from categories (no longer set on model)

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

        // new fields handling for update
        // plan_from / planFrom
        if (array_key_exists('plan_from', (array)$data) || array_key_exists('planFrom', (array)$data)) {
            $val = array_key_exists('plan_from', (array)$data) ? $data['plan_from'] : $data['planFrom'];
            $val = $val === null ? null : trim((string)$val);
            $timeRe = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
            if ($val !== null && $val !== '' && !preg_match($timeRe, $val)) return (new JsonResponse(['status'=>'error','errors'=>['planFrom'=>'Invalid time']]))->setStatusCode(400);
            $cat->setPlanFrom($val === '' ? null : $val);
        } else {
            if ($request->hasValue('plan_from') || $request->hasValue('planFrom')) {
                $rv = $request->hasValue('plan_from') ? $request->value('plan_from') : $request->value('planFrom');
                $rv = $rv === null ? null : trim((string)$rv);
                $timeRe = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
                if ($rv !== null && $rv !== '' && !preg_match($timeRe, $rv)) return (new JsonResponse(['status'=>'error','errors'=>['planFrom'=>'Invalid time']]))->setStatusCode(400);
                $cat->setPlanFrom($rv === '' ? null : $rv);
            }
        }

        // plan_to / planTo
        if (array_key_exists('plan_to', (array)$data) || array_key_exists('planTo', (array)$data)) {
            $val = array_key_exists('plan_to', (array)$data) ? $data['plan_to'] : $data['planTo'];
            $val = $val === null ? null : trim((string)$val);
            $timeRe = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
            if ($val !== null && $val !== '' && !preg_match($timeRe, $val)) return (new JsonResponse(['status'=>'error','errors'=>['planTo'=>'Invalid time']]))->setStatusCode(400);
            $cat->setPlanTo($val === '' ? null : $val);
        } else {
            if ($request->hasValue('plan_to') || $request->hasValue('planTo')) {
                $rv = $request->hasValue('plan_to') ? $request->value('plan_to') : $request->value('planTo');
                $rv = $rv === null ? null : trim((string)$rv);
                $timeRe = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
                if ($rv !== null && $rv !== '' && !preg_match($timeRe, $rv)) return (new JsonResponse(['status'=>'error','errors'=>['planTo'=>'Invalid time']]))->setStatusCode(400);
                $cat->setPlanTo($rv === '' ? null : $rv);
            }
        }

        // max_duration / maxDuration
        if (array_key_exists('max_duration', (array)$data) || array_key_exists('maxDuration', (array)$data)) {
            $val = array_key_exists('max_duration', (array)$data) ? $data['max_duration'] : $data['maxDuration'];
            $val = $val === null ? null : trim((string)$val);
            if ($val !== null && $val !== '' && !preg_match('/^\d+$/', $val)) return (new JsonResponse(['status'=>'error','errors'=>['maxDuration'=>'Must be integer']]))->setStatusCode(400);
            $cat->setMaxDuration($val === '' ? null : ($val === null ? null : (int)$val));
        } else {
            if ($request->hasValue('max_duration') || $request->hasValue('maxDuration')) {
                $rv = $request->hasValue('max_duration') ? $request->value('max_duration') : $request->value('maxDuration');
                $rv = $rv === null ? null : trim((string)$rv);
                if ($rv !== null && $rv !== '' && !preg_match('/^\d+$/', $rv)) return (new JsonResponse(['status'=>'error','errors'=>['maxDuration'=>'Must be integer']]))->setStatusCode(400);
                $cat->setMaxDuration($rv === '' ? null : ($rv === null ? null : (int)$rv));
            }
        }

        // atomic_task removed from categories

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
