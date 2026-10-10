
FROM php:8.2-apache

# Instala dependências e habilita a extensão cURL do PHP
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copia o projeto para o Apache
COPY . /var/www/html/

# Define permissões
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
