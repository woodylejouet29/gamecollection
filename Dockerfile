FROM php:8.3-apache

# 1. Installation des dépendances système et des outils de build pour zstd et postgres
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    libzstd-dev \
    && rm -rf /var/lib/apt/lists/*

# 2. Installation des extensions PHP natives
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql zip gd

# 3. Installation de l'extension php-zstd (via PECL)
RUN pecl install zstd \
    && docker-php-ext-enable zstd

# 4. Activation de mod_rewrite pour Apache (requis pour FastRoute)
RUN a2enmod rewrite

# 5. Configuration d'Apache pour utiliser la racine du projet comme DocumentRoot
# Note: ton index.php est à la racine, donc on garde /var/www/html
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 6. Installation de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 7. Copie des fichiers de l'application
WORKDIR /var/www/html
COPY . .

# 8. Installation des dépendances Composer
RUN composer install --no-dev --optimize-autoloader

# 9. Permissions pour Apache
RUN chown -R www-data:www-data /var/www/html
