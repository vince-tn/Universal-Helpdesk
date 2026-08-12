FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        unzip \
        git \
        default-mysql-client \
    && docker-php-ext-install intl mbstring zip mysqli pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN echo "variables_order=EGPCS" > /usr/local/etc/php/conf.d/zz-env.ini

ENV PHP_CLI_SERVER_WORKERS=8

COPY entrypoint.sh /usr/local/bin/helpdesk-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/helpdesk-entrypoint \
    && chmod +x /usr/local/bin/helpdesk-entrypoint

WORKDIR /app

EXPOSE 8080

CMD ["helpdesk-entrypoint"]
