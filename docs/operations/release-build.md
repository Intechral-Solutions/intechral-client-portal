# Release Build: an Off-Host, Checksummed Archive (Release 0.9, WP3-B2)

**Authority:** the Release 0.9 shared-hosting runtime gate. **Companions:** [Staging First Install](./staging-first-install.md) (what to do with the archive),
[Staging Acceptance](./staging-acceptance.md), [Runtime Preflight](../architecture/runtime-preflight.md) (why a release directory must never change after it is built).

The Namecheap account has **no Composer and no Node**, and Release 0.9 installs nothing on it. A release is a finished archive built on a development machine from one Git commit,
uploaded, checked against its checksum, unpacked into a new immutable directory and selected with a symlink. This document is the build half. It uses the project's existing
toolchain (the development image and the `composer` and `npm` scripts already in the repository) and adds no deployment system.

> **Status.** Documentation only. The build was rehearsed end to end on 2026-10-22 against `c0624ac` in a disposable directory on a development machine (numbers at the end). **Nothing has been
> uploaded to Namecheap**, and no host step in this file is authorised until the owner opens the gate named in the first-install runbook.

## The platform contract

| Item | Value | Where it comes from |
|---|---|---|
| Host PHP CLI | `/opt/alt/php85/usr/bin/php`, PHP 8.5.11 | Host inventory (owner, 2026-10-22) |
| Build PHP | 8.5.11 in the project image (`intechral-portal-app:latest`, from `.docker/php/Dockerfile`, `php:8.5-fpm`) | `docker run … php -v` |
| Composer | 2.10.3 (pinned in the Dockerfile) | Dockerfile |
| Node / npm | 22 LTS / 10 (NodeSource 22.x in the image) | Dockerfile |
| Application platform | `php: ^8.3` in `composer.json`; Laravel 13.36 | `composer.lock` |

Build with the **image**, not with whatever PHP the development machine happens to have: it is the same PHP minor as the host, and its Composer and Node are the pinned ones. The image id and the tool versions
actually used are written into the release's `RELEASE` file ([Release identity](#release-identity)), so a build can be reproduced.

