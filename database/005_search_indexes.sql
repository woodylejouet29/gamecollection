-- ================================================================
-- Migration 005 — Optimisations de la recherche
--
-- ⚠ À exécuter en 3 étapes dans le SQL Editor Supabase.
--   Copier-coller chaque bloc séparément.
-- ================================================================


-- ================================================================
-- ÉTAPE 1 — Extension + colonne + index
-- (rapide, ~5 secondes)
-- ================================================================

CREATE EXTENSION IF NOT EXISTS pg_trgm;

ALTER TABLE games
    ADD COLUMN IF NOT EXISTS platform_ids integer[] NOT NULL DEFAULT '{}';

CREATE INDEX IF NOT EXISTS idx_games_title_trgm
    ON games USING GIN(title gin_trgm_ops);

CREATE INDEX IF NOT EXISTS idx_games_release_date
    ON games(release_date);

CREATE INDEX IF NOT EXISTS idx_games_igdb_rating
    ON games(igdb_rating);

CREATE INDEX IF NOT EXISTS idx_games_genres_gin
    ON games USING GIN(genres);

CREATE INDEX IF NOT EXISTS idx_games_platform_ids
    ON games USING GIN(platform_ids);

CREATE INDEX IF NOT EXISTS idx_gp_platform_id
    ON game_platforms(platform_id);

CREATE INDEX IF NOT EXISTS idx_gp_game_id
    ON game_platforms(game_id);


-- ================================================================
-- ÉTAPE 2 — Backfill platform_ids (données existantes)
-- Version optimisée : 1 seul GROUP BY + 1 UPDATE jointure
-- (quelques secondes même sur 100 000 jeux)
-- ================================================================

UPDATE games g
SET platform_ids = sq.pids
FROM (
    SELECT
        game_id,
        array_agg(platform_id ORDER BY platform_id) AS pids
    FROM game_platforms
    GROUP BY game_id
) sq
WHERE g.id = sq.game_id;


-- ================================================================
-- ÉTAPE 3 — Trigger de synchronisation automatique
-- (maintient platform_ids à jour lors des futurs syncs IGDB)
-- ================================================================

CREATE OR REPLACE FUNCTION fn_sync_game_platform_ids()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        UPDATE games
        SET platform_ids = COALESCE(
            ARRAY(
                SELECT platform_id
                FROM game_platforms
                WHERE game_id = OLD.game_id
                ORDER BY platform_id
            ),
            '{}'
        )
        WHERE id = OLD.game_id;
        RETURN OLD;
    ELSE
        UPDATE games
        SET platform_ids = COALESCE(
            ARRAY(
                SELECT platform_id
                FROM game_platforms
                WHERE game_id = NEW.game_id
                ORDER BY platform_id
            ),
            '{}'
        )
        WHERE id = NEW.game_id;
        RETURN NEW;
    END IF;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sync_platform_ids ON game_platforms;

CREATE TRIGGER trg_sync_platform_ids
AFTER INSERT OR UPDATE OR DELETE ON game_platforms
FOR EACH ROW EXECUTE FUNCTION fn_sync_game_platform_ids();
