<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Middleware\AuthMiddleware;
use App\Core\View;

class SelectController
{
    public function index(): void
    {
        AuthMiddleware::requireAuth();

        $authUser = AuthMiddleware::user();

        View::render('select/index', [
            'title'   => 'Ma sélection',
            'cssFile' => 'select',
            'metaDesc'=> 'Finalisez l\'ajout de vos jeux sélectionnés à votre collection.',
            'foot'    => '<script>window.SELECT_CONFIG=' . json_encode([
                'gameUrl'       => '/api/games/',
                'addUrl'        => '/api/collection/add',
                'checkDupUrl'   => '/api/select/check-duplicate',
                'collectionUrl' => '/collection',
                'searchUrl'     => '/search',
            ], JSON_UNESCAPED_SLASHES) . ';</script>'
                      . '<script src="' . View::asset('js/collection-release.js') . '" defer></script>'
                      . '<script src="' . View::asset('js/select.js') . '" defer></script>',
        ]);
    }
}
