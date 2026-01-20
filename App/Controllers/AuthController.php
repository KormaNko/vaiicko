<?php

namespace App\Controllers;

use App\Configuration;
use Exception;
use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\Response;
use Framework\Http\Responses\ViewResponse;

// tato trieda riadi autarizovanie ako login a logout a presmerovanie na login stranku
class AuthController extends BaseController
{

    public function index(Request $request): Response // login je samozrejme dostupny aj ked niesy prihlaseny
    {
        return $this->redirect(Configuration::LOGIN_URL);
    }



    // Prihlasenie pouzivatela ak nezadal spravne heslo alebo meno tak ostane na rovnakej stranke  a dostane vypis

    public function login(Request $request): Response
    {
        $logged = null;
        if ($request->hasValue('submit')) {
            $logged = $this->app->getAuthenticator()->login($request->value('username'), $request->value('password'));
            if ($logged) {
                return $this->redirect($this->url("admin.index"));
            }
        }

        $message = $logged === false ? 'Bad username or password' : null;
        return $this->html(compact("message"));
    }

    // odhlasenie pouzivatela a presmerovanie na login stranku
    public function logout(Request $request): Response
    {
        $this->app->getAuthenticator()->logout();
        return $this->html();
    }
}
