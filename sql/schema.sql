-- LibreStats database schema (MariaDB 10.6+ / MySQL 8+).
-- Privacy by design: no IP addresses and no cookies. A visitor is only a daily-changing hash
-- (see lib/salt.php), so nothing here can follow a person from one day to the next.

SET NAMES utf8mb4;

-- Websites being counted. Hits are only accepted from pages on `domain` (or www.`domain`).
CREATE TABLE IF NOT EXISTS sites (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain      VARCHAR(253) NOT NULL,                  -- example.com (lower case, no www.)
  name        VARCHAR(100) NOT NULL DEFAULT '',
  timezone    VARCHAR(64) NOT NULL DEFAULT 'UTC',     -- days and months are counted in this zone
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sites_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The daily secret for visitor hashes. Only today's (and yesterday's, for visits that run past
-- midnight) ever exist; bin/maintain.php deletes older ones, which makes old hashes unlinkable.
CREATE TABLE IF NOT EXISTS salts (
  day         DATE NOT NULL,
  salt        BINARY(32) NOT NULL,
  PRIMARY KEY (day)
) ENGINE=InnoDB;

-- One row per visit: a visitor's pages until 30 minutes pass without activity.
CREATE TABLE IF NOT EXISTS visits (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id       INT UNSIGNED NOT NULL,
  visitor       BINARY(8) NOT NULL,                    -- daily hash, see lib/salt.php
  day           DATE NOT NULL,                         -- in the site's time zone
  started_at    DATETIME NOT NULL,                     -- UTC
  last_at       DATETIME NOT NULL,                     -- UTC, last page view or activity
  pageviews     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  duration      INT UNSIGNED NOT NULL DEFAULT 0,       -- seconds actively on the site
  entry_path    VARCHAR(512) NOT NULL DEFAULT '',
  exit_path     VARCHAR(512) NOT NULL DEFAULT '',
  source        VARCHAR(20) NOT NULL DEFAULT 'direct', -- direct, search, social, link, campaign, email
  referrer_host VARCHAR(253) NOT NULL DEFAULT '',
  utm_source    VARCHAR(100) NOT NULL DEFAULT '',
  utm_medium    VARCHAR(100) NOT NULL DEFAULT '',
  utm_campaign  VARCHAR(100) NOT NULL DEFAULT '',
  country       CHAR(2) NOT NULL DEFAULT '',           -- ISO code, '' when unknown
  browser       VARCHAR(40) NOT NULL DEFAULT '',
  os            VARCHAR(40) NOT NULL DEFAULT '',
  device        VARCHAR(10) NOT NULL DEFAULT '',       -- desktop, phone, tablet
  screen_width  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_visits_site_day (site_id, day),
  KEY idx_visits_lookup (site_id, visitor, last_at),
  KEY idx_visits_live (site_id, last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every page view. `page_key` is a random id the script makes per page, so the "leaving"
-- message can add the time spent to the right row without identifying anyone.
CREATE TABLE IF NOT EXISTS pageviews (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visit_id    BIGINT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  day         DATE NOT NULL,
  at          DATETIME NOT NULL,                       -- UTC
  path        VARCHAR(512) NOT NULL,
  title       VARCHAR(200) NOT NULL DEFAULT '',
  page_key    CHAR(16) NOT NULL,
  seconds     INT UNSIGNED NOT NULL DEFAULT 0,         -- active time on this page
  PRIMARY KEY (id),
  KEY idx_pageviews_site_day (site_id, day),
  KEY idx_pageviews_visit (visit_id),
  KEY idx_pageviews_key (site_id, page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Things visitors do that you choose to count: "Built an order", outbound links, downloads.
CREATE TABLE IF NOT EXISTS events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visit_id    BIGINT UNSIGNED NOT NULL,
  site_id     INT UNSIGNED NOT NULL,
  day         DATE NOT NULL,
  at          DATETIME NOT NULL,
  name        VARCHAR(100) NOT NULL,
  detail      VARCHAR(512) NOT NULL DEFAULT '',        -- e.g. the link or file, or one short label
  path        VARCHAR(512) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_events_site_day (site_id, day, name),
  KEY idx_events_visit (visit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Goals, in funnel order: a page path (exact, or ending in * for a prefix) or an event name.
CREATE TABLE IF NOT EXISTS goals (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  kind        VARCHAR(10) NOT NULL,                    -- 'path' or 'event'
  target      VARCHAR(512) NOT NULL,
  position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_goals_site (site_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kept for good after raw visits are deleted (bin/maintain.php fills these at each month's end).
CREATE TABLE IF NOT EXISTS monthly_totals (
  site_id     INT UNSIGNED NOT NULL,
  month       DATE NOT NULL,                           -- first day of the month
  visitors    INT UNSIGNED NOT NULL,                   -- sum of each day's unique visitors
  visits      INT UNSIGNED NOT NULL,
  pageviews   INT UNSIGNED NOT NULL,
  bounces     INT UNSIGNED NOT NULL,                   -- visits with one page view
  duration    BIGINT UNSIGNED NOT NULL,                -- seconds, all visits together
  PRIMARY KEY (site_id, month)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS monthly_top (
  site_id     INT UNSIGNED NOT NULL,
  month       DATE NOT NULL,
  dimension   VARCHAR(20) NOT NULL,                    -- page, source, referrer, country, browser, os, device, event
  value       VARCHAR(191) NOT NULL,
  visitors    INT UNSIGNED NOT NULL,
  hits        INT UNSIGNED NOT NULL,                   -- page views / visits / events
  PRIMARY KEY (site_id, month, dimension, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Offline country lookup (DB-IP Lite, CC BY 4.0), loaded by bin/geo-update.php.
-- Addresses are 16-byte IPv6 (IPv4 as ::ffff:a.b.c.d) so one table covers both.
CREATE TABLE IF NOT EXISTS geo_country (
  ip_start    VARBINARY(16) NOT NULL,
  ip_end      VARBINARY(16) NOT NULL,
  country     CHAR(2) NOT NULL,
  PRIMARY KEY (ip_start)
) ENGINE=InnoDB;

-- Dashboard logins (the default dashboard, apps embedding LibreStats can use their own). An 'admin' can
-- change everything. A 'viewer' can only look at the numbers, of every website ('*') or only of the domains
-- listed in `sites` (comma-separated). `team` is the id an app that runs LibreStats gives to the group a
-- viewer belongs to, so that app can manage its own people and nobody else's (see lib/team.php).
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(10) NOT NULL DEFAULT 'admin',
  team          VARCHAR(64) NULL,
  sites         VARCHAR(2000) NOT NULL DEFAULT '*',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed dashboard logins, to slow down password guessing. Keyed by a daily hash like visitors,
-- never by the address itself; rows older than a day are deleted by bin/maintain.php.
CREATE TABLE IF NOT EXISTS login_failures (
  who         BINARY(8) NOT NULL,
  at          DATETIME NOT NULL,
  KEY idx_login_failures (who, at)
) ENGINE=InnoDB;

-- Keys for the data API (public/api.php), for apps that show LibreStats numbers in their own pages
-- (a hosting panel, say). Only a hash of the key is stored. `sites` limits which websites it can
-- read (comma-separated domains), or '*' for all. `team` is 1 when the app may also add and remove
-- viewers for the teams it manages (never for more websites than the key itself can read).
-- `manage_sites` is 1 when the app may also add and remove websites (only for a key that reads all of them).
CREATE TABLE IF NOT EXISTS api_keys (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  key_hash    BINARY(32) NOT NULL,
  label       VARCHAR(100) NOT NULL,
  sites       TEXT NOT NULL,
  team        TINYINT(1) NOT NULL DEFAULT 0,
  manage_sites TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_keys_hash (key_hash)
) ENGINE=InnoDB;