**PHP extensions the host must provide.** The authority is the build itself, not a list in this document: `composer check-platform-reqs --no-dev` lists every `ext-*` the locked production packages require, and
step 2 writes that list into `RELEASE` as `required_extensions=`. For the commit rehearsed here it is `ctype`, `curl`, `date`, `dom`, `exif`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`,
`pcre`, `session`, `tokenizer`, `zlib`. The application adds `pdo_mysql` (the database), and the CLI cron wrapper needs `pcntl` and `posix` ([Cron Execution](../architecture/cron-execution.md)). `exif` is the one that is easy to
miss: a locked package requires it, and nothing else implies it. Not required by any locked package: `gd`, `intl`, `zip`, `bcmath`, `redis`, `sqlite` (the development image has them; the host need not).

**The Composer-generated `vendor/composer/platform_check.php` does not verify extensions.** For this project it checks only the PHP version (`>= 8.3`) and a 64-bit build; it contains no `extension_loaded()` test, so a release
that loads without error can still be missing `exif`. Extensions are therefore verified **directly**, in both runtimes the application uses, and a missing one stops the install (fail closed):

- the **CLI** (cron and administration): from an SSH session, before anything is created ([Staging First Install, phase 0](./staging-first-install.md#phase-0-build-rulings-and-the-read-only-host-check-no-host-change-g0));
- the **web** handler for the staging hostname: a one-off, owner-approved probe before the release is uploaded ([Staging First Install, G1d](./staging-first-install.md#phase-1-staging-resources-g1-each-sub-gate-separate)).

Do not change Composer dependencies to satisfy a missing extension.

## What a release contains, and what it must never contain

The archive is built from an **allowlist** of top-level paths, so nothing outside it can enter by accident:

| In the archive | Why |
|---|---|
| `app`, `bootstrap` (including the generated `bootstrap/cache/packages.php` and `services.php`), `config`, `database`, `resources`, `routes`, `scripts`, `public` | the application. `scripts/cron/portal-cron.sh` is mode 0755; `public/` includes `.htaccess` and `build/` (the Vite output) |
| `vendor` | the **production** Composer tree (`--no-dev`, optimised autoloader), built off-host |
| `artisan`, `composer.json`, `composer.lock` | the entry point, and the record of what was installed |
| `RELEASE` | the release identity and build provenance (below) |

| Never in the archive | Why |
|---|---|
| `.env`, `.env.example`, `.env.testing`, `.env.staging.example`, any `.env*` | configuration is the host's private `shared/.env`; templates are not needed there |
| `storage/` | it is the shared directory, linked into each release; a shipped `storage/` would shadow it |
| `tests/`, `phpunit.xml`, `node_modules/`, `package.json`, `package-lock.json`, `eslint*`, `playwright*`, `vite.config.ts`, `tsconfig.json`, `.prettier*`, `components.json` | development and build tooling |
| `.git`, `.github`, `.claude`, `.vscode`, `auth.json`, `*.sqlite`, logs, keys | version-control and developer files; none belongs on a server |

`resources/js` (including the generated Wayfinder TypeScript) is inside `resources/` and travels with it; it is inert at runtime. `vendor/` legitimately contains third-party `phpunit.xml`,
`.github/` and similar files, which is why the content checks below are scoped to **outside `vendor/`**.

## Release identity

- **Release id:** `<UTC yyyymmddHHMMSS>-<7-character commit>`, for example `20261022094614-c0624ac`. It becomes the directory name under `releases/`, and the build directory name, so a rebuild of the same commit is a *new* id and a
  new directory. It must contain **no whitespace** (the cron wrapper writes it into space-separated log fields).
- **`RELEASE`** (a small text file at the archive root) records: the id; the full 40-character commit; the build time; the build image (name and id) and the PHP, Composer, Node and npm versions used; the SHA-256 of `composer.lock`,
  `package-lock.json` and **`public/.htaccess`** (the baseline against which a control-panel edit is detected); and `required_extensions`.
- **The archive's SHA-256** is recorded next to it in `<archive>.sha256`. A file cannot contain its own hash, which is why the checksum is a separate file.
- A release is **built from a commit, never from a working tree** (`git archive`), so uncommitted or untracked files, a local `.env`, a dev `vendor/` or a stale `public/build` cannot enter it.
- Never rebuild and re-upload an id that already exists on the host. A fix is a new commit and a new id. The runtime checks (A2) depend on a release directory never being modified after it is built; the one deliberate,
  recorded exception is the reviewed PHP handler block appended to the `public/.htaccess` of a release that is **not yet selected** ([Staging First Install, phase 8](./staging-first-install.md#phase-8-select-the-release-and-serve-it-g5)); it is logged on the host, and the archive and its checksum still describe the artifact as built.

## Build

All commands run from the repository root on the development machine. They need Docker and network access (Packagist, npm).

### 0. Choose the commit

```bash
git fetch origin
REPO=$PWD
COMMIT=$(git rev-parse origin/main)          # the reviewed, merged commit to release (a full SHA)
SHORT=$(git rev-parse --short=7 "$COMMIT")
# CI must have passed for THIS exact commit: prints true only if at least one run exists for it and every run succeeded
gh run list --commit "$COMMIT" --json conclusion --jq 'length > 0 and all(.[]; .conclusion == "success")'
docker image inspect intechral-portal-app:latest >/dev/null     # else: docker compose build app
REL_ID="$(date -u +%Y%m%d%H%M%S)-$SHORT"                       # unique per build: running these lines again gives a new id and a new directory
BUILD=$REPO/backups/dev/release-build/$REL_ID                   # gitignored; on disk (not the RAM-backed /tmp)
mkdir -p "$(dirname "$BUILD")" && mkdir "$BUILD" "$BUILD/work"   # fails if the directory exists: it never reuses another build's files
```

Stop unless the `gh` line printed `true`. CI is the test gate: this build does not run the test suite, and a documentation or packaging change does not need one.

### 1. Export the commit

```bash
git archive "$COMMIT" src | tar -x -C "$BUILD/work"       # produces $BUILD/work/src, tracked files only
```

### 2. Production dependencies, and the facts about the build

```bash
build_sh() {   # runs a command in the project image, in the exported tree, as you (so files are yours)
  docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
    -e npm_config_cache=/tmp/npm-cache -e CI=1 -v "$BUILD/work:/work" -w /work/src \
    intechral-portal-app:latest sh -c "$1"
}
build_sh 'composer validate --no-check-publish --strict --no-ansi \
  && composer install --no-dev --optimize-autoloader --no-interaction --no-ansi --prefer-dist \
  && composer check-platform-reqs --no-dev --no-ansi'

