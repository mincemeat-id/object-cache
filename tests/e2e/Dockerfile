FROM wordpress:6.9.4-php8.4-apache

RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
	pecl install redis-6.3.0; \
	docker-php-ext-enable redis; \
	apt-get purge -y --auto-remove $PHPIZE_DEPS; \
	rm -rf /var/lib/apt/lists/*; \
	curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; \
	chmod +x /usr/local/bin/wp
