FROM php:8.2-apache

# Enable Apache modules
RUN a2enmod rewrite headers

# Install zip extension (if needed)
RUN apt-get update && apt-get install -y libzip-dev && docker-php-ext-install zip

# Set working directory
WORKDIR /var/www/html

# Copy all files
COPY . .

# Create required files and directories with proper permissions
RUN mkdir -p logs && \
    echo "movie_name,message_id,channel_id" > movies.csv && \
    echo '{"requests":[],"stats":{"total":0,"pending":0,"completed":0}}' > movie_requests.json && \
    echo "[]" > delete_schedule.json && \
    echo "{}" > progress_tracking.json && \
    chmod -R 755 logs && \
    chmod 644 movies.csv movie_requests.json delete_schedule.json progress_tracking.json && \
    chown -R www-data:www-data /var/www/html

EXPOSE 80
