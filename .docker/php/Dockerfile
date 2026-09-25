# ── Playwright version: single source of truth is src/package-lock.json ─────
# A throwaway stage reads the locked playwright-core version so the browser build below
# always matches the test runner, and fails the build if the lockfile is inconsistent.
# Only the extracted version reaches the main stage, so unrelated lockfile changes do not
# invalidate the (large) browser layer.
FROM php:8.3-fpm AS playwright-version
COPY src/package-lock.json /tmp/package-lock.json
RUN php -r '$p = json_decode(file_get_contents("/tmp/package-lock.json"), true)["packages"] ?? []; \
    $core = $p["node_modules/playwright-core"]["version"] ?? ""; \
    $test = $p["node_modules/@playwright/test"]["version"] ?? ""; \
    if (!preg_match("/^\d+\.\d+\.\d+$/", $core) || $core !== $test) { \
        fwrite(STDERR, "package-lock.json: playwright-core (\"$core\") and @playwright/test (\"$test\") must be present and identical\n"); \
        exit(1); \
    } \
    echo $core;' > /playwright-version

FROM php:8.3-fpm

# System dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    libgd-dev \
    zip \
    unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        xml \
        intl \
        opcache

# Redis extension (via PECL)
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Node.js 22 LTS
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Playwright Chromium + OS libraries for `./dev test:e2e` (development image only: production
# targets cPanel/PHP-FPM, not this image; the queue service shares these layers).
# Built here, not at container start, so recreating the container never loses it. Browsers live
# in a shared world-readable path (not /root/.cache) so any UID, including the host user that
# ./dev runs as, can launch them. The version comes from the lockfile stage above, and the
# build fails if the installed CLI or browser directories do not match it.
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright
COPY --from=playwright-version /playwright-version /tmp/playwright-version
RUN set -eux; \
    PW_VERSION="$(cat /tmp/playwright-version)"; \
    export npm_config_cache=/tmp/npm-cache; \
    npx --yes "playwright-core@${PW_VERSION}" install --with-deps chromium; \
    test "$(npx --yes "playwright-core@${PW_VERSION}" --version)" = "Version ${PW_VERSION}"; \
    for browser in chromium chromium_headless_shell; do \
        set -- "${PLAYWRIGHT_BROWSERS_PATH}/${browser}-"*; \
        test -e "$1/INSTALLATION_COMPLETE"; \
    done; \
    chmod -R a+rX "${PLAYWRIGHT_BROWSERS_PATH}"; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/* /tmp/npm-cache /tmp/playwright-version /root/.npm

# PHP configuration
COPY .docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY .docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Working directory
WORKDIR /var/www/app

# Non-root user matching common host UID
RUN groupadd -g 1000 portal && useradd -u 1000 -g portal -m portal
RUN chown -R portal:portal /var/www
USER portal

EXPOSE 9000
CMD ["php-fpm"]
