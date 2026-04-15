-- 011 — Champs IGDB "légers" pour éviter de stocker raw_igdb_data (TOAST).
-- Objectif: filtrer DLC/expansions et garder quelques infos sans JSON brut.

ALTER TABLE public.games
  ADD COLUMN IF NOT EXISTS igdb_category INTEGER NOT NULL DEFAULT 0;

ALTER TABLE public.games
  ADD COLUMN IF NOT EXISTS parent_game_igdb_id INTEGER;

-- Index utiles pour les filtres PostgREST (recherche)
CREATE INDEX IF NOT EXISTS idx_games_parent_game_igdb_id
  ON public.games (parent_game_igdb_id);

CREATE INDEX IF NOT EXISTS idx_games_igdb_category
  ON public.games (igdb_category);

