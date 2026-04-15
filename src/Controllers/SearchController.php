<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Data\PlatformAbbreviations;
use App\Services\SearchService;

class SearchController
{
    public function index(): void
    {
        // Canonicalisation URL : on a retiré les filtres par année et le tri "oldest".
        // Si quelqu'un arrive avec une ancienne URL partagée, on nettoie et on redirige.
        if (!empty($_GET)) {
            $needsCleanup = array_key_exists('year_from', $_GET)
                || array_key_exists('year_to', $_GET)
                || (isset($_GET['sort']) && $_GET['sort'] === 'oldest');

            if ($needsCleanup) {
                $params = $_GET;
                unset($params['year_from'], $params['year_to']);

                if (($params['sort'] ?? null) === 'oldest') {
                    unset($params['sort']); // retombe sur le défaut « tous les jeux »
                }

                // Ces paramètres dépendent des filtres/résultats, on les retire pour éviter incohérences.
                unset($params['cursor'], $params['page']);

                $qs  = http_build_query($params);
                $url = '/search' . ($qs !== '' ? ('?' . $qs) : '');

                header('Location: ' . $url, true, 302);
                exit;
            }
        }

        $service = new SearchService();

        // Lecture des filtres depuis $_GET
        $platforms = [];
        $platformGet = $_GET['platform'] ?? [];
        if (is_numeric($platformGet)) {
            $platformGet = [(string) $platformGet];
        } elseif (is_string($platformGet) && $platformGet !== '') {
            $platformGet = [$platformGet];
        }
        if (is_array($platformGet)) {
            foreach ($platformGet as $pv) {
                if (is_numeric($pv)) {
                    $platforms[] = (int) $pv;
                }
            }
        }
        $platforms = array_values(array_unique(array_filter($platforms, static fn($x) => $x > 0)));

        $genres = [];
        $genreGet = $_GET['genre'] ?? [];
        if (is_string($genreGet) && $genreGet !== '') {
            $genreGet = [$genreGet];
        }
        if (is_array($genreGet)) {
            foreach ($genreGet as $gv) {
                $gv = trim((string) $gv);
                if ($gv !== '') {
                    $genres[] = $gv;
                }
            }
        }
        $genres = array_values(array_unique($genres));

        $sortGet = trim((string) ($_GET['sort'] ?? ''));
        if ($sortGet === 'all' || $sortGet === '') {
            $sort = 'all';
        } elseif (in_array($sortGet, ['recent', 'upcoming', 'rating'], true)) {
            $sort = $sortGet;
        } else {
            $sort = 'all';
        }

        $filters = [
            'q'          => trim($_GET['q']          ?? ''),
            'platforms'  => $platforms,
            'genres'     => $genres,
            'rating_min' => (int) ($_GET['rating_min'] ?? 0),
            'sort'       => $sort,
        ];

        $perPage = 24;
        $cursor  = isset($_GET['cursor']) ? trim((string) $_GET['cursor']) : '';

        // Détection requête AJAX (intercept JS via X-Requested-With)
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
               && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $baseUrl = $this->buildBaseUrl($filters);

        if ($isAjax) {
            // Requête AJAX : seulement la recherche (options déjà dans le DOM)
            // Perf: en AJAX on ne calcule pas le total (count=none) pour accélérer.
            $result = $service->searchCursor($filters, $cursor !== '' ? $cursor : null, $perPage, 'none');
            if (!is_array($result)) {
                $result = [];
            }
            $games = is_array($result['games'] ?? null) ? $result['games'] : [];
            $total = (int) ($result['total'] ?? 0);
            $hadError = !empty($result['_error']);
            $countMode = (string) ($result['_count_mode'] ?? 'none');
            $platformMap = $this->platformMap($service);

            header('Content-Type: text/html; charset=utf-8');
            header('X-Search-Total: ' . $total);
            header('Cache-Control: no-store');

            View::renderPartial('search/_results', [
                'games'        => $games,
                'totalResults' => $total,
                'filters'      => $filters,
                'platformMap'  => $platformMap,
                'baseUrl'      => $baseUrl,
                'nextCursor'   => $result['next_cursor'] ?? null,
                'pageSize'     => $perPage,
                'error'        => $hadError,
                'countMode'    => $countMode,
            ]);
            return;
        }

        // Requête complète : search + options de filtres en parallèle (cold cache)
        // Note: on garde searchWithOptions pour options, mais on remplace la recherche par cursor.
        $combined = $service->searchWithOptions($filters, 1, $perPage);
        $result   = $combined['result'];
        $options  = $combined['options'];
        $platformMap = $this->platformMapFromOptions($options['platforms'] ?? []);

        // Si un cursor est présent au chargement initial (navigation directe),
        // on calcule la recherche cursor (sans recharger les options).
        if ($cursor !== '') {
            $result = $service->searchCursor($filters, $cursor, $perPage);
        } else {
            // Page 1 sans cursor : utiliser aussi cursor pour éviter OFFSET à terme.
            $result = $service->searchCursor($filters, null, $perPage);
        }
        if (!is_array($result)) {
            $result = [];
        }
        $games = is_array($result['games'] ?? null) ? $result['games'] : [];
        $total = (int) ($result['total'] ?? 0);
        $hadError = !empty($result['_error']);
        $countMode = (string) ($result['_count_mode'] ?? 'estimated');

        $pageTitle = $filters['q'] !== ''
            ? 'Recherche : ' . htmlspecialchars($filters['q'])
            : 'Catalogue des jeux';

        // Filtres « actifs » pour le badge FAB / lien Réinitialiser : uniquement la sidebar
        // (pas la recherche `q`, pas le tri par défaut « tous les jeux » (`sort=all`), aligné avec search.js updateFabBadge).
        $activeFilters = $this->sidebarActiveFilters($filters);

        View::render('search/index', [
            'title'         => $pageTitle,
            'cssFile'       => 'search',
            'metaDesc'      => 'Parcourez le catalogue complet de jeux vidéo. Filtrez par plateforme, genre, année et note.',
            'games'         => $games,
            'totalResults'  => $total,
            'filters'       => $filters,
            'filterOptions' => $options,
            'platformMap'   => $platformMap,
            'activeFilters' => $activeFilters,
            'baseUrl'       => $baseUrl,
            'nextCursor'    => $result['next_cursor'] ?? null,
            'pageSize'      => $perPage,
            'error'         => $hadError,
            'countMode'     => $countMode,
            // Important: `search.js` dépend de fonctions globales définies dans `app.js` (cookies + view toggle).
            // On l'injecte donc après `app.js` via le slot `$foot` du layout.
            'foot'          => '<script>window.SEARCH_CONFIG={gameUrl:"/api/games/",suggestUrl:"/api/games/search"};</script>'
                            . '<script src="' . View::asset('js/search.js') . '" defer></script>',
        ]);
    }

