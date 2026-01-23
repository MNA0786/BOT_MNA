FROM php:8.2-apache

# System dependencies install karo
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install zip

# Apache configuration
RUN a2enmod rewrite headers
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Working directory
WORKDIR /var/www/html

# Copy all files
COPY . .

# Create necessary directories
RUN mkdir -p uploads logs backups \
    && chmod -R 755 uploads logs backups

# File permissions set karo
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 664 movies.csv users.json bot_stats.json 2>/dev/null || true

# Create default files if they don't exist
RUN if [ ! -f "movies.csv" ]; then \
    echo "movie_name,message_id,channel_id,added_at" > movies.csv; \
    fi

RUN if [ ! -f "users.json" ]; then \
    echo '{"users":{},"stats":{"total_searches":0,"total_users":0,"last_updated":null},"message_logs":[],"total_requests":0}' > users.json; \
    fi

RUN if [ ! -f "bot_stats.json" ]; then \
    echo '{"total_movies":0,"total_users":0,"total_searches":0,"last_updated":"'$(date -Iseconds)'"}' > bot_stats.json; \
    fi

# Copy .htaccess
COPY .htaccess .htaccess

# Apache config
RUN chmod 644 .htaccess

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start Apache
CMD ["apache2-foreground"]