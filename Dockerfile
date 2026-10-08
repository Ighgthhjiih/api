FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    curl \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && printf 'DirectoryIndex index.html index.php\n' > /etc/apache2/conf-enabled/directoryindex.conf

RUN apt-get update && apt-get install -y \
    python3 python3-pip \
    && ln -sf /usr/bin/python3 /usr/bin/python

RUN apt-get update && apt-get install -y \
    chromium \
    xvfb \
    dumb-init \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

ENV FLARESOLVERR_VERSION=v3.3.21
RUN curl -sL https://github.com/FlareSolverr/FlareSolverr/archive/refs/tags/${FLARESOLVERR_VERSION}.tar.gz \
    | tar xz -C /tmp \
    && mv /tmp/FlareSolverr-${FLARESOLVERR_VERSION#v} /flaresolverr \
    && pip3 install --no-cache-dir --break-system-packages -r /flaresolverr/requirements.txt

ENV LANG=en_US.UTF-8 \
    LANGUAGE=en_US \
    CHROME_EXE_PATH=/usr/bin/chromium \
    HEADLESS=true

RUN apt-get update && apt-get install -y supervisor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
