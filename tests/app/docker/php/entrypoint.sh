#!/bin/sh

composer install --prefer-dist --no-interaction \
&& exec "$@"
