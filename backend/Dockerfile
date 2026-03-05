FROM php:8.3-cli

WORKDIR /var/www/backend

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY backend/ /var/www/backend/

EXPOSE 8000

CMD ["sh", "-c", "php scripts/init_db.php && php -S 0.0.0.0:8000 -t public"]
