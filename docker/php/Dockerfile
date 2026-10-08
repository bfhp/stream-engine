FROM node:20 AS node

FROM php:8.3-fpm

ENV LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

ARG TARGETARCH
ARG SUPERCRONIC_VERSION=v0.2.48

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
    libmagickwand-dev \
    librsvg2-bin \
    libmemcached-dev \
    zlib1g-dev \
    libcurl4-openssl-dev \
    libicu-dev \
    pkg-config \
    build-essential \
    autoconf \
    && rm -rf /var/lib/apt/lists/*

# ---- container scheduler ----
RUN case "${TARGETARCH}" in \
        amd64) checksum='016b7c9aebfc8d9fd9526e8ba33b191fc524485f' ;; \
        arm64) checksum='2ab9b3bdcf290f60b59700aad876b6e68f3a6b06' ;; \
        arm) checksum='9beb848f1bba657bad3e470251f6e6a47566b8cf' ;; \
        386) checksum='dea739b7124d1bab8a9b0458568f933d91a4d22c' ;; \
        *) echo "Unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac \
    && curl -fsSL \
        "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${TARGETARCH}" \
        -o /usr/local/bin/supercronic \
    && echo "${checksum}  /usr/local/bin/supercronic" | sha1sum -c - \
    && chmod 0755 /usr/local/bin/supercronic

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

# ---- optional SVG conversion used by administrator-configurable site icons ----
RUN pecl install imagick \
    && docker-php-ext-enable imagick \
    && php -r '$image = new Imagick(); $image->readImageBlob("<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1\" height=\"1\"><rect width=\"1\" height=\"1\"/></svg>"); $image->setImageFormat("png"); exit($image->getImageBlob() === "" ? 1 : 0);'

# ---- xdebug ----
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && mv /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
          /usr/local/etc/php/conf.d/99-xdebug.ini

# ---- xdebug config ----
COPY xdebug.ini /usr/local/etc/php/conf.d/99-xdebug-settings.ini

# ---- uploads ----
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

COPY crontab /etc/stream-engine/crontab

# ---- composer ----
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ---- node ----
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /var/www
