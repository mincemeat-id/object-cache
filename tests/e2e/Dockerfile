FROM wordpress:php8.4-apache

ARG WORDPRESS_VERSION=6.9.5

RUN set -eux; \
	case "$WORDPRESS_VERSION" in \
		trunk) wordpress_url='https://wordpress.org/nightly-builds/wordpress-latest.tar.gz' ;; \
		*) wordpress_url="https://wordpress.org/wordpress-$WORDPRESS_VERSION.tar.gz" ;; \
	esac; \
	curl -fL --retry 3 --retry-all-errors -o /tmp/wordpress.tar.gz "$wordpress_url"; \
	cp /usr/src/wordpress/wp-config-docker.php /tmp/wp-config-docker.php; \
	rm -rf /usr/src/wordpress; \
	tar -xzf /tmp/wordpress.tar.gz -C /usr/src; \
	mv /tmp/wp-config-docker.php /usr/src/wordpress/wp-config-docker.php; \
	rm /tmp/wordpress.tar.gz; \
	apt-get update; \
	apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
	pecl install redis-6.3.0; \
	docker-php-ext-enable redis; \
	apt-get purge -y --auto-remove $PHPIZE_DEPS; \
	rm -rf /var/lib/apt/lists/*; \
	curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; \
	chmod +x /usr/local/bin/wp
