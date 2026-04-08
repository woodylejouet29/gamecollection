-- ================================================================
-- 008 — raw_igdb_data : JSONB objet (pas une string JSON)
--
-- Symptôme : raw_igdb_data avait été upserté via json_encode() côté PHP,
-- ce qui produit un JSONB scalaire de type string. Les filtres du type
-- raw_igdb_data->version_parent ne fonctionnent pas (éditions Deluxe, etc.).
--
-- Corrigé dans IgdbSync (envoi du tableau $g). Cette migration répare l’existant.
--
-- IMPORTANT — timeout Supabase : ne pas tout mettre à jour d’un coup.
-- Exécute le bloc ci‑dessous PLUSIEURS FOIS (bouton Run) jusqu’à ce que
-- le message indique « UPDATE 0 » (ou plus aucune ligne à convertir).
-- Tu peux baisser la limite (ex. 100) si ça timeoute encore.
-- ================================================================

WITH todo AS (
    SELECT id
    FROM games
    WHERE raw_igdb_data IS NOT NULL
      AND jsonb_typeof(raw_igdb_data) = 'string'
    ORDER BY id
    LIMIT 250
)
UPDATE games AS g
SET raw_igdb_data = (g.raw_igdb_data #>> '{}')::jsonb
FROM todo
WHERE g.id = todo.id;

-- Vérification (optionnel) : doit retourner 0 quand c’est fini
-- SELECT COUNT(*) FROM games
-- WHERE raw_igdb_data IS NOT NULL AND jsonb_typeof(raw_igdb_data) = 'string';
