# --------------------------------------------------------------------
# Base image
# --------------------------------------------------------------------
FROM php:8.4-fpm

# --------------------------------------------------------------------
# Arguments
# --------------------------------------------------------------------
ARG USER_UID=1000
ARG USER_GID=1000
ARG COMPOSER_ALLOW_SUPERUSER=1

# --------------------------------------------------------------------
# Use a faster Debian mirror (optional)
# --------------------------------------------------------------------
RUN sed -i 's|deb.debian.org|kartolo.sby.datautama.net.id|g' /etc/apt/sources.list.d/debian.sources || true

# --------------------------------------------------------------------
# 1️⃣ System Dependencies
# --------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
  git unzip curl sudo supervisor netcat-openbsd \
  libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev \
  libxml2-dev libzip-dev libicu-dev libonig-dev libpq-dev \
  libmagickwand-dev \
  webp \
  zip \
  && rm -rf /var/lib/apt/lists/*

# --------------------------------------------------------------------
# 2️⃣ PHP Extensions
# --------------------------------------------------------------------
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
  && docker-php-ext-install -j$(nproc) gd intl mbstring pdo pdo_pgsql pgsql zip xml bcmath pcntl exif

## Install Imagick extension for better image processing performance/quality
RUN pecl install imagick \
  && docker-php-ext-enable imagick

RUN pecl install redis && docker-php-ext-enable redis

# --------------------------------------------------------------------
# 3️⃣ PHP Configuration
# --------------------------------------------------------------------
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini

# --------------------------------------------------------------------
# 4️⃣ Composer (from official image)
# --------------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --------------------------------------------------------------------
# 4️⃣ Create non-root user (same UID/GID as host)
# --------------------------------------------------------------------
RUN groupadd -g ${USER_GID} foto && \
    useradd -u ${USER_UID} -g foto -m -s /bin/bash foto && \
    usermod -aG sudo foto

# --------------------------------------------------------------------
# 5️⃣ Set working directory
# --------------------------------------------------------------------
WORKDIR /var/www/html

# --------------------------------------------------------------------
# 6️⃣ Composer configuration
# --------------------------------------------------------------------
ENV COMPOSER_MEMORY_LIMIT=-1
ENV COMPOSER_PROCESS_TIMEOUT=2000
## Prefer Imagick for Spatie MediaLibrary (can override via .env)
ENV IMAGE_DRIVER=imagick

# Copy only composer files first (for caching)
COPY composer.json composer.lock* ./

RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader --no-scripts

# --------------------------------------------------------------------
# 7️⃣ Copy full application
# --------------------------------------------------------------------
COPY . .

# --------------------------------------------------------------------
# 8️⃣ Run post-install scripts
# --------------------------------------------------------------------
RUN composer run-script post-autoload-dump || true

# --------------------------------------------------------------------
# 9️⃣ Permissions
# --------------------------------------------------------------------
RUN chown -R foto:foto /var/www/html && chmod -R 775 storage bootstrap/cache || true

# --------------------------------------------------------------------
# 🔟 Supervisor configuration
# --------------------------------------------------------------------
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY wait-for-redis.sh /usr/local/bin/wait-for-redis.sh

RUN chmod +x /usr/local/bin/wait-for-redis.sh \
 && mkdir -p /var/log/supervisor \
 && chown -R foto:foto /var/log/supervisor \
 && chmod 1777 /tmp

# --------------------------------------------------------------------
# 11️⃣ Environment & runtime
# --------------------------------------------------------------------
EXPOSE 9000
ENV PATH="/usr/local/bin:/usr/local/sbin:/usr/sbin:/usr/bin:/sbin:/bin"

USER foto

# Start Supervisor (manages php-fpm + horizon)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
