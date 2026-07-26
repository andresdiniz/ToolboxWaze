#!/usr/bin/env bash
# =============================================================================
# cron/import-radares.sh
# Importa radares de TODOS os estados via endpoint HTTP (wget)
#
# Configuração na Hostinger (painel Cron Jobs):
#   Comando : /bin/bash /home/seu_usuario/public_html/cron/import-radares.sh
#   Horário : 0 3 * * *   (todo dia às 03:00)
#
# Variáveis de ambiente necessárias:
#   CRON_SECRET  — defina em .env.local ou exporte antes de chamar o script
#   BASE_URL     — URL base da aplicação (sem barra no final)
#
# Ou edite diretamente as variáveis abaixo.
# =============================================================================

set -euo pipefail

# ── Configuração ──────────────────────────────────────────────────────────────
BASE_URL="${BASE_URL:-https://wazetoolbox.acheireviews.com.br}"
CRON_SECRET="${CRON_SECRET:-COLOQUE_SEU_SECRET_AQUI}"

# Timeout em segundos — 7200 = 2 horas (suficiente para todos os 27 estados)
WGET_TIMEOUT=7200

# Parâmetros opcionais (deixe vazio para usar padrão)
SKIP_WAZE="0"       # 1 = pula etapa de links Waze
SKIP_NOTIFY="0"     # 1 = pula notificações por e-mail
UF_FILTER=""        # Ex: "SP,RJ,MG" — vazio = todos os estados

# Arquivo de log local (opcional — o servidor já grava em var/log/)
LOG_DIR="$(dirname "$0")"
LOG_FILE="${LOG_DIR}/import_$(date +%Y%m%d_%H%M).log"
# =============================================================================

# Monta URL com parâmetros
PARAMS="secret=${CRON_SECRET}&skip_waze=${SKIP_WAZE}&skip_notify=${SKIP_NOTIFY}"
if [ -n "${UF_FILTER}" ]; then
    PARAMS="${PARAMS}&uf=${UF_FILTER}"
fi

URL="${BASE_URL}/cron/import-radares?${PARAMS}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Iniciando importação de radares..." | tee -a "${LOG_FILE}"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] URL: ${BASE_URL}/cron/import-radares?secret=***" | tee -a "${LOG_FILE}"

# wget com timeout generoso — o endpoint responde imediatamente (background)
# mas mantemos timeout alto para garantir que a resposta seja recebida
RESPOSTA=$(
    wget \
        --quiet \
        --output-document=- \
        --timeout="${WGET_TIMEOUT}" \
        --tries=1 \
        --no-check-certificate \
        "${URL}" 2>> "${LOG_FILE}" || echo '{"error":"wget falhou"}'
)

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Resposta do servidor: ${RESPOSTA}" | tee -a "${LOG_FILE}"

# Verifica se a resposta contém "ok":true
if echo "${RESPOSTA}" | grep -q '"ok":true'; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✅ Importação iniciada em background no servidor." | tee -a "${LOG_FILE}"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Log do servidor em: var/log/cron_import_*.log" | tee -a "${LOG_FILE}"
    exit 0
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ❌ Erro ao iniciar importação." | tee -a "${LOG_FILE}"
    exit 1
fi
