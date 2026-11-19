FROM php:8.5.12-fpm-trixie@sha256:baf3ea13a8207fa18e1011bad1016e8b5e59fc974c6966d0efa6f36a16f875f0 AS php-base
FROM node:26.13.1-trixie-slim@sha256:e6bedef217677b276c591795e169840edd9096dbf13f29df8ef8d1856b8820bc AS node
FROM composer:2.11.2@sha256:0c8e663f14c94d07b3c45b175c51f5a14718508f21fe4dd7e54f09488dd0ed71 AS composer

FROM php-base AS ocr-build
RUN apt-get update \
    && apt-get install -y --no-install-recommends cmake libleptonica-dev \
    && rm -rf /var/lib/apt/lists/*
RUN curl --fail --silent --show-error --location --retry 3 \
        https://codeload.github.com/tesseract-ocr/tesseract/tar.gz/refs/tags/5.5.4 \
        --output /tmp/tesseract.tar.gz \
    && echo 'a8dbf8b8c8ee427ff6ad1d99116502feaf59440af1adb893caf60a6576826cf4  /tmp/tesseract.tar.gz' | sha256sum --check \
    && mkdir /tmp/tesseract \
    && tar -xzf /tmp/tesseract.tar.gz --strip-components=1 -C /tmp/tesseract \
    && cmake -S /tmp/tesseract -B /tmp/tesseract/build \
        -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF \
        -DBUILD_TRAINING_TOOLS=OFF -DDISABLE_ARCHIVE=ON -DDISABLE_CURL=ON \
    && cmake --build /tmp/tesseract/build --parallel 4 \
    && cmake --install /tmp/tesseract/build --prefix /opt/tesseract

FROM php-base AS tooling
RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev libpq-dev libstdc++6 unzip \
    && docker-php-ext-install -j4 bcmath intl pcntl pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/ /usr/local/lib/node_modules/
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
WORKDIR /var/www/html

FROM node AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources/ resources/
RUN npm run build

FROM tooling AS app
RUN apt-get update \
    && apt-get install -y --no-install-recommends libleptonica6 \
        tesseract-ocr-eng tesseract-ocr-ind tesseract-ocr-osd \
    && rm -rf /var/lib/apt/lists/*
COPY --from=ocr-build /opt/tesseract/bin/tesseract /usr/local/bin/tesseract
COPY --from=ocr-build /opt/tesseract/share/tessdata/ /usr/share/tesseract-ocr/5/tessdata/
ENV TESSDATA_PREFIX=/usr/share/tesseract-ocr/5/tessdata
RUN tesseract --version && tesseract --list-langs

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache COMPOSER_ALLOW_SUPERUSER=1 \
    composer install --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY artisan phpunit.xml ./
COPY app/ app/
COPY bootstrap/ bootstrap/
COPY config/ config/
COPY database/ database/
COPY public/ public/
COPY resources/ resources/
COPY routes/ routes/
COPY storage/ storage/
COPY tests/ tests/
COPY --from=assets /build/public/build/ public/build/
COPY docker/uploads.ini /usr/local/etc/php/conf.d/99-gigkampus-uploads.ini
COPY --chmod=755 docker/start-app.sh /usr/local/bin/start-app
# Compose injects the runtime environment. The image has a blank dotenv file for Laravel.
RUN touch .env \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-interaction --optimize \
    && chown -R www-data:www-data bootstrap/cache storage
CMD ["start-app"]

FROM nginx:1.30.7-trixie@sha256:7fad6c0dff97965adbddf28027d934b111fc0221a554315dc274712f3fba24d3 AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY public/ /var/www/html/public/
COPY --from=assets /build/public/build/ /var/www/html/public/build/
