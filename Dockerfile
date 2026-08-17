FROM php:8.3-cli-alpine

RUN apk add --no-cache openssl bash sqlite-libs curl libzip \
    && apk add --no-cache --virtual .build-deps sqlite-dev curl-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_sqlite curl zip \
    && apk del .build-deps

WORKDIR /app
COPY src /app/src
COPY public /app/public
COPY entrypoint.sh /app/entrypoint.sh
RUN chmod +x /app/entrypoint.sh

VOLUME /app/data
EXPOSE 80
ENTRYPOINT ["/app/entrypoint.sh"]
