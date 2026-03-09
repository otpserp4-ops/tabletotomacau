FROM dunglas/frankenphp:latest

RUN install-php-extensions pdo pdo_pgsql

COPY . /app
COPY Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app
