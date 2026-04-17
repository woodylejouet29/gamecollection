-- Synopsis compressé (zstd) pour réduire la taille disque de `games`.
-- L’application lit `synopsis_zstd` en priorité, puis retombe sur `synopsis` (TEXT) si présent (migration).

ALTER TABLE games
    ADD COLUMN IF NOT EXISTS synopsis_zstd BYTEA;

COMMENT ON COLUMN games.synopsis_zstd IS 'Synopsis IGDB (summary) compressé en zstd ; TEXT synopsis vide si rempli ici.';
