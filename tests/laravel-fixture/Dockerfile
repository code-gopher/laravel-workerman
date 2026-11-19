# 测试专用 PHP 8.3 镜像，使根开发依赖能够解析 Laravel 12。
FROM php:8.3-cli

# 安装验收客户端、Workerman、MariaDB 及 Laravel/PHPUnit 所需扩展。
# ctype、fileinfo、filter、hash、openssl、PDO、session、tokenizer 已由基础镜像提供。
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libcurl4-openssl-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install curl mbstring pcntl sockets pdo_mysql dom xml xmlwriter zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . /app

# 测试专用构建自行解析根 require-dev；根 lock 未入库，不能依赖宿主机产物。
RUN git config --global --add safe.directory /app \
    && composer update --no-interaction --prefer-dist --no-progress \
    && mkdir -p tests/laravel-fixture/bootstrap/cache \
        tests/laravel-fixture/storage/framework/views \
        tests/laravel-fixture/storage/logs \
        /tmp/workerman-acceptance-18768 \
        /tmp/workerman-acceptance-sessions
