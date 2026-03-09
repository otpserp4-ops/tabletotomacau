FROM dunglas/frankenphp:latest

RUN install-php-extensions pdo pdo_pgsql

COPY . /app

WORKDIR /app
