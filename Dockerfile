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

# Copy all files
COPY . .

# Create missing files if they don't exist
RUN if [ ! -f "movies.csv" ]; then \
    echo "movie_name,message_id,channel_id,added_at" > movies.csv; \
    fi && \
    if [ ! -f "users.json" ]; then \
    echo '{"users":[],"stats":{"total_searches":0,"last_updated":null}}' > users.json; \
    fi

# Create directories if they don't exist
RUN mkdir -p logs uploads backups

# File permissions set karo
RUN chmod 755 /var/www/html && \
    chmod 644 /var/www/html/movies.csv && \
    chmod 644 /var/www/html/users.json && \
    chmod 755 /var/www/html/uploads/ && \
    chmod 755 /var/www/html/logs/ && \
    chmod 755 /var/www/html/backups/ && \
    chown -R www-data:www-data /var/www/html

# Composer dependencies (agar required ho)
RUN if [ -f "composer.json" ]; then composer install --no-dev --optimize-autoloader; fi

EXPOSE 80
