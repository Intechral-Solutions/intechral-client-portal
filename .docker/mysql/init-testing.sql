-- Runs automatically on a fresh Docker volume (MariaDB entrypoint-initdb.d).
-- Creates the dedicated test database and grants the portal user full access.
-- This file is idempotent — safe to run multiple times.

CREATE DATABASE IF NOT EXISTS `intechral_client_portal_testing`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `intechral_client_portal_testing`.* TO `portal`@`%`;

FLUSH PRIVILEGES;
