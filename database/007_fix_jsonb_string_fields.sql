-- ================================================================
-- Migration 007 — Fix JSONB stocké en "string JSON"
--
-- Problème:
--   Lors des upserts via PostgREST, des champs JSONB (genres, screenshots, videos, accessories)
--   ont été envoyés sous forme de STRING JSON (via json_encode), ce qui a conduit Postgres
--   à stocker une valeur JSONB de type "string" au lieu d'un tableau/objet JSONB.
--
-- Symptômes:
--   - filtres PostgREST `cs.` (contains) ne matchent jamais
--   - impossible d'utiliser des opérateurs JSONB correctement
--
-- Correctif:
--   - extraire la string interne via #>> '{}' puis caster en jsonb.
-- ================================================================

-- Convertir uniquement les lignes où la valeur JSONB est une string
UPDATE games
SET genres = (genres #>> '{}')::jsonb
WHERE genres IS NOT NULL
  AND jsonb_typeof(genres) = 'string';

UPDATE games
SET screenshots = (screenshots #>> '{}')::jsonb
WHERE screenshots IS NOT NULL
  AND jsonb_typeof(screenshots) = 'string';

UPDATE games
SET videos = (videos #>> '{}')::jsonb
WHERE videos IS NOT NULL
  AND jsonb_typeof(videos) = 'string';

UPDATE games
SET accessories = (accessories #>> '{}')::jsonb
WHERE accessories IS NOT NULL
  AND jsonb_typeof(accessories) = 'string';

