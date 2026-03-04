FROM php:8.3-cli

WORKDIR /var/www/backend

RUN docker-php-ext-install pdo pdo_sqlite

COPY backend/ /var/www/backend/

EXPOSE 8000

CMD ["sh", "-c", "php scripts/init_db.php && php -S 0.0.0.0:8000 -t public"]
