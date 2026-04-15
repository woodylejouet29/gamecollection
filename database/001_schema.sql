-- ================================================================
--  001_schema.sql — Schéma complet (Supabase / PostgreSQL)
--  Exécuter dans : Supabase Dashboard → SQL Editor
-- ================================================================

-- Extension UUID (déjà présente dans Supabase, par sécurité)
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ----------------------------------------------------------------
--  ENUM TYPES
-- ----------------------------------------------------------------
DO $$ BEGIN
    CREATE TYPE game_type     AS ENUM ('physical', 'digital');
    CREATE TYPE game_region   AS ENUM ('PAL', 'NTSC-U', 'NTSC-J', 'NTSC-K', 'other');
    CREATE TYPE game_status   AS ENUM ('owned', 'playing', 'completed', 'hundred_percent', 'abandoned', 'wishlist');
    CREATE TYPE physical_cond AS ENUM ('mint', 'near_mint', 'very_good', 'good', 'acceptable', 'poor', 'damaged', 'incomplete');
    CREATE TYPE log_level     AS ENUM ('debug', 'info', 'warning', 'error', 'critical');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- ----------------------------------------------------------------
--  PLATFORMS
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
--  USERS (extension de auth.users de Supabase)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id               UUID         PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
    username         VARCHAR(30)  UNIQUE NOT NULL,
    avatar_url       TEXT,
    bio              TEXT,
    collection_public BOOLEAN     NOT NULL DEFAULT FALSE,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------
--  USER_PLATFORMS — plateformes préférées d'un utilisateur
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_platforms (
    id          BIGSERIAL   PRIMARY KEY,
    user_id     UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    platform_id BIGINT      NOT NULL REFERENCES platforms(id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, platform_id)
);

-- ----------------------------------------------------------------
--  USER_GENRES — genres préférés d'un utilisateur
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_genres (
    id         BIGSERIAL   PRIMARY KEY,
    user_id    UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    igdb_genre_id INTEGER  NOT NULL,
    genre_name VARCHAR(80) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, igdb_genre_id)
);

-- ----------------------------------------------------------------
--  GAMES — catalogue (alimenté via IGDB)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS games (
    id              BIGSERIAL    PRIMARY KEY,
    igdb_id         INTEGER      UNIQUE NOT NULL,
    title           VARCHAR(255) NOT NULL,
    slug            VARCHAR(255) UNIQUE NOT NULL,
    igdb_category   INTEGER      NOT NULL DEFAULT 0,
    parent_game_igdb_id INTEGER,
    synopsis        TEXT,
    cover_url       TEXT,
    cover_local     TEXT,             -- chemin WebP local
    igdb_rating     DECIMAL(5,2),   -- 0–100 IGDB (4,2 débordait à 100)
    igdb_rating_count INTEGER,
    release_date    DATE,
    developer       VARCHAR(150),
    publisher       VARCHAR(150),
    genres          JSONB        NOT NULL DEFAULT '[]',
    screenshots     JSONB        NOT NULL DEFAULT '[]',
    videos          JSONB        NOT NULL DEFAULT '[]',
    accessories     JSONB        NOT NULL DEFAULT '[]',
    raw_igdb_data   JSONB,
    cached_at       TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------
--  GAME_PLATFORMS — jeu × plateforme
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS game_platforms (
    id          BIGSERIAL   PRIMARY KEY,
    game_id     BIGINT      NOT NULL REFERENCES games(id) ON DELETE CASCADE,
    platform_id BIGINT      NOT NULL REFERENCES platforms(id) ON DELETE CASCADE,
    region      game_region,
    release_date DATE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (game_id, platform_id, region)
);

-- ----------------------------------------------------------------
--  GAME_VERSIONS — éditions (Standard, GOTY, Deluxe, etc.)
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
--  COLLECTION_ENTRIES — entrées de collection
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
    play_time_minutes  INTEGER,        -- renseigné si statut = completed / hundred_percent
    rank_position      INTEGER        NOT NULL DEFAULT 0,

    -- Champs physiques uniquement
    physical_condition physical_cond,
    condition_note     VARCHAR(500),
    has_box            BOOLEAN,
    has_manual         BOOLEAN,

    created_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW(),

    -- Unicité : un exemplaire unique par (user, jeu, plateforme, version, région, type)
    CONSTRAINT uq_collection_entry
        UNIQUE (user_id, game_id, platform_id, game_version_id, region, game_type)
);

-- ----------------------------------------------------------------
--  COLLECTION_PHOTOS — photos d'exemplaires physiques
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collection_photos (
    id           BIGSERIAL    PRIMARY KEY,
    entry_id     BIGINT       NOT NULL REFERENCES collection_entries(id) ON DELETE CASCADE,
    url          TEXT         NOT NULL,
    local_path   TEXT,
    display_order SMALLINT    NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT max_3_photos CHECK (display_order BETWEEN 0 AND 2)
);

-- ----------------------------------------------------------------
--  REVIEWS — avis (statut Terminé / 100% obligatoire)
-- ----------------------------------------------------------------
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

-- ----------------------------------------------------------------
--  WISHLIST
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wishlist (
    id         BIGSERIAL   PRIMARY KEY,
    user_id    UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    game_id    BIGINT      NOT NULL REFERENCES games(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, game_id)
);

-- ----------------------------------------------------------------
--  LOGS — journal centralisé JSON
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS logs (
    id         BIGSERIAL   PRIMARY KEY,
    level      log_level   NOT NULL DEFAULT 'info',
    message    TEXT        NOT NULL,
    context    JSONB       NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------
--  INDEX DE PERFORMANCE
-- ----------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_games_slug          ON games(slug);
CREATE INDEX IF NOT EXISTS idx_games_igdb_id       ON games(igdb_id);
CREATE INDEX IF NOT EXISTS idx_collection_user     ON collection_entries(user_id);
CREATE INDEX IF NOT EXISTS idx_collection_game     ON collection_entries(game_id);
CREATE INDEX IF NOT EXISTS idx_wishlist_user       ON wishlist(user_id);
CREATE INDEX IF NOT EXISTS idx_reviews_game        ON reviews(game_id);
CREATE INDEX IF NOT EXISTS idx_logs_level          ON logs(level);
CREATE INDEX IF NOT EXISTS idx_logs_created        ON logs(created_at DESC);

-- ----------------------------------------------------------------
--  TRIGGER updated_at automatique
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