# what the locked production packages require, and what built them (both go into RELEASE)
EXTS=$(build_sh 'composer check-platform-reqs --no-dev --no-ansi --format=json 2>/dev/null' \
  | grep -o '"name": *"ext-[a-z0-9_]*"' | sed -E 's/.*"ext-([a-z0-9_]+)"/\1/' | sort -u | paste -sd, -)
[[ "$EXTS" == *exif* ]] && echo "required extensions: $EXTS" || echo "extension derivation failed: STOP"   # an empty or exif-less list means the derivation broke
IMAGE_ID=$(docker image inspect --format '{{.Id}}' intechral-portal-app:latest)
BUILD_PHP=$(build_sh 'php -r "echo PHP_VERSION;"'); BUILD_COMPOSER=$(build_sh 'composer --version --no-ansi 2>/dev/null | head -n 1')
BUILD_NODE=$(build_sh 'node -v');                    BUILD_NPM=$(build_sh 'npm -v')
```

Expect `./composer.json is valid`, `Generating optimized autoload files`, the package discovery list, and a platform table that ends `success` on every row including `php 8.5.11`. Composer's
`post-autoload-dump` script runs `php artisan package:discover`, which writes `bootstrap/cache/packages.php` and `services.php` for the **production** package set (no dev provider can be listed).
Any `failed` row stops the build: it means the image and the lock disagree. (This table is a check of the *image*; it is not a check of the host.)

### 3. Frontend assets

```bash
build_sh 'npm ci --no-audit --no-fund && npm run build'
```

`npm ci` installs exactly `package-lock.json`. `npm run build` is `vite build`; the Wayfinder plugin runs `php artisan wayfinder:generate` itself, which is why Composer ran first in the same image. The output
is `public/build/` (including `manifest.json`). The lint, typecheck and unit tests are `npm run check`, which CI runs: they are not repeated here.

### 4. Check the built tree

```bash
( set -u
  cd -- "${BUILD:?}/work/src" || exit 1                       # a failed cd stops the sequence: nothing below can run in the wrong directory
  test -f public/build/manifest.json                          && echo "assets ok"            || exit 1
  test ! -e .env                                              && echo "no .env"              || exit 1
  { test ! -d vendor/pestphp && test ! -d vendor/laravel/pail; } && echo "no dev packages"   || exit 1
  test -f bootstrap/cache/packages.php                        && echo "packages manifest ok" || exit 1 )
```

Every line must print; the first failing check stops the sequence. (`vendor/pestphp` and `vendor/laravel/pail` are development-only packages: their absence proves `--no-dev` took effect.)

### 5. Stamp and package

```bash
( set -u
  cd -- "${BUILD:?}/work/src" || exit 1
  : "${REL_ID:?}" "${COMMIT:?}" "${EXTS:?}" "${IMAGE_ID:?}" "${BUILD_PHP:?}" "${BUILD_COMPOSER:?}" "${BUILD_NODE:?}" "${BUILD_NPM:?}"     # nothing is stamped from an unset value
  cat > RELEASE <<REL
release=$REL_ID
commit=$COMMIT
built_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)
build_image=intechral-portal-app:latest $IMAGE_ID
build_php=$BUILD_PHP
build_composer=$BUILD_COMPOSER
build_node=$BUILD_NODE
build_npm=$BUILD_NPM
composer_lock_sha256=$(sha256sum composer.lock | cut -d' ' -f1)
package_lock_sha256=$(sha256sum package-lock.json | cut -d' ' -f1)
htaccess_sha256=$(sha256sum public/.htaccess | cut -d' ' -f1)
required_extensions=$EXTS
REL
  MTIME=$(git -C "${REPO:?}" show -s --format=%ct "$COMMIT") || exit 1
  mkdir "$BUILD/out" || exit 1
  tar --sort=name --owner=0 --group=0 --numeric-owner --mtime="@$MTIME" -cf - \
      app artisan bootstrap config database public resources routes scripts vendor composer.json composer.lock RELEASE \
    | gzip -n -9 > "$BUILD/out/portal-$REL_ID.tar.gz" || exit 1
  ( cd -- "$BUILD/out" && sha256sum "portal-$REL_ID.tar.gz" > "portal-$REL_ID.tar.gz.sha256" && cat "portal-$REL_ID.tar.gz.sha256" ) )
