<?php

namespace App\Controllers;

use App\Models\Option;
use Exception;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\Response;

class OptionsController extends AppController
{
    public function index(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $userId = $this->user->getIdentity()->getId();
        //Načítaj používateľove nastavenia; ak ešte neexistujú, vytvor ich; potom ich pošli späť ako JSON.
        try {
            $opts = Option::getByUserId($userId);
            if ($opts === null) {
                // vytvorim nejake default nastavenia pre daneho uzivatela
                $opts = Option::createDefaultForUser($userId);
            }
            return $this->json($opts);
        } catch (Exception $e) {
            return $this->json(['error' => 'Failed to load options'])->setStatusCode(500);
        }
    }

    public function update(Request $request): Response
    {
        $this->sendCorsIfNeeded($request);
        if ($request->server('REQUEST_METHOD') === 'OPTIONS') {
            return (new JsonResponse(['status' => 'ok']))->setStatusCode(200);
        }

        $resp = $this->requireAuth($request);
        if ($resp) return $resp;

        $userId = $this->user->getIdentity()->getId();

        // Expect JSON body with keys exactly as frontend sends: language, theme, task_filter, task_sort
        if (!$request->isJson()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Expected JSON body']))->setStatusCode(400);
        }

        try {
            $tmp = $request->json();
            if (is_object($tmp)) $tmp = (array)$tmp;
            if (!is_array($tmp)) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON payload']))->setStatusCode(400);
            }
            $body = $tmp;
        } catch (\JsonException $e) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON']))->setStatusCode(400);
        }

        try {
            $opts = Option::getByUserId($userId);
            if ($opts === null) {
                $opts = Option::createDefaultForUser($userId);
            }

            if (array_key_exists('language', $body)) {
                $val = $body['language'];
                if ($val === '') $val = null;
                if ($val !== null) $opts->setLanguage($val);
            }

            if (array_key_exists('theme', $body)) {
                $val = $body['theme'];
                if ($val === '') $val = null;
                if ($val !== null) $opts->setTheme($val);
            }

            if (array_key_exists('task_filter', $body)) {
                $val = $body['task_filter'];
                if ($val === '') $val = null;
                if ($val !== null) $opts->setTaskFilter($val);
            }

            if (array_key_exists('task_sort', $body)) {
                $val = $body['task_sort'];
                if ($val === '') $val = null;
                if ($val !== null) $opts->setTaskSort($val);
            }

            // new work day start/end (expect keys exactly: work_day_start, work_day_end)
            if (array_key_exists('work_day_start', $body)) {
                $val = $body['work_day_start'];
                if ($val === '') {
                    // empty -> reset to DB/default value
                    $opts->setWorkDayStart('08:00:00');
                } else {
                    $opts->setWorkDayStart($val);
                }
            }

            if (array_key_exists('work_day_end', $body)) {
                $val = $body['work_day_end'];
                if ($val === '') {
                    $opts->setWorkDayEnd('16:00:00');
                } else {
                    $opts->setWorkDayEnd($val);
                }
            }

            $opts->setUpdatedAt(date('Y-m-d H:i:s'));
            $opts->save();

            return $this->json($opts);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()])->setStatusCode(400);
        } catch (Exception $e) {
            return $this->json(['error' => 'Failed to update options'])->setStatusCode(500);
        }
    }
}
