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
        } elseif (in_array($sortGet, ['recent', 'upcoming', 'rating', 'release_asc'], true)) {
            $sort = $sortGet;
        } else {
            $sort = 'all';
        }

        // ── Filtre date de sortie (presets + personnalisé) ──
        $releasePreset = trim((string) ($_GET['release_preset'] ?? ''));
        $allowedPresets = ['', 'this_month', 'last_3_months', 'this_year', 'last_year', 'custom'];
        if (!in_array($releasePreset, $allowedPresets, true)) {
            $releasePreset = '';
        }

        $releaseMode = trim((string) ($_GET['release_mode'] ?? 'year'));
        if (!in_array($releaseMode, ['year', 'month', 'date'], true)) {
            $releaseMode = 'year';
        }

        $releaseYearFrom  = trim((string) ($_GET['release_year_from'] ?? ''));
        $releaseYearTo    = trim((string) ($_GET['release_year_to'] ?? ''));
        $releaseMonthFrom = trim((string) ($_GET['release_month_from'] ?? ''));
        $releaseMonthTo   = trim((string) ($_GET['release_month_to'] ?? ''));
        $releaseDateFrom  = trim((string) ($_GET['release_date_from'] ?? ''));
        $releaseDateTo    = trim((string) ($_GET['release_date_to'] ?? ''));

        $releaseIncludeUnknown = !empty($_GET['release_include_unknown']) && (string) $_GET['release_include_unknown'] !== '0';

        [$releaseFrom, $releaseTo] = $this->resolveReleaseBounds(
            $releasePreset,
            $releaseMode,
            $releaseYearFrom,
            $releaseYearTo,
            $releaseMonthFrom,
            $releaseMonthTo,
            $releaseDateFrom,
            $releaseDateTo
        );

        $filters = [
            'q'          => trim($_GET['q']          ?? ''),
            'platforms'  => $platforms,
            'genres'     => $genres,
            'rating_min' => (int) ($_GET['rating_min'] ?? 0),
            'sort'       => $sort,

            // Date de sortie (raw + bornes normalisées pour SearchService)
            'release_preset' => $releasePreset,
            'release_mode'   => $releaseMode,
            'release_year_from'  => $releaseYearFrom,
            'release_year_to'    => $releaseYearTo,
            'release_month_from' => $releaseMonthFrom,
            'release_month_to'   => $releaseMonthTo,
            'release_date_from'  => $releaseDateFrom,
            'release_date_to'    => $releaseDateTo,
            'release_include_unknown' => $releaseIncludeUnknown ? 1 : 0,
            'release_from'  => $releaseFrom,
            'release_to'    => $releaseTo,
        ];

        $perPage = 42;
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
                            . '<script src="' . View::asset('js/collection-release.js') . '" defer></script>'
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

        // Date de sortie : 1 seul "badge" (peu importe le niveau de précision)
        $preset = trim((string) ($filters['release_preset'] ?? ''));
        $hasCustom = false;
        if ($preset === 'custom') {
            foreach ([
                'release_year_from', 'release_year_to',
                'release_month_from', 'release_month_to',
                'release_date_from', 'release_date_to',
            ] as $k) {
                if (trim((string) ($filters[$k] ?? '')) !== '') {
                    $hasCustom = true;
                    break;
                }
            }
        }
        $includeUnknown = !empty($filters['release_include_unknown']);
        if ($preset !== '' || $hasCustom || $includeUnknown) {
            $out['release'] = 1;
        }

        return $out;
    }

    private function buildBaseUrl(array $filters): string
    {
        $params = [];
        $releasePreset = trim((string) ($filters['release_preset'] ?? ''));
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

            // Date de sortie : conserver les champs d'UI (pas les bornes normalisées)
            if ($k === 'release_from' || $k === 'release_to') {
                continue;
            }
            if ($k === 'release_include_unknown') {
                if (!empty($v)) {
                    $params['release_include_unknown'] = 1;
                }
                continue;
            }
            if ($k === 'release_mode') {
                if ($releasePreset === 'custom') {
                    $sv = trim((string) $v);
                    if ($sv !== '') $params['release_mode'] = $sv;
                }
                continue;
            }
            if (in_array($k, [
                'release_preset',
                'release_year_from', 'release_year_to',
                'release_month_from', 'release_month_to',
                'release_date_from', 'release_date_to',
            ], true)) {
                $sv = trim((string) $v);
                if ($sv !== '') {
                    // Ne conserver les champs détaillés que si on est en mode "custom"
                    if ($k === 'release_preset') {
                        $params[$k] = $sv;
                    } elseif ($releasePreset === 'custom') {
                        $params[$k] = $sv;
                    }
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

    /**
     * Calcule des bornes [from, to] (YYYY-MM-DD) à partir des paramètres UI.
     *
     * @return array{0:string,1:string}
     */
    private function resolveReleaseBounds(
        string $preset,
        string $mode,
        string $yearFrom,
        string $yearTo,
        string $monthFrom,
        string $monthTo,
        string $dateFrom,
        string $dateTo
    ): array {
        $from = '';
        $to   = '';

        $today = new \DateTimeImmutable('today');

        if ($preset === '' ) {
            return [$from, $to];
        }

        if ($preset !== 'custom') {
            switch ($preset) {
                case 'this_month': {
                    $start = $today->modify('first day of this month');
                    $end   = $today->modify('last day of this month');
                    $from = $start->format('Y-m-d');
                    $to   = $end->format('Y-m-d');
                    break;
                }
                case 'last_3_months': {
                    // Mois courant inclus : [début mois -2] → [fin mois courant]
                    $start = $today->modify('first day of this month')->modify('-2 months');
                    $end   = $today->modify('last day of this month');
                    $from = $start->format('Y-m-d');
                    $to   = $end->format('Y-m-d');
                    break;
                }
                case 'this_year': {
                    $y = (int) $today->format('Y');
                    $from = sprintf('%04d-01-01', $y);
                    $to   = sprintf('%04d-12-31', $y);
                    break;
                }
                case 'last_year': {
                    $y = (int) $today->format('Y') - 1;
                    $from = sprintf('%04d-01-01', $y);
                    $to   = sprintf('%04d-12-31', $y);
                    break;
                }
                default:
                    return ['', ''];
            }
            return [$from, $to];
        }

        // custom
        if ($mode === 'year') {
            $yf = preg_match('/^\d{4}$/', $yearFrom) ? (int) $yearFrom : 0;
            $yt = preg_match('/^\d{4}$/', $yearTo) ? (int) $yearTo : 0;
            if ($yf === 0 && $yt === 0) return ['', ''];
            if ($yf === 0) $yf = $yt;
            if ($yt === 0) $yt = $yf;
            if ($yf > $yt) [$yf, $yt] = [$yt, $yf];
            $from = sprintf('%04d-01-01', $yf);
            $to   = sprintf('%04d-12-31', $yt);
        } elseif ($mode === 'month') {
            $mf = preg_match('/^\d{4}-\d{2}$/', $monthFrom) ? $monthFrom : '';
            $mt = preg_match('/^\d{4}-\d{2}$/', $monthTo) ? $monthTo : '';
            if ($mf === '' && $mt === '') return ['', ''];
            if ($mf === '') $mf = $mt;
            if ($mt === '') $mt = $mf;
            if ($mf > $mt) [$mf, $mt] = [$mt, $mf];
            $start = \DateTimeImmutable::createFromFormat('Y-m-d', $mf . '-01') ?: null;
            $endBase = \DateTimeImmutable::createFromFormat('Y-m-d', $mt . '-01') ?: null;
            if (!$start || !$endBase) return ['', ''];
            $end = $endBase->modify('last day of this month');
            $from = $start->format('Y-m-d');
            $to   = $end->format('Y-m-d');
        } else { // date
            $df = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : '';
            $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : '';
            if ($df === '' && $dt === '') return ['', ''];
            if ($df === '') $df = $dt;
            if ($dt === '') $dt = $df;
            if ($df > $dt) [$df, $dt] = [$dt, $df];
            $from = $df;
            $to   = $dt;
        }

        return [$from, $to];
    }
}

