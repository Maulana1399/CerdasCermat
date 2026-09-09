# syntax=docker/dockerfile:1

# ------------------------------------------------------------------
# Tahap build: composer install (termasuk dev deps — dipakai untuk
# base tools/tests; prod akan dipangkas --no-dev setelahnya).
# ------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# Instal tanpa scripts/autoload dulu; pengoptimalan autoload dilakukan
# setelah kode disalin agar perubahan file app ikut terjaring.
RUN composer install \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist

COPY . .

RUN composer install \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --prefer-dist

# ------------------------------------------------------------------
# Base runtime: PHP 8.4 FPM — digunakan untuk tools/test dan menjadi
# induk image produksi.
# ------------------------------------------------------------------
FROM php:8.4-fpm AS base

# Catatan ABI runtime:
# - Extension PHP yang dikompilasi (zip → libzip, mbstring → libonig)
#   membutuhkan *runtime library* (mis. libzip.so.5 / libonig.so.*) yang
#   disediakan paket non-`-dev` (libzip5, libonig5, dst).
# - `apt-get purge --auto-remove <pkg>-dev` ikut membuang runtime library
#   tersebut (karena menjadi orphan), sehingga zip/mbstring gagal dimuat.
#   Solusi: tangkap nama paket runtime non-`-dev` via dpkg-query sebelum
#   purge, lalu pasang ulang setelahnya — tetap hanya paket minimal.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libfcgi-bin \
        libsqlite3-dev \
        libonig-dev \
        libzip-dev \
    ; \
    docker-php-ext-configure zip; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        mbstring \
        zip \
        opcache \
    ; \
    RUNTIME_LIBS="$(dpkg-query -W -f='${Package}\n' 'libzip*' 'libonig*' | grep -v -- '-dev$' | tr '\n' ' ')"; \
    apt-get purge -y --auto-remove libsqlite3-dev libonig-dev libzip-dev; \
    if [ -n "$RUNTIME_LIBS" ]; then apt-get install -y --no-install-recommends $RUNTIME_LIBS; fi; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

WORKDIR /var/www/lomba

COPY --from=vendor --chown=www-data:www-data /app /var/www/lomba

# Rootfs image menyediakan direktori data; saat volume di-mount,
# kepemilikan disesuaikan lagi oleh entrypoint.
ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]

# ------------------------------------------------------------------
# Produksi: hapus dev dependencies agar image ringan, lalu regenerasi
# package discovery *tanpa* paket dev (laravel/boost tidak di-ship).
# Composer CLI tidak diperlukan di runtime produksi.
# ------------------------------------------------------------------
FROM base AS prod

# bootstrap/cache lokal (packages.php/services.php dari vendor dev) tidak
# boleh ikut; dibersihkan lalu dibangun ulang dari vendor produksi.
RUN rm -rf bootstrap/cache/* \
    && composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
    && php artisan package:discover --ansi \
    && rm -f /usr/local/bin/composer

EXPOSE 9000