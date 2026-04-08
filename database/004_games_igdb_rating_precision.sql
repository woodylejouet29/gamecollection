-- ================================================================
--  004_games_igdb_rating_precision.sql
--  IGDB renvoie `rating` sur une échelle 0–100 ; DECIMAL(4,2)
--  n’accepte que < 100 → erreur 22003 sur les jeux notés 100.
--  Exécuter dans : Supabase Dashboard → SQL Editor
-- ================================================================

ALTER TABLE games
    ALTER COLUMN igdb_rating TYPE DECIMAL(5,2);
