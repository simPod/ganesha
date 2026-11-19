FROM php:8.5-cli

COPY install_composer.sh /tmp/

RUN apt-get update \
 && apt-get install --no-install-recommends -y git \
 && apt-get install --no-install-recommends -y libmemcached-dev \
 && apt-get install --no-install-recommends -y libssl-dev \
 && apt-get install --no-install-recommends -y zlib1g-dev \
 && apt-get install --no-install-recommends -y unzip \
 && apt-get install --no-install-recommends -y libzip-dev \
 && apt-get install --no-install-recommends -y git \
 && yes '' | pecl install zip \
 && echo 'extension=zip.so' >> /usr/local/etc/php/php.ini \
 && yes '' | pecl install memcached-3.4.0 \
 && echo 'extension=memcached.so' >> /usr/local/etc/php/php.ini \
 && yes '' | pecl install redis-6.3.0 \
 && echo 'extension=redis.so' >> /usr/local/etc/php/php.ini \
 && yes '' | pecl install xdebug \
 && echo 'zend_extension=xdebug.so' >> /usr/local/etc/php/php.ini \
 && yes '' | pecl install mongodb-2.5.7 \
 && echo 'extension=mongodb.so' >> /usr/local/etc/php/php.ini \
 && yes '' | pecl install apcu \
 && echo 'extension=apcu.so' >> /usr/local/etc/php/php.ini \
 && echo 'apc.enable_cli=1' >> /usr/local/etc/php/php.ini \
 && useradd -m ganesha \
 && rm -rf /var/lib/apt/lists/* \
 && rm -rf /var/cache/apt/* \
 # Install composer
 # https://getcomposer.org/download/
 && /tmp/install_composer.sh \
 && mv composer.phar /usr/local/bin/composer
