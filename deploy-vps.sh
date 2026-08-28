#!/usr/bin/env bash
set -e

cd "$(dirname "$0")"

if ! command -v docker >/dev/null 2>&1; then
    echo ">>> Instalando Docker..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
fi

if ! docker compose version >/dev/null 2>&1; then
    echo ">>> Instalando plugin docker compose..."
    apt-get update && apt-get install -y docker-compose-plugin
fi

if [ ! -f .env ]; then
    cp .env.production .env
    echo ">>> .env creado desde .env.production - EDITALO con tu IP, contrasenas y credenciales Firebase"
fi

# Generar APP_KEY si esta vacia
if ! grep -q "^APP_KEY=base64:" .env; then
    KEY="base64:$(openssl rand -base64 32)"
    sed -i "s|^APP_KEY=$|APP_KEY=${KEY}|" .env
    echo ">>> APP_KEY generada"
fi

echo ">>> Construyendo imagen (esto tarda la primera vez)..."
docker compose -f docker-compose.prod.yml build

echo ">>> Levantando contenedores..."
docker compose -f docker-compose.prod.yml up -d

sleep 5
IP=$(curl -s ifconfig.me || echo "localhost")
echo ""
echo ">>> Estado:"
docker compose -f docker-compose.prod.yml ps
echo ""
echo ">>> Verificando salud del backend..."
for i in $(seq 1 12); do
    if curl -sf "http://127.0.0.1/api/check" >/dev/null 2>&1; then
        echo ">>> Backend funcionando: http://${IP}/api/check"
        exit 0
    fi
    sleep 5
done

echo ">>> El backend no responde aun. Revisa los logs con:"
echo "    docker compose -f docker-compose.prod.yml logs -f app"
exit 1
