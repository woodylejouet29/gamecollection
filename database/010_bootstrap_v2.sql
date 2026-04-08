-- ================================================================
-- 010_bootstrap_v2.sql — Nouveau projet (perf + 300k+ games)
--
-- À exécuter dans : Supabase Dashboard → SQL Editor
-- Objectif :
--   - éviter OFFSET profond, counts exact, filtres JSONB non indexés
--   - rendre la recherche rapide (GIN/trgm + arrays + indexes de tri)
--   - éviter les migrations "fix jsonb_typeof(...)" en stockant les champs correctement
-- ================================================================

-- Extensions
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ----------------------------------------------------------------
-- ENUM TYPES
-- ----------------------------------------------------------------
DO $$ BEGIN
    CREATE TYPE game_type     AS ENUM ('physical', 'digital');
    CREATE TYPE game_region   AS ENUM ('PAL', 'NTSC-U', 'NTSC-J', 'NTSC-K', 'other');
    CREATE TYPE game_status   AS ENUM ('owned', 'playing', 'completed', 'hundred_percent', 'abandoned', 'wishlist');
    CREATE TYPE physical_cond AS ENUM ('mint', 'near_mint', 'very_good', 'good', 'acceptable', 'poor', 'damaged', 'incomplete');
    CREATE TYPE log_level     AS ENUM ('debug', 'info', 'warning', 'error', 'critical');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- ----------------------------------------------------------------
-- PLATFORMS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS platforms (
    id             BIGSERIAL    PRIMARY KEY,
    igdb_id        INTEGER      UNIQUE,
    name           VARCHAR(120) NOT NULL,
    slug           VARCHAR(120) UNIQUE,
    abbreviation   VARCHAR(30),
    logo_url       TEXT,
    generation     SMALLINT,
    manufacturer   VARCHAR(100),
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------
-- USERS (extension de auth.users de Supabase)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                UUID         PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    username          VARCHAR(30)  UNIQUE NOT NULL,
    avatar_url        TEXT,
    bio               TEXT,
    collection_public BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------
-- USER_PLATFORMS / USER_GENRES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_platforms (
    id          BIGSERIAL   PRIMARY KEY,
    user_id     UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    platform_id BIGINT      NOT NULL REFERENCES platforms(id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, platform_id)
);

CREATE TABLE IF NOT EXISTS user_genres (
    id            BIGSERIAL   PRIMARY KEY,
    user_id       UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    igdb_genre_id INTEGER     NOT NULL,
    genre_name    VARCHAR(80) NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, igdb_genre_id)
);

