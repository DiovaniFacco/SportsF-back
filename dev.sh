#!/usr/bin/env bash

# ==============================================================================
# SFACCO - Script de Inicialização & Reinicialização Unificada (Back + Front)
# ==============================================================================

BACKEND_DIR="/home/diovanifacco/back"
FRONTEND_DIR="/home/diovanifacco/front"

# Cores ANSI para o terminal
CYAN='\033[1;36m'
GREEN='\033[1;32m'
RED='\033[1;31m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m' # Reset color

EXPO_ARGS=""
if [[ "$1" == "--clear" || "$1" == "-c" ]]; then
    EXPO_ARGS="--clear"
    echo -e "${YELLOW}🧹 Limpeza de cache ativada para o Expo (--clear)${NC}"
fi

echo -e "${YELLOW}====================================================${NC}"
echo -e "${BOLD}${YELLOW}   🚀 SFACCO: Reiniciando Backend & Frontend...     ${NC}"
echo -e "${YELLOW}====================================================${NC}"

# 1. Finalizar processos anteriores
echo -e "${RED}[1/3] Limpando processos e portas anteriores (8081)...${NC}"
fuser -k -9 8081/tcp 2>/dev/null
pkill -9 -f "expo start" 2>/dev/null
pkill -9 -f "php artisan serve" 2>/dev/null
sleep 1

# 2. Configurar tratamento de encerramento (Ctrl+C)
cleanup() {
    echo -e "\n${RED}====================================================${NC}"
    echo -e "${RED} 🛑 Encerrando Backend e Frontend...                 ${NC}"
    echo -e "${RED}====================================================${NC}"
    kill 0 2>/dev/null
    exit 0
}
trap cleanup SIGINT SIGTERM EXIT

# 3. Preparar Backend (Laravel)
echo -e "${CYAN}[2/3] Atualizando Backend (Laravel)...${NC}"
cd "$BACKEND_DIR" || exit 1

# Limpar cache do Laravel
php artisan optimize:clear > /dev/null 2>&1
echo -e "${CYAN}[BACK] Caches do Laravel limpos com sucesso!${NC}"

# Verificar se a porta 8000 já está em uso (ex: Nginx / PHP-FPM)
if curl -s -o /dev/null -w "%{http_code}" http://localhost:8000 | grep -q "200\|302\|404\|401"; then
    echo -e "${CYAN}[BACK] Backend (Nginx + PHP-FPM) ativo e servindo em http://localhost:8000${NC}"
else
    echo -e "${CYAN}[BACK] Iniciando php artisan serve em http://localhost:8000...${NC}"
    (
        php artisan serve --port=8000 2>&1 | sed -u $'s/^/\e[1;36m[BACK]\e[0m /'
    ) &
fi

# 4. Iniciar Frontend (Expo)
echo -e "${GREEN}[3/3] Iniciando Frontend (Expo)...${NC}"
(
    cd "$FRONTEND_DIR" || exit 1
    npx expo start $EXPO_ARGS 2>&1 | sed -u $'s/^/\e[1;32m[FRONT]\e[0m /'
) &

echo -e "${GREEN}====================================================${NC}"
echo -e "${BOLD}${GREEN} ✅ Back & Front operacionais!                      ${NC}"
echo -e "${GREEN} 💡 Pressione ${BOLD}Ctrl + C${NC}${GREEN} a qualquer momento para parar.${NC}"
echo -e "${GREEN}====================================================${NC}"

# Manter o script rodando e escutando logs
wait
