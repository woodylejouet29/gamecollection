<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Middleware\AuthMiddleware;
use App\Core\Logger;
use App\Services\CollectionService;
use App\Services\CollectionListService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * POST /api/collection/add
 *
 * Corps attendu (JSON) :
 * {
 *   "games": [
 *     {
 *       "game_id":         int,
 *       "platform_id":     int,
 *       "game_version_id": int|null,
 *       "region":          string,   // PAL | NTSC-U | NTSC-J | NTSC-K | other | ""
 *       "game_type":       string,   // physical | digital
 *       "copies": [
 *         {
 *           "status":             string,  // owned | playing | completed | hundred_percent | abandoned
 *           "rank_position":      int,
 *           "acquired_at":        string|"",
 *           "price_paid":         number|"",
 *           "play_time_hours":    int|"",
 *           "play_time_minutes":  int|"",
 *           "physical_condition": string|null,
 *           "condition_note":     string|"",
 *           "has_box":            bool|null,
 *           "has_manual":         bool|null,
 *           "rating":             int|null,
 *           "review_body":        string|""
 *         }
 *       ]
 *     }
 *   ]
 * }
 */
class CollectionApiController
{
    private const VALID_STATUSES = ['owned', 'playing', 'completed', 'hundred_percent', 'abandoned'];
    private const REVIEW_STATUSES = ['completed', 'hundred_percent'];
    private const VALID_TYPES = ['physical', 'digital'];

