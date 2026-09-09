#!/bin/sh
set -e

# Hapus manifest provider lama (bisa terbawa dari volume/image versi
# sebelumnya yang masih memuat paket dev seperti laravel/boost).
# PackageManifest akan membangun ulang dari vendor produksi saat boot.
rm -f \
    /var/www/lomba/bootstrap/cache/packages.php \
    /var/www/lomba/bootstrap/cache/services.php

# Direktori yang ditulis Laravel: sesuaikan kepemilikan terhadap volume
# (named volume). Tidak memakai chmod 777.
# Catatan: database/migrations TIDAK di-chown karena di dev mode
# bind-mounted read-only (:ro); chown -R akan gagal "Read-only file
# system" dan menghentikan entrypoint (set -e).
chown -R www-data:www-data \
    /var/www/lomba/storage \
    /var/www/lomba/bootstrap/cache

chown -R www-data:www-data \
    /var/www/lomba/database/database.sqlite \
    /var/www/lomba/database/.gitignore
find /var/www/lomba/database -mindepth 1 -maxdepth 1 \
    ! -name migrations \
    -exec chown -R www-data:www-data {} +

# Migrasi + seed data awal (idempotent; seed regu default hanya bila kosong).
php artisan lomba:bootstrap --force

# Lanjutkan ke perintah yang diminta (default: php-fpm).
exec "$@"