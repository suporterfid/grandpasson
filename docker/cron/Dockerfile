FROM php:8.2-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends cron \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY crontab /etc/cron.d/grandpasson
RUN chmod 0644 /etc/cron.d/grandpasson \
    && touch /var/log/cron.log

WORKDIR /var/www/html

CMD ["cron", "-f", "-L", "15"]
