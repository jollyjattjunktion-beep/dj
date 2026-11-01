FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_sqlite \
 && a2enmod rewrite headers \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . /var/www/html/
RUN mkdir -p /var/data && chown -R www-data:www-data /var/data
EXPOSE 10000
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
 && sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf \
 && php -r 'new PDO("sqlite::memory:"); echo "PDO SQLite OK\\n";'
CMD ["sh","-c","chown -R www-data:www-data /var/data && exec apache2-foreground"]
