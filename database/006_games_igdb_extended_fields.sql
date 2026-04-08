-- ================================================================
--  006_games_igdb_extended_fields.sql
--  Ajoute les colonnes récupérées via les GAME_FIELDS complets IGDB.
--  Exécuter dans : Supabase Dashboard → SQL Editor
--
--  Note : la colonne platform_ids (integer[]) et son index GIN ont déjà
--  été créés par la migration 005. Ce fichier ne les touche pas.
-- ================================================================

ALTER TABLE games
    -- Note de la presse / critiques agrégées (Metacritic, OpenCritic…)
    ADD COLUMN IF NOT EXISTS aggregated_rating  DECIMAL(5,2),

    -- Note totale (moyenne pondérée IGDB rating + aggregated_rating)
    ADD COLUMN IF NOT EXISTS total_rating       DECIMAL(5,2),

    -- Synopsis long (storyline), distinct du résumé court (synopsis = summary IGDB)
    ADD COLUMN IF NOT EXISTS storyline          TEXT;

COMMENT ON COLUMN games.aggregated_rating IS 'Note agrégée presse (Metacritic/OpenCritic via IGDB)';
COMMENT ON COLUMN games.total_rating      IS 'Note totale IGDB (moyenne pondérée utilisateurs + presse)';
COMMENT ON COLUMN games.storyline         IS 'Récit long du jeu (distinct de synopsis = summary IGDB)';
