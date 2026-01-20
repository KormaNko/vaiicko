<?php

namespace App\Controllers;

use App\Configuration;
use Framework\Core\BaseController;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\RedirectResponse;
use Framework\Http\Responses\Response;

//  Toto je rodicovksy kontroler pre Vsetky ostatne ktore musia mat kontrolu prihlasenia odpovede pre ajajax a redirect na login
abstract class AppController extends BaseController
{
    // Tuto metodu pouzivam aby som zistil ci ze uzivatel prihlaseny alebo nie a podla toho mu buď povolim pokracovat alebo ho presmerujem na login stranku
    // tuto triedu mi poradil chat AI ked som bol zufaly a nevedel som ako to prepojit kvoli tym roznym portom pre react a php snazil som sa jej samozrjme pochopit na 100%
    protected function requireAuth(Request $request): ?Response
    {
        if ($this->user->isLoggedIn()) {
            return null;    // ak je pozuviatel prihlaseny tak sa nedeje nic a porkacuje normalne
        }

        // ak bola poziadavka ajax alebo json tak vratim json odpoved s chybou 401 nehadzem ho na login stranku
        if ($request->isAjax() || $request->wantsJson() || $request->isJson()) {
            return (new JsonResponse(['status' => 'error', 'message' => 'Unauthorized']))->setStatusCode(401);
        }

       // vrati pouzivatela na login stranku v pripade ze by do url chel napisat adresu na ktoru nema pravo
        return new RedirectResponse(Configuration::LOGIN_URL);
    }


    //tuto metodu mam preto ze mam php + react ktore kazdy bezi na inom porte cize potrebujem povolit cors pre komunikaciu medzi nimi
    protected function sendCorsIfNeeded(Request $request): void
    {
        $allowed = [
            'http://localhost:5173', // React dev server
        ];
        $origin = $request->server('HTTP_ORIGIN') ?? ''; // ziskam origin z poziadavky
        if (in_array($origin, $allowed, true)) {  // ak je origin v povolenych tak nastavim cors hlavičky
            header('Access-Control-Allow-Origin: ' . $origin);   // nastavim povoleny origin
            header('Vary: Origin'); // pridam vary header aby cache servery vedeli ze odpoved sa lisi podla originu
            header('Access-Control-Allow-Credentials: true'); // povolim posielanie cookies
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With'); // povolim potrebne hlavičky
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); // povolim metody
        }
    }

}
