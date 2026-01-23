# ================================================
# DOCKERFILE FOR TELEGRAM MOVIE BOT PRO
# ================================================
# Created: 21st January 2026
# Version: 3.0.0 Ultimate Pro
# ================================================

FROM php:8.1-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    sockets

# Enable Apache mod_rewrite
RUN a2enmod rewrite headers

# Configure Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN echo "AllowEncodedSlashes On" >> /etc/apache2/apache2.conf

# Set PHP configuration
RUN echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/docker-php-memlimit.ini
RUN echo "upload_max_filesize = 50M" >> /usr/local/etc/php/conf.d/docker-php-upload.ini
RUN echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/docker-php-upload.ini
RUN echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/docker-php-maxexectime.ini
RUN echo "display_errors = Off" >> /usr/local/etc/php/conf.d/docker-php-displayerrors.ini
RUN echo "log_errors = On" >> /usr/local/etc/php/conf.d/docker-php-displayerrors.ini
RUN echo "error_log = /var/log/php_errors.log" >> /usr/local/etc/php/conf.d/docker-php-displayerrors.ini
RUN echo "date.timezone = Asia/Kolkata" >> /usr/local/etc/php/conf.d/docker-php-timezone.ini

# Create necessary directories
RUN mkdir -p /var/www/html/logs \
    /var/www/html/backups \
    /var/www/html/uploads \
    /var/www/html/cache \
    /var/www/html/temp

# Set permissions
RUN chmod -R 755 /var/www/html \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 777 /var/www/html/logs \
    && chmod -R 777 /var/www/html/backups \
    && chmod -R 777 /var/www/html/uploads \
    && chmod -R 777 /var/www/html/cache \
    && chmod -R 777 /var/www/html/temp

# Copy application files
COPY . /var/www/html/

# Set Apache document root
ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
