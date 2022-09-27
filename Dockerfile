FROM php:8.0

RUN mkdir /app

WORKDIR /app

RUN apt-get update && apt-get install -y  git curl libc-client-dev libkrb5-dev libzip-dev  zip && rm -r /var/lib/apt/lists/*
RUN docker-php-ext-install imap
RUN docker-php-ext-configure imap --with-kerberos --with-imap-ssl 
RUN docker-php-ext-install  zip
RUN docker-php-ext-configure zip --with-libzip

   

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
#RUN    php -r "if (hash_file('sha384', 'composer-setup.php') === '756890a4488ce9024fc62c56153228907f1545c228516cbf63f885e036d37e9a59d27d63f46af1d4d07ee0f76181c7d3') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
RUN    php composer-setup.php
RUN    php -r "unlink('composer-setup.php');"



COPY  index.php index.php
COPY composer.json composer.json
#COPY .env .env

RUN php composer.phar update
CMD ["php", "/app/index.php"]
