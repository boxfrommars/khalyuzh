FROM php:8.3-cli-alpine

RUN apk add --no-cache sqlite

WORKDIR /app

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