```

Sorted names, fixed owner and modification time, and `gzip -n` make the archive a pure function of its inputs: tarring the same tree and `RELEASE` again gives the same SHA-256 (checked in the rehearsal).
File modes are Git's for the application (files 0644; the wrapper `scripts/cron/portal-cron.sh` 0755 is the only executable the repository tracks, and `artisan` is run as `php artisan`) and Composer's for `vendor/` (which
contains executable files, mostly 0755). Nothing in the archive is group- or world-writable.

### 6. Verify before upload

```bash
( cd -- "${BUILD:?}/out" || exit 1; A=portal-${REL_ID:?}.tar.gz
sha256sum -c "$A.sha256"                                              # OK
tar -tzf "$A" | cut -d/ -f1 | sort -u | tr '\n' ' '; echo             # exactly: RELEASE app artisan bootstrap composer.json composer.lock config database public resources routes scripts vendor
for p in artisan vendor/autoload.php public/index.php public/.htaccess public/build/manifest.json bootstrap/app.php \
         bootstrap/cache/packages.php scripts/cron/portal-cron.sh database/profile/foreign-keys.json config/portal.php RELEASE; do
  tar -tzf "$A" | grep -qx "$p" && echo "ok  $p" || echo "MISSING $p"; done                       # no MISSING
tar -tzf "$A" | grep -vE '^vendor/' | grep -cE '(^|/)\.env($|\.)'                               # 0   (no environment file, outside vendor)
tar -tzf "$A" | grep -vE '^vendor/' | grep -cE '(^|/)\.git/'                                    # 0   (no repository)
tar -tzf "$A" | grep -vE '^vendor/' | grep -cE '\.sqlite3?$|(^|/)auth\.json$'                   # 0
tar -xzOf "$A" --exclude='vendor' --exclude='public/build' | grep -acE 'BEGIN [A-Z ]*PRIVATE KEY|APP_KEY=base64:'   # 0   (no key material outside vendor)
tar -tvzf "$A" | grep -E ' scripts/cron/portal-cron.sh$'                                         # -rwxr-xr-x
tar -xzOf "$A" scripts/cron/portal-cron.sh | sh -n && tar -xzOf "$A" scripts/cron/portal-cron.sh | bash --posix -n && echo "wrapper syntax ok"
tar -xzOf "$A" RELEASE                                                                           # commit equals $COMMIT; build_php 8.5.x; required_extensions contains exif
tar -xzOf "$A" public/.htaccess | sha256sum                                                      # equals htaccess_sha256 in RELEASE: RECORD it (the baseline for the control-panel check)
tar -tzf "$A" | grep -vc '/$'                                                                    # RECORD this number: files and links in the archive
# the two lines to paste into the host session, derived from RELEASE (never typed by hand)
{ echo "EXTS_WEB=$(tar -xzOf "$A" RELEASE | sed -n 's/^required_extensions=//p'),pdo_mysql"
  echo "EXTS_CLI=$(tar -xzOf "$A" RELEASE | sed -n 's/^required_extensions=//p'),pdo_mysql,pcntl,posix"; } )
```

Any unexpected result stops the release. Record the SHA-256, the entry count, the `.htaccess` hash and the `RELEASE` contents in the evidence record ([Staging Acceptance](./staging-acceptance.md#evidence-record)).

## Upload and verify on the host (a host step: gated)

Not authorised by this document. The first-install runbook opens it as gate **G3** ([Staging First Install](./staging-first-install.md#gates-and-approvals)). The commands are here so the build and its verification stay together.
They run in the **session set up by the runbook** (its guards `root_ok`, `rel_path_ok` and `rel_ok`, with the release selected by `setrel <REL_ID>`); every multi-step sequence is a subshell that stops at its first failing step with an explicit `|| exit 1` (it does not rely on `set -e`, which bash ignores inside `&&`/`||` lists).

```bash
# from the development machine; the key and passphrase stay on your machine. SSH_TARGET is your ssh-config alias for the account.
SSH_TARGET=REPLACE_WITH_YOUR_SSH_CONFIG_ALIAS
scp "$BUILD/out/portal-$REL_ID.tar.gz" "$BUILD/out/portal-$REL_ID.tar.gz.sha256" "${SSH_TARGET:?}:portal-staging/incoming/"

