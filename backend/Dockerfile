FROM php:7.4-cli-bullseye

# Исправление ошибки 404: перенаправляем apt на архивные зеркала Debian Bullseye
RUN echo "deb http://archive.debian.org/debian/ bullseye main" > /etc/apt/sources.list && \
    echo "deb http://archive.debian.org/debian-security bullseye-security main" >> /etc/apt/sources.list && \
    echo "Acquire::Check-Valid-Until false;" > /etc/apt/apt.conf.d/99no-check-valid-until

RUN apt-get update && \
    apt-get install -y --no-install-recommends libpq-dev && \
    docker-php-ext-install pgsql && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

ENV PHP_CLI_SERVER_WORKERS=4

# Копируем файлы проекта внутрь контейнера (обязательно!)
COPY . .

CMD ["php", "-d", "display_errors=0", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]