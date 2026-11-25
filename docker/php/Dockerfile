FROM dunglas/frankenphp:1.14.0-php8.5-bookworm

# install-php-extensions (bundled with FrankenPHP) builds core and PECL extensions.
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && install-php-extensions pdo_pgsql intl zip bcmath pcntl redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY docker/php/Caddyfile /etc/frankenphp/Caddyfile
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl --fail --silent http://127.0.0.1:8000/up > /dev/null || exit 1
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
