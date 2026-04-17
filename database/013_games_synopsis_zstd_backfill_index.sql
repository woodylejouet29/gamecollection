-- Index partiel pour le backfill synopsis → synopsis_zstd (script migrate_synopsis_zstd.php).
-- Sans cet index, le planificateur peut balayer une grande partie de `games` et dépasser statement_timeout.
--
-- À exécuter dans le SQL Editor Supabase (une seule instruction ; pas besoin de CONCURRENTLY sur un petit verrou court).
-- Si la table est très chargée en prod, vous pouvez préférer en maintenance :
--   CREATE INDEX CONCURRENTLY IF NOT EXISTS … (hors transaction implicite).

CREATE INDEX IF NOT EXISTS idx_games_synopsis_zstd_backfill
    ON public.games (id)
    WHERE synopsis IS NOT NULL
      AND synopsis_zstd IS NULL;