# on the host
(
  set -u
  root_ok && rel_path_ok || exit 1                                       # REL is exactly <staging root>/releases/<id> in a real releases/ directory (the release itself does not exist yet, so rel_ok cannot be used)
  cd -- "$PORTAL_ROOT/incoming" || exit 1
  sha256sum -c "portal-$REL_ID.tar.gz.sha256" || exit 1                  # OK (a mismatch means do not unpack: upload again)
  { test ! -e "$REL" && test ! -e "$REL.partial"; } || { echo "release id already used: STOP" >&2; exit 1; }     # an id is never reused
  umask 022
  mkdir -- "$REL.partial" || exit 1
  tar -xzf "portal-$REL_ID.tar.gz" --no-same-owner -C "$REL.partial" || { echo "unpack failed: the .partial directory is left for inspection: STOP" >&2; exit 1; }
  mv -T -- "$REL.partial" "$REL" || exit 1                               # a directory named $REL_ID exists only once it is complete
)
rel_ok && find "$REL" ! -type d | wc -l                                   # equals the number recorded before upload
sed -n 1,2p "$REL/RELEASE"                                                # release= and commit= match the build
stat -c '%a %n' "$REL/scripts/cron/portal-cron.sh" "$REL/public/.htaccess" "$REL"            # 755, 644, 755
find "$REL" -perm /022 | wc -l                                            # 0: nothing group- or world-writable
sha256sum "$REL/public/.htaccess" | cut -d' ' -f1; sed -n 's/^htaccess_sha256=//p' "$REL/RELEASE"   # equal: the baseline, before any control-panel edit
```

Do not `chmod -R a-w` the release. The release's `public/` is the web document root, and cPanel AutoSSL (and possibly a PHP-version selection) writes there; AutoSSL's validation files are inert and disappear with
the next release. Directories need to stay readable and traversable (0755) so the web server can serve `public/build`.

On the host nothing is installed or compiled: the next steps are the extension checks, `config:cache`, the migration and the rest of the runbook.

## Rehearsal record (2026-10-22, development machine, no host involved)

Commit `c0624ac704fe4e577562986bb2ffb5fb259c0a6d`, image `intechral-portal-app:latest` (PHP 8.5.11, Composer 2.10.3, Node 22.23.3, npm 10.9.9):

- `composer install --no-dev`: 121 packages, about 13 s; platform check `success` on every row.
- `npm ci` + `npm run build`: 377 packages, about 25 s; `public/build` 1.7 MB, `manifest.json` present.
- Archive: 13 MB, 12,237 entries (10,596 files and links), top level exactly as listed above; the content checks all returned 0 (the scan was shown not to be vacuous: 4.8 MB scanned outside `vendor/`, and a planted key
  pattern was caught); the wrapper is 0755 and passes `sh -n` and `bash --posix -n`.
- Re-tarring the same inputs reproduced the SHA-256 byte for byte.
- Unpacking with `umask 022` gave 0755 directories, 0644 files and a 0755 wrapper, with nothing group- or world-writable, and the extracted file count equalled the archive's.
- The unpacked release booted offline (no network): `php artisan --version` printed `Laravel Framework 13.36.0`, `config:cache` succeeded with the staging template filled with fake values, and
  `RuntimeProfile::configuration()` returned no violation ([the template](../../src/.env.staging.example)).
- Remediation re-validation: the revised step 2 and 5 commands were run (the extension list derived from Composer, the provenance fields and the `.htaccess` hash written into a `RELEASE`; the exact-SHA CI query returns `true` for
  this commit and `false` for an unknown one). `vendor/composer/platform_check.php` was read: it tests the PHP version and 64-bit only.

None of this proves the host: the host PHP, extensions, web handler and cron environment are checked in the acceptance checklist.
