FROM php:8.2-apache

# System dependencies install karo
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-install zip

# Composer install karo
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Apache configuration
RUN a2enmod rewrite headers
COPY .htaccess /var/www/html/.htaccess
RUN chown -R www-data:www-data /var/www/html

# Working directory
WORKDIR /var/www/html

# Copy files
COPY . .

# File permissions set karo
RUN chmod 755 /var/www/html && \
    chmod 644 /var/www/html/movies.csv && \
    chmod 644 /var/www/html/users.json && \
    chmod 755 /var/www/html/uploads/ && \
    chown -R www-data:www-data /var/www/html

# Composer dependencies (agar required ho)
RUN if [ -f "composer.json" ]; then composer install --no-dev --optimize-autoloader; fi

EXPOSE 80
