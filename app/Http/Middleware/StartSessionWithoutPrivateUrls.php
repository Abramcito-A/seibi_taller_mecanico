<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

/**
 * Igual que StartSession, pero no guarda como "URL anterior" las páginas del portal de cotizaciones:
 * su URL contiene el token privado y las sesiones se almacenan en la base de datos.
 */
class StartSessionWithoutPrivateUrls extends StartSession
{
    /**
     * @param  Session  $session
     */
    protected function storeCurrentUrl(Request $request, $session)
    {
        if (str_starts_with((string) $request->route()?->getName(), 'portal.')) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
