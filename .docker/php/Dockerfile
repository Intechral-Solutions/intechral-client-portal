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
