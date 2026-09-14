FROM node:20 AS node

FROM php:8.3-fpm

ENV LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

# ---- system deps ----
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libmemcached-dev \
    zlib1g-dev \
    libcurl4-openssl-dev \
    libicu-dev \
    pkg-config \
    build-essential \
    autoconf \
    && rm -rf /var/lib/apt/lists/*

# ---- php extensions ----
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        zip \
        gd \
        curl \
        fileinfo \
        intl

# ---- memcached ----
RUN pecl install memcached \
    && docker-php-ext-enable memcached

# ---- xdebug ----
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && mv /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
          /usr/local/etc/php/conf.d/99-xdebug.ini

# ---- xdebug config ----
COPY xdebug.ini /usr/local/etc/php/conf.d/99-xdebug-settings.ini

# ---- uploads ----
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# ---- composer ----
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ---- node ----
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /var/www
