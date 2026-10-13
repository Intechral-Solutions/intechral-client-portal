-- Release 0.9 migration rehearsal (R0.9-1A): two disposable databases, nothing else.
--
--   intechral_client_portal_rehearsal          where the baseline is built, migrated and snapshotted
--   intechral_client_portal_rehearsal_restore  where a backup of it is restored to prove the round trip
--
-- Runs automatically on a fresh Docker volume (MariaDB entrypoint-initdb.d), and by hand against an existing
-- volume with `./dev rehearse:provision` (the only place the rehearsal tooling uses the database root login).
-- Idempotent. The `portal` account gets ALL on these two databases ONLY: no global privilege and no access to
-- any other schema is added. The underscores are escaped because an unescaped `_` in a GRANT database name is
-- a single-character wildcard. The rehearsal harness (App\Support\Rehearsal) refuses to run as an account
-- that holds any global privilege.

CREATE DATABASE IF NOT EXISTS `intechral_client_portal_rehearsal`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS `intechral_client_portal_rehearsal_restore`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `intechral\_client\_portal\_rehearsal`.* TO `portal`@`%`;
GRANT ALL PRIVILEGES ON `intechral\_client\_portal\_rehearsal\_restore`.* TO `portal`@`%`;

FLUSH PRIVILEGES;
