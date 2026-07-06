FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY assets ./assets
COPY tailwind.config.js ./
COPY index.html ./
RUN npm run build:css

FROM php:8.3-apache AS app

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates unzip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/certs/sectigo-public-server-auth-ca-dv-r36.crt /usr/local/share/ca-certificates/
RUN update-ca-certificates

RUN docker-php-ext-install pdo_mysql
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && a2enmod rewrite headers

WORKDIR /var/www/html
COPY . .
COPY --from=frontend /app/public/assets/css/tailwind.css public/assets/css/tailwind.css

RUN if [ -f composer.json ]; then composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader; fi \
    && mkdir -p private/cache private/rate_limits public/uploads/locations \
    && chown -R www-data:www-data private public/uploads

EXPOSE 80