-- ----------------------------------------------------------------
-- GAMES — catalogue (alimenté via IGDB)
-- Notes perf :
--   - platform_ids (int[]) pour filtrer vite via GIN
--   - version_parent_igdb_id (int) pour éviter des WHERE sur JSONB raw_igdb_data
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS games (
    id                    BIGSERIAL    PRIMARY KEY,
    igdb_id               INTEGER      UNIQUE NOT NULL,
    title                 VARCHAR(255) NOT NULL,
    slug                  VARCHAR(255) UNIQUE NOT NULL,
    synopsis              TEXT,
    storyline             TEXT,
    cover_url             TEXT,
    cover_local           TEXT,
    igdb_rating           DECIMAL(5,2),
    igdb_rating_count     INTEGER,
    aggregated_rating     DECIMAL(5,2),
    total_rating          DECIMAL(5,2),
    release_date          DATE,
    developer             VARCHAR(150),
    publisher             VARCHAR(150),
    genres                JSONB        NOT NULL DEFAULT '[]',
    screenshots           JSONB        NOT NULL DEFAULT '[]',
    videos                JSONB        NOT NULL DEFAULT '[]',
    accessories           JSONB        NOT NULL DEFAULT '[]',
    raw_igdb_data         JSONB,
    version_parent_igdb_id INTEGER,
    platform_ids          INTEGER[]    NOT NULL DEFAULT '{}',
    cached_at             TIMESTAMPTZ,
    created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------
-- GAME_PLATFORMS — jeu × plateforme
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_platforms (
    id           BIGSERIAL   PRIMARY KEY,
    game_id      BIGINT      NOT NULL REFERENCES games(id) ON DELETE CASCADE,
    platform_id  BIGINT      NOT NULL REFERENCES platforms(id) ON DELETE CASCADE,
    region       game_region,
    release_date DATE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (game_id, platform_id, region)
);

-- ----------------------------------------------------------------
-- GAME_VERSIONS — éditions (Standard, GOTY, Deluxe, etc.)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_versions (
    id          BIGSERIAL    PRIMARY KEY,
    game_id     BIGINT       NOT NULL REFERENCES games(id) ON DELETE CASCADE,
    igdb_id     INTEGER      UNIQUE,
    name        VARCHAR(150) NOT NULL,
    description TEXT,
    cover_url   TEXT,
    is_dlc      BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------
-- COLLECTION / REVIEWS / WISHLIST / LOGS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collection_entries (
    id                 BIGSERIAL     PRIMARY KEY,
    user_id            UUID          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    game_id            BIGINT        NOT NULL REFERENCES games(id),
    platform_id        BIGINT        NOT NULL REFERENCES platforms(id),
    game_version_id    BIGINT        REFERENCES game_versions(id),
    region             game_region   NOT NULL,
    game_type          game_type     NOT NULL,
    status             game_status   NOT NULL DEFAULT 'owned',
    acquired_at        DATE,
    price_paid         DECIMAL(8,2),
    play_time_minutes  INTEGER,
    rank_position      INTEGER        NOT NULL DEFAULT 0,
    physical_condition physical_cond,
    condition_note     VARCHAR(500),
    has_box            BOOLEAN,
    has_manual         BOOLEAN,
    created_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_collection_entry
        UNIQUE (user_id, game_id, platform_id, game_version_id, region, game_type)
);

CREATE TABLE IF NOT EXISTS reviews (
    id         BIGSERIAL   PRIMARY KEY,
    user_id    UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    entry_id   BIGINT      NOT NULL REFERENCES collection_entries(id) ON DELETE CASCADE,
    game_id    BIGINT      NOT NULL REFERENCES games(id),
    rating     SMALLINT    NOT NULL CHECK (rating BETWEEN 1 AND 10),
    body       TEXT        NOT NULL CHECK (char_length(body) >= 100),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, entry_id)
);

CREATE TABLE IF NOT EXISTS wishlist (
    id         BIGSERIAL   PRIMARY KEY,
    user_id    UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    game_id    BIGINT      NOT NULL REFERENCES games(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, game_id)
);

CREATE TABLE IF NOT EXISTS logs (
    id         BIGSERIAL   PRIMARY KEY,
    level      log_level   NOT NULL DEFAULT 'info',
    message    TEXT        NOT NULL,
    context    JSONB       NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------
-- INDEXES — recherche / tri / filtres (300k+)
-- ----------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_games_slug            ON games(slug);
CREATE INDEX IF NOT EXISTS idx_games_igdb_id         ON games(igdb_id);
CREATE INDEX IF NOT EXISTS idx_games_cached_at       ON games(cached_at DESC NULLS LAST);

-- Tri stable (keyset pagination)
CREATE INDEX IF NOT EXISTS idx_games_release_sort
  ON games (release_date DESC NULLS LAST, id DESC);
CREATE INDEX IF NOT EXISTS idx_games_rating_sort
  ON games (igdb_rating DESC NULLS LAST, id DESC);
CREATE INDEX IF NOT EXISTS idx_games_title_sort
  ON games (title ASC, id DESC);

-- Recherche texte + filtres
CREATE INDEX IF NOT EXISTS idx_games_title_trgm
  ON games USING GIN(title gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_games_platform_ids
  ON games USING GIN(platform_ids);
CREATE INDEX IF NOT EXISTS idx_games_genres_gin
  ON games USING GIN(genres);

-- Edition siblings (au lieu de raw_igdb_data->version_parent)
CREATE INDEX IF NOT EXISTS idx_games_version_parent_igdb_id
  ON games (version_parent_igdb_id);

-- Pivots / joins
CREATE INDEX IF NOT EXISTS idx_gp_platform_id ON game_platforms(platform_id);
CREATE INDEX IF NOT EXISTS idx_gp_game_id     ON game_platforms(game_id);

-- UX / compteurs
CREATE INDEX IF NOT EXISTS idx_wishlist_game  ON wishlist(game_id);
CREATE INDEX IF NOT EXISTS idx_reviews_game   ON reviews(game_id);
CREATE INDEX IF NOT EXISTS idx_logs_level     ON logs(level);
CREATE INDEX IF NOT EXISTS idx_logs_created   ON logs(created_at DESC);

-- ----------------------------------------------------------------
-- Trigger updated_at automatique
-- ----------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;

DO $$ DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['users','games','collection_entries','reviews'] LOOP
        EXECUTE format(
            'CREATE OR REPLACE TRIGGER trg_%s_updated_at
             BEFORE UPDATE ON %s
             FOR EACH ROW EXECUTE FUNCTION set_updated_at()',
            t, t
        );
    END LOOP;
END $$;

-- ----------------------------------------------------------------
-- Trigger sync platform_ids (dérivé de game_platforms)
-- ----------------------------------------------------------------
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

