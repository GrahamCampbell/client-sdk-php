FROM php:8.4-cli

RUN apt-get update && apt-get install -y -q git rake ruby-ronn zlib1g-dev libtool make gcc && apt-get clean

RUN cd /usr/local/bin && curl -sS https://getcomposer.org/installer | php
RUN cd /usr/local/bin && mv composer.phar composer