    /**
     * Filtres sidebar réellement choisis (pour badge « Filtres » et affichage Réinitialiser).
     */
    private function sidebarActiveFilters(array $filters): array
    {
        $out = [];
        $platforms = $filters['platforms'] ?? [];
        if (is_array($platforms) && !empty($platforms)) {
            $out['platform'] = array_values(array_map('intval', $platforms));
        }

        $genres = $filters['genres'] ?? [];
        if (is_array($genres) && !empty($genres)) {
            $out['genre'] = array_values(array_map('strval', $genres));
        }

        $ratingMin = (int) ($filters['rating_min'] ?? 0);
        if ($ratingMin > 0) {
            $out['rating_min'] = $ratingMin;
        }

        return $out;
    }

    private function buildBaseUrl(array $filters): string
    {
        $params = [];
        foreach ($filters as $k => $v) {
            if ($k === 'q') {
                $v = trim((string) $v);
                if ($v !== '') $params['q'] = $v;
                continue;
            }
            if ($k === 'sort') {
                $sv = is_string($v) ? trim($v) : (string) $v;
                if ($sv !== '' && $sv !== 'all') {
                    $params['sort'] = $sv;
                }
                continue;
            }
            if ($v === '' || $v === 0 || $v === '0' || $v === null) {
                continue;
            }
            // Important: l'UI envoie `platform[]` et `genre[]` (pas `platforms[]/genres[]`).
            // On encode donc l'URL de base avec ces clés pour que la pagination conserve bien les filtres.
            if ($k === 'platforms') {
                if (is_array($v) && !empty($v)) {
                    $params['platform'] = array_values($v); // devient platform[0]=... via http_build_query
                }
                continue;
            }
            if ($k === 'genres') {
                if (is_array($v) && !empty($v)) {
                    $params['genre'] = array_values($v); // devient genre[0]=...
                }
                continue;
            }

            $params[$k] = $v;
        }
        $qs = http_build_query($params);
        return $qs !== '' ? ('/search?' . $qs) : '/search';
    }

    private function platformMap(SearchService $service): array
    {
        $opts = $service->getFilterOptions();
        return $this->platformMapFromOptions($opts['platforms'] ?? []);
    }

    private function platformMapFromOptions(array $platforms): array
    {
        $map = [];
        foreach ($platforms as $p) {
            $id = (int) ($p['id'] ?? 0);
            if ($id <= 0) continue;
            $label = trim((string) ($p['abbreviation'] ?? ''));
            if ($label === '') {
                $fullName = trim((string) ($p['name'] ?? ''));
                $label = PlatformAbbreviations::get($fullName);
            }
            $slug = trim((string) ($p['slug'] ?? ''));
            $name = trim((string) ($p['name'] ?? ''));
            $abbr = trim((string) ($p['abbreviation'] ?? ''));

            if ($label !== '') {
                $map[$id] = [
                    'label' => $label,
                    'slug'  => $slug,
                    'name'  => $name,
                    'abbreviation' => $abbr,
                ];
            }
        }
        return $map;
    }
}

