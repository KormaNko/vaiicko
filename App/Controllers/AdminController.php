<?php

namespace App\Controllers;


use Framework\Http\Request;
use Framework\Http\Responses\Response;
// tato trieda je spolocna trieda ktora riesi cast aplikacie ktora je dostupna az po prihlaseni uzivatela
class AdminController extends AppController
{
   // tato metoda kontroluje ci je uzivatel prihlaseny
    public function authorize(Request $request, string $action): bool
    {
        return $this->user->isLoggedIn();
    }
    // jednoducha metoda na rozhodnutie co sa ma stat ked teda nemas pravo pristupu k danej akcii
    public function index(Request $request): Response
    {
        $resp = $this->requireAuth($request); // overi ci je uzivatel prihlaseny a vrati bud null alebo redirect na login stranku alebo json odpoved s chybou 401
        if ($resp) return $resp; // ak je odpoved nieco ine ako null tak to vratim hned

         // ak je uzivatel prihlaseny tak mu zobrazim admin dashboard

        return $this->html();
    }
}
