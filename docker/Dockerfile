FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates unzip \
    && rm -rf /var/lib/apt/lists/*

# etkinlik.gaziantep.edu.tr (ve birkaç eski GAÜN alt alanı) TLS el sıkışmasında
# ara sertifikayı (Sectigo Public Server Authentication CA DV R36) göndermiyor;
# sertifikanın kendisi geçerli ama zincir eksik olduğu için curl doğrulayamıyor.
# SHA256: 8C:54:C3:34:B6:6B:A4:E4:26:77:2A:F4:A3:F9:13:6C:19:A1:AE:C7:29:FD:B2:8C:53:5C:07:A5:A4:EF:22:E0
# (sunucunun kendi AIA uzantısından indirildi: crt.sectigo.com/...DVR36.crt)
COPY certs/sectigo-public-server-auth-ca-dv-r36.crt /usr/local/share/ca-certificates/
RUN update-ca-certificates

# MySQL/MariaDB için PDO sürücüsü
RUN docker-php-ext-install pdo_mysql

# PHP test takımı (PHPUnit) için: bkz. tests/php/, composer.json "test" script'i.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Apache'i public/ web köküne yönlendir; .htaccess kuralları geçerli olsun.
RUN sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && a2enmod rewrite headers
