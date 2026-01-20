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


        $body = [];
        if ($request->isJson()) {
            try {
                $tmp = $request->json();
                //vzdy chcem pracovat ako s polom tu mi tiez pomahal chat
                if (is_object($tmp)) $tmp = (array)$tmp;
                if (is_array($tmp)) $body = $tmp;
            } catch (\JsonException $e) {
                return (new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON']))->setStatusCode(400);
            }
        }

        try {
            $opts = Option::getByUserId($userId);
            if ($opts === null) {
                $opts = Option::createDefaultForUser($userId);
            }

            //tieto mi cisto generoval chat uz sa mi nechcelo pisat samemu
            if (array_key_exists('language', $body) || $request->hasValue('language')) {
                $val = $body['language'] ?? $request->value('language');
                if ($val === '') $val = null;
                if ($val !== null) $opts->setLanguage($val);
            }

            if (array_key_exists('theme', $body) || $request->hasValue('theme')) {
                $val = $body['theme'] ?? $request->value('theme');
                if ($val === '') $val = null;
                if ($val !== null) $opts->setTheme($val);
            }

            if (array_key_exists('task_filter', $body) || array_key_exists('taskFilter', $body) || $request->hasValue('task_filter') || $request->hasValue('taskFilter')) {
                $val = $body['task_filter'] ?? $body['taskFilter'] ?? $request->value('task_filter') ?? $request->value('taskFilter');
                if ($val === '') $val = null;
                if ($val !== null) $opts->setTaskFilter($val);
            }

            if (array_key_exists('task_sort', $body) || array_key_exists('taskSort', $body) || $request->hasValue('task_sort') || $request->hasValue('taskSort')) {
                $val = $body['task_sort'] ?? $body['taskSort'] ?? $request->value('task_sort') ?? $request->value('taskSort');
                if ($val === '') $val = null;
                if ($val !== null) $opts->setTaskSort($val);
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

