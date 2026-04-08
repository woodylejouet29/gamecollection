<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Middleware\AuthMiddleware;
use App\Core\View;
use App\Services\HomeService;

class HomeController
{
    public function index(): void
    {
        $service  = new HomeService();
        $homeData = $service->getHomeData();
        $meta     = $homeData['_meta']['homeServiceTimings'] ?? null;
        unset($homeData['_meta']);

        if (is_array($meta) && !headers_sent()) {
            $parts = [];
            foreach ($meta as $name => $t) {
                $ms = isset($t['ms']) ? (float) $t['ms'] : null;
                if ($ms === null) {
                    continue;
                }
                $cache = isset($t['cache']) ? (string) $t['cache'] : 'miss';
                $ok    = !empty($t['ok']) ? '1' : '0';
                $safeName = preg_replace('/[^a-zA-Z0-9_\\-\\.]/', '_', (string) $name);
                $parts[] = "{$safeName};dur=" . number_format($ms, 1, '.', '') . ";desc=\"cache={$cache},ok={$ok}\"";
            }
            if (!empty($parts)) {
                // Autorise d'autres "Server-Timing" (ex: timing global requête) à coexister.
                header('Server-Timing: ' . implode(', ', $parts), false);
            }
        }

        $metaDesc = 'Cataloguez votre passion jeu vidéo. Organisez votre collection physique et digitale, notez vos expériences, découvrez les perles rares.';
        $appUrl   = rtrim($_ENV['APP_URL'] ?? '', '/');

        View::render('home/index', array_merge($homeData, [
            'title'    => 'Accueil',
            'cssFile'  => 'home',
            'metaDesc' => $metaDesc,
            'ogTitle'  => 'GameCollection — Cataloguez votre passion jeu vidéo',
            'ogDesc'   => $metaDesc,
            'ogUrl'    => $appUrl . '/',
            'head'     => '<script src="' . View::asset('js/home.js') . '" defer></script>',
        ]));
    }
}

