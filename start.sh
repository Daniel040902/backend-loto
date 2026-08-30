#!/bin/sh
set -e

NGINX_CONF_DIR="/etc/nginx/http.d"
if [ ! -d "$NGINX_CONF_DIR" ]; then
    NGINX_CONF_DIR="/etc/nginx/conf.d"
fi

export PORT="${PORT:-8080}"
envsubst '${PORT}' < "${NGINX_CONF_DIR}/default.conf.template" > "${NGINX_CONF_DIR}/default.conf"

# Permisos de storage para que php-fpm (www-data) y los procesos CLI puedan escribir logs
chmod -R 777 /var/www/storage 2>/dev/null || true

# FCM: materializa las credenciales del service account.
# 1) Si está definida la variable FIREBASE_CREDENTIALS_JSON, la escribe directamente.
# 2) Si no, copia el archivo firebase-credentials.json desde la raíz del proyecto
#    (caso VPS: el Dockerfile hace COPY . ., y suele estar en /var/www/firebase-credentials.json).
mkdir -p /var/www/storage/app
if [ -n "$FIREBASE_CREDENTIALS_JSON" ]; then
    echo "FCM: FIREBASE_CREDENTIALS_JSON definida"
    echo "$FIREBASE_CREDENTIALS_JSON" > /var/www/storage/app/firebase-credentials.json
    echo "FCM: firebase-credentials.json creado desde FIREBASE_CREDENTIALS_JSON ($(wc -c < /var/www/storage/app/firebase-credentials.json) bytes)"
elif [ -f /var/www/firebase-credentials.json ]; then
    cp /var/www/firebase-credentials.json /var/www/storage/app/firebase-credentials.json
    echo "FCM: firebase-credentials.json copiado desde raíz ($(wc -c < /var/www/storage/app/firebase-credentials.json) bytes)"
elif [ -f /var/www/storage/app/firebase-credentials.json ]; then
    echo "FCM: firebase-credentials.json ya existe en storage/app ($(wc -c < /var/www/storage/app/firebase-credentials.json) bytes)"
else
    echo "FCM: no se encontraron credenciales (FIREBASE_CREDENTIALS_JSON vacía y sin archivo firebase-credentials.json)"
fi

php-fpm -D

php /var/www/artisan migrate --force --no-interaction >/dev/null 2>&1 || true
php /var/www/artisan db:seed --force --no-interaction >/dev/null 2>&1 || true

if [ "$QUEUE_CONNECTION" = "redis" ]; then
    (
        while true; do
            php /var/www/artisan queue:work redis --sleep=3 --tries=3 --timeout=150 --max-time=3540 -q 2>/dev/null
            sleep 2
        done
    ) &
    echo "QUEUE: worker redis iniciado (reinicio automatico cada ~1h)"
else
    echo "QUEUE: QUEUE_CONNECTION=${QUEUE_CONNECTION:-no-definida} (worker no iniciado)"
fi

php /var/www/artisan schedule:work 2>/dev/null &

nginx -g 'daemon off;'
