FROM php:8.2-apache

# ---------- Dependências do PHP ----------
RUN apt-get update && apt-get install -y \
    curl \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && printf 'DirectoryIndex index.html index.php\n' > /etc/apache2/conf-enabled/directoryindex.conf

# ---------- Python (necessário pro FlareSolverr) ----------
RUN apt-get update && apt-get install -y \
    python3 python3-pip \
    && ln -sf /usr/bin/python3 /usr/bin/python

# ---------- Chromium + deps do FlareSolverr ----------
RUN apt-get update && apt-get install -y \
    chromium \
    xvfb \
    dumb-init \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ---------- FlareSolverr ----------
ENV FLARESOLVERR_VERSION=v3.3.21
RUN git clone --depth 1 --branch ${FLARESOLVERR_VERSION} https://github.com/FlareSolverr/FlareSolverr.git /flaresolverr \
    && pip3 install --no-cache-dir --break-system-packages -r /flaresolverr/requirements.txt

ENV LANG=en_US.UTF-8 \
    LANGUAGE=en_US \
    CHROME_EXE_PATH=/usr/bin/chromium \
    HEADLESS=true

# ---------- Supervisor pra rodar Apache + FlareSolverr juntos ----------
RUN apt-get update && apt-get install -y supervisor
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