    public function add(): void
    {
        AuthMiddleware::requireAuth();

        $userId = AuthMiddleware::userId();
        if (!$userId) {
            $this->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED']], 401);
        }

        $raw   = file_get_contents('php://input');
        $input = json_decode($raw ?: '{}', true);

        if (!is_array($input) || empty($input['games']) || !is_array($input['games'])) {
            $this->json([
                'success' => false,
                'error'   => ['code' => 'VALIDATION_ERROR', 'message' => 'Données manquantes ou malformées.'],
            ], 422);
        }

        $service = new CollectionService();
        $created = [];
        $errors  = [];

        foreach ($input['games'] as $gameIdx => $game) {
            $gameId    = (int)  ($game['game_id']    ?? 0);
            $platId    = (int)  ($game['platform_id'] ?? 0);
            $versionId = (isset($game['game_version_id']) && $game['game_version_id'] !== '' && $game['game_version_id'] !== null)
                            ? (int) $game['game_version_id'] : null;
            $region    = $this->normalizeRegion((string) ($game['region']    ?? ''));
            $gameType  = in_array($game['game_type'] ?? '', self::VALID_TYPES, true)
                            ? $game['game_type'] : 'physical';

            if ($gameId <= 0 || $platId <= 0) {
                $errors[] = ['game_idx' => $gameIdx, 'code' => 'VALIDATION_ERROR', 'message' => 'game_id ou platform_id invalide.'];
                continue;
            }

            $copies = is_array($game['copies'] ?? null) ? $game['copies'] : [];
            if (empty($copies)) {
                $errors[] = ['game_idx' => $gameIdx, 'code' => 'VALIDATION_ERROR', 'message' => 'Au moins un exemplaire requis.'];
                continue;
            }

            $gameCreated = [];

            foreach ($copies as $copyIdx => $copy) {
                $status = trim((string) ($copy['status'] ?? 'owned'));
                if (!in_array($status, self::VALID_STATUSES, true)) {
                    $errors[] = ['game_idx' => $gameIdx, 'copy_idx' => $copyIdx, 'code' => 'VALIDATION_ERROR', 'message' => 'Statut invalide.'];
                    continue;
                }

                // Validation note + avis
                $rating     = null;
                $reviewBody = null;

                if (!empty($copy['rating']) || !empty($copy['review_body'])) {
                    if (!in_array($status, self::REVIEW_STATUSES, true)) {
                        $errors[] = ['game_idx' => $gameIdx, 'copy_idx' => $copyIdx, 'code' => 'INVALID_STATUS_FOR_REVIEW', 'message' => 'Note/avis réservés aux statuts Terminé ou 100%.'];
                        continue;
                    }

                    if (!empty($copy['rating'])) {
                        $r = (int) $copy['rating'];
                        if ($r < 1 || $r > 10) {
                            $errors[] = ['game_idx' => $gameIdx, 'copy_idx' => $copyIdx, 'code' => 'VALIDATION_ERROR', 'message' => 'La note doit être entre 1 et 10.'];
                            continue;
                        }
                        $rating = $r;
                    }

                    if (!empty($copy['review_body'])) {
                        $rb = trim((string) $copy['review_body']);
                        if (mb_strlen($rb) < 100) {
                            $errors[] = ['game_idx' => $gameIdx, 'copy_idx' => $copyIdx, 'code' => 'VALIDATION_ERROR', 'message' => 'L\'avis doit contenir au moins 100 caractères.'];
                            continue;
                        }
                        $reviewBody = $rb;
                    }
                }

                // Durée complétion
                $playMinutes = null;
                if (in_array($status, self::REVIEW_STATUSES, true)) {
                    $h = max(0, (int) ($copy['play_time_hours']   ?? 0));
                    $m = max(0, (int) ($copy['play_time_minutes'] ?? 0));
                    if ($h > 0 || $m > 0) {
                        $playMinutes = $h * 60 + $m;
                    }
                }

                // Vérification doublon en base
                if ($service->isDuplicate($userId, $gameId, $platId, $versionId, $region, $gameType)) {
                    $errors[] = ['game_idx' => $gameIdx, 'copy_idx' => $copyIdx, 'code' => 'DUPLICATE_ENTRY', 'message' => 'Cette entrée existe déjà dans votre collection.'];
                    continue;
                }

                $result = $service->addEntry($userId, [
                    'game_id'            => $gameId,
                    'platform_id'        => $platId,
                    'game_version_id'    => $versionId,
                    'region'             => $region,
                    'game_type'          => $gameType,
                    'status'             => $status,
                    'acquired_at'        => $copy['acquired_at']  ?? '',
                    'price_paid'         => $copy['price_paid']   ?? '',
                    'play_time_minutes'  => $playMinutes,
                    'rank_position'      => (int) ($copy['rank_position'] ?? 0),
                    'physical_condition' => ($gameType === 'physical') ? ($copy['physical_condition'] ?? null) : null,
                    'condition_note'     => ($gameType === 'physical') ? ($copy['condition_note']     ?? '') : '',
                    'has_box'            => ($gameType === 'physical' && isset($copy['has_box']))    ? (bool) $copy['has_box']    : null,
                    'has_manual'         => ($gameType === 'physical' && isset($copy['has_manual'])) ? (bool) $copy['has_manual'] : null,
                    'photo_urls'         => ($gameType === 'physical' && !empty($copy['photo_urls']) && is_array($copy['photo_urls']))
                                            ? array_slice(array_values($copy['photo_urls']), 0, 3)
                                            : [],
                ]);

                if ($result['success']) {
                    $entryId = $result['id'];
                    $gameCreated[] = ['game_id' => $gameId, 'entry_id' => $entryId];

                    // Avis (non bloquant en cas d'échec)
                    if ($rating !== null && $reviewBody !== null) {
                        try {
                            $service->addReview($userId, $entryId, $gameId, $rating, $reviewBody);
                        } catch (\Throwable $e) {
                            Logger::warning('addReview failed', ['entry_id' => $entryId, 'error' => $e->getMessage()]);
                        }
                    }
                } else {
                    $errors[] = ['game_idx' => $gameIdx, 'copy_idx' => $copyIdx, 'code' => $result['code'] ?? 'VALIDATION_ERROR'];
                }
            }

            // Retrait wishlist — uniquement si au moins une entrée créée pour ce jeu
            if (!empty($gameCreated)) {
                $created = array_merge($created, $gameCreated);
                try {
                    $service->removeFromWishlist($userId, $gameId);
                } catch (\Throwable $e) {
                    Logger::warning('removeFromWishlist failed', ['game_id' => $gameId, 'error' => $e->getMessage()]);
                }
            }
        }

        if (empty($errors)) {
            $this->json(['success' => true, 'data' => ['created' => $created]]);
        }

        $httpStatus = !empty($created) ? 207 : 422;
        $this->json([
            'success' => !empty($created),
            'data'    => ['created' => $created],
            'errors'  => $errors,
        ], $httpStatus);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────────
    //  PATCH /api/collection/update
    // ──────────────────────────────────────────────────────────────────

    /**
     * Modification rapide d'une entrée.
     * Corps JSON : { entry_id, status?, rank_position?, physical_condition?,
     *               play_time_minutes?, rating?, review_body? }
     */
    public function update(): void
    {
        AuthMiddleware::requireAuth();
        $userId = AuthMiddleware::userId();
        if (!$userId) $this->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED']], 401);

        $raw   = file_get_contents('php://input');
        $input = json_decode($raw ?: '{}', true);

        $entryId = (int) ($input['entry_id'] ?? 0);
        if ($entryId <= 0) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'entry_id requis.']], 422);
        }

        // Validation statut
        $validStatuses = ['owned', 'playing', 'completed', 'hundred_percent', 'abandoned'];
        if (isset($input['status']) && !in_array($input['status'], $validStatuses, true)) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Statut invalide.']], 422);
        }

        // Validation note
        if (!empty($input['rating'])) {
            $r = (int) $input['rating'];
            if ($r < 1 || $r > 10) {
                $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Note invalide (1-10).']], 422);
            }
        }

        // Validation avis
        if (!empty($input['review_body']) && mb_strlen(trim((string) $input['review_body'])) < 100) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'L\'avis doit faire au moins 100 caractères.']], 422);
        }

        $service = new CollectionListService();
        $result  = $service->updateEntry($userId, $input);

        if ($result['success']) {
            $this->json(['success' => true]);
        }
        $this->json(['success' => false, 'error' => ['code' => $result['code'] ?? 'UPDATE_FAILED']], 422);
    }

    // ──────────────────────────────────────────────────────────────────
    //  DELETE /api/collection/delete
    // ──────────────────────────────────────────────────────────────────

    public function delete(): void
    {
        AuthMiddleware::requireAuth();
        $userId = AuthMiddleware::userId();
        if (!$userId) $this->json(['success' => false, 'error' => ['code' => 'UNAUTHORIZED']], 401);

        $raw     = file_get_contents('php://input');
        $input   = json_decode($raw ?: '{}', true);
        $entryId = (int) ($input['entry_id'] ?? 0);

        if ($entryId <= 0) {
            $this->json(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR']], 422);
        }

        $service = new CollectionListService();
        $ok      = $service->deleteEntry($userId, $entryId);

        $this->json(['success' => $ok], $ok ? 200 : 500);
    }

    // ──────────────────────────────────────────────────────────────────
    //  GET /api/collection/export
    // ──────────────────────────────────────────────────────────────────

    public function export(): void
    {
        AuthMiddleware::requireAuth();
        $userId = AuthMiddleware::userId();
        if (!$userId) {
            http_response_code(401);
            exit;
        }

        $format = strtolower(trim($_GET['format'] ?? 'json'));
        if (!in_array($format, ['json', 'csv'], true)) $format = 'json';

        $service = new CollectionListService();
        $entries = $service->exportAll($userId);

        $filename = 'collection-' . date('Y-m-d') . '.' . $format;
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;
        }

        // CSV
        header('Content-Type: text/csv; charset=utf-8');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'ID', 'Jeu', 'Plateforme', 'Région', 'Type', 'Statut', 'Note',
            'État physique', 'Rang', 'Date acquisition', 'Prix payé (€)',
            'Temps de jeu (min)', 'Boîte', 'Manuel', 'Date ajout',
        ], ';');

        foreach ($entries as $e) {
            fputcsv($out, [
                $e['id'],
                $e['game']['title']         ?? '',
                $e['platform']['name']      ?? '',
                $e['region']                ?? '',
                $e['game_type']             ?? '',
                $e['status']                ?? '',
                $e['review']['rating']      ?? '',
                $e['physical_condition']    ?? '',
                $e['rank_position']         ?? '',
                $e['acquired_at']           ?? '',
                $e['price_paid'] !== null   ? number_format((float) $e['price_paid'], 2, '.', '') : '',
                $e['play_time_minutes']     ?? '',
                $e['has_box']  !== null     ? ($e['has_box']    ? 'Oui' : 'Non') : '',
                $e['has_manual'] !== null   ? ($e['has_manual'] ? 'Oui' : 'Non') : '',
                $e['created_at']            ?? '',
            ], ';');
        }
        fclose($out);
        exit;
    }

    // ──────────────────────────────────────────────────────────────────
    //  GET /api/collection/export-xlsx-by-platform
    // ──────────────────────────────────────────────────────────────────

    public function exportByPlatformXlsx(): void
    {
        AuthMiddleware::requireAuth();
        $userId = AuthMiddleware::userId();
        if (!$userId) {
            http_response_code(401);
            exit;
        }

        // Filtres identiques à /collection
        $filters = [
            'list'      => trim((string) ($_GET['list'] ?? 'all')) ?: 'all',
            'platform'  => (int) ($_GET['platform'] ?? 0),
            'status'    => trim((string) ($_GET['status'] ?? '')),
            'game_type' => trim((string) ($_GET['game_type'] ?? '')),
            'region'    => trim((string) ($_GET['region'] ?? '')),
            'condition' => trim((string) ($_GET['condition'] ?? '')),
            'q'         => trim((string) ($_GET['q'] ?? '')),
            'sort'      => trim((string) ($_GET['sort'] ?? 'recent')) ?: 'recent',
        ];

        $service = new CollectionListService();
        $entries = $service->exportFiltered($userId, $filters);

        // Grouper par plateforme
        $byPlatform = [];
        foreach ($entries as $e) {
            $pid  = (int) ($e['platform_id'] ?? 0);
            $abbr = trim((string) ($e['platform']['abbreviation'] ?? ''));
            $name = trim((string) ($e['platform']['name'] ?? ''));
            $label = $abbr !== '' ? $abbr : ($name !== '' ? $name : ('Plateforme ' . $pid));
            if ($pid <= 0) {
                $pid = 0;
                $label = 'Inconnu';
            }
            if (!isset($byPlatform[$pid])) {
                $byPlatform[$pid] = ['label' => $label, 'rows' => []];
            }
            $byPlatform[$pid]['rows'][] = $e;
        }

        // Trie des onglets par label
        uasort($byPlatform, static function ($a, $b) {
            return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        });

        $spreadsheet = new Spreadsheet();
        // Retirer la feuille par défaut (on la remplacera)
        $spreadsheet->removeSheetByIndex(0);

        $usedNames = [];
        foreach ($byPlatform as $group) {
            $sheetName = $this->makeExcelSheetName((string)($group['label'] ?? ''), $usedNames);
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($sheetName);

            // En-têtes (identiques au CSV)
            $headers = [
                'ID', 'Jeu', 'Plateforme', 'Région', 'Type', 'Statut', 'Note',
                'État physique', 'Rang', 'Date acquisition', 'Prix payé (€)',
                'Temps de jeu (min)', 'Boîte', 'Manuel', 'Date ajout',
            ];
            $sheet->fromArray($headers, null, 'A1');
            $sheet->freezePane('A2');

            $r = 2;
            foreach (($group['rows'] ?? []) as $e) {
                $sheet->fromArray([
                    $e['id'] ?? '',
                    $e['game']['title']         ?? '',
                    ($e['platform']['abbreviation'] ?? '') ?: ($e['platform']['name'] ?? ''),
                    $e['region']                ?? '',
                    $e['game_type']             ?? '',
                    $e['status']                ?? '',
                    $e['review']['rating']      ?? '',
                    $e['physical_condition']    ?? '',
                    $e['rank_position']         ?? '',
                    $e['acquired_at']           ?? '',
                    $e['price_paid'] !== null   ? number_format((float) $e['price_paid'], 2, '.', '') : '',
                    $e['play_time_minutes']     ?? '',
                    $e['has_box']  !== null     ? ($e['has_box']    ? 'Oui' : 'Non') : '',
                    $e['has_manual'] !== null   ? ($e['has_manual'] ? 'Oui' : 'Non') : '',
                    $e['created_at']            ?? '',
                ], null, 'A' . $r);
                $r++;
            }

            // Auto-size simple
            foreach (range('A', 'O') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        if ($spreadsheet->getSheetCount() === 0) {
            // Toujours fournir un fichier valide (même vide)
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Collection');
            $sheet->fromArray(['Aucune entrée'], null, 'A1');
        } else {
            $spreadsheet->setActiveSheetIndex(0);
        }

        $filename = 'collection-par-plateforme-' . date('Y-m-d') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Génère un nom de feuille Excel valide (≤31 chars, sans caractères interdits).
     * Dédoublonne en ajoutant " (2)", " (3)", etc.
     *
     * @param array<string,bool> $used
     */
    private function makeExcelSheetName(string $label, array &$used): string
    {
        $name = trim($label) !== '' ? trim($label) : 'Plateforme';
        $name = str_replace(['[',']',':','*','?','/','\\'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = trim($name);
        if ($name === '') $name = 'Plateforme';
        // Excel: 31 caractères max
        if (mb_strlen($name, 'UTF-8') > 31) {
            $name = mb_substr($name, 0, 31, 'UTF-8');
        }

        $base = $name;
        $i = 2;
        while (isset($used[$name])) {
            $suffix = " ({$i})";
            $maxBaseLen = 31 - mb_strlen($suffix, 'UTF-8');
            $trimmed = $base;
            if (mb_strlen($trimmed, 'UTF-8') > $maxBaseLen) {
                $trimmed = mb_substr($trimmed, 0, max(1, $maxBaseLen), 'UTF-8');
            }
            $name = $trimmed . $suffix;
            $i++;
        }
        $used[$name] = true;
        return $name;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    private function normalizeRegion(string $r): string
    {
        return match($r) {
            'PAL'    => 'PAL',
            'NTSC-U' => 'NTSC-U',
            'NTSC-J' => 'NTSC-J',
            'NTSC-K' => 'NTSC-K',
            default  => 'other',
        };
    }

    private function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
