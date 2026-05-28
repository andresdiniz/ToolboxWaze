#!/usr/bin/env python3
"""
WazeToolbox — Exemplo de importação bulk de radares
=====================================================

Autenticação
-----------
Cada usuário possui um token pessoal gerado em:

    https://<seu-dominio>/perfil/api-token

O token é uma string de 64 caracteres hexadecimais.
Informe-o abaixo ou via variável de ambiente WAZE_API_TOKEN.

Como obter o token (passo a passo)
-----------------------------------
1. Acesse https://<seu-dominio>/perfil/api-token
2. Clique em "Gerar meu token" (ou "Regenerar token" se já tiver um)
3. Copie o token exibido na tela
4. Cole no campo API_TOKEN abaixo OU defina a variavel de ambiente:

       export WAZE_API_TOKEN='seu_token_aqui'

Revogação
----------
Se o token for comprometido, acesse a mesma página e clique em
"Revogar token". O token anterior é invalidado imediatamente.
"""

import json
import os
import sys
import time
from pathlib import Path

import requests

# ---------------------------------------------------------------------------
# Configuração
# ---------------------------------------------------------------------------

BASE_URL   = os.getenv('WAZE_BASE_URL', 'https://<seu-dominio>')
API_TOKEN  = os.getenv('WAZE_API_TOKEN', 'COLE_SEU_TOKEN_AQUI')
ENDPOINT   = f'{BASE_URL}/api/radares/importar'
BATCH_SIZE = 500          # máximo aceito pela API
RETRY_MAX  = 3            # tentativas em caso de erro 5xx
RETRY_WAIT = 5            # segundos entre tentativas

HEADERS = {
    'Authorization': f'Bearer {API_TOKEN}',
    'Content-Type':  'application/json',
    'Accept':        'application/json',
}

# ---------------------------------------------------------------------------
# Funções
# ---------------------------------------------------------------------------

def enviar_batch(radares: list[dict], batch_num: int, offset: int) -> dict:
    """Envia um batch e retorna o resultado. Retry automático em 5xx."""
    for tentativa in range(1, RETRY_MAX + 1):
        try:
            resp = requests.post(ENDPOINT, headers=HEADERS,
                                 data=json.dumps(radares, ensure_ascii=False),
                                 timeout=60)
        except requests.exceptions.Timeout:
            print(f'  [batch {batch_num}] Timeout na tentativa {tentativa}')
            if tentativa < RETRY_MAX:
                time.sleep(RETRY_WAIT)
            continue
        except requests.exceptions.ConnectionError as exc:
            print(f'  [batch {batch_num}] Erro de conexão: {exc}')
            if tentativa < RETRY_MAX:
                time.sleep(RETRY_WAIT)
            continue

        if resp.status_code == 401:
            print('\nErro 401: Token inválido ou revogado.')
            print('Acesse https://<seu-dominio>/perfil/api-token para gerar um novo token.')
            sys.exit(1)

        if resp.status_code in (200, 207):
            resultado = resp.json()
            # Ajusta índice de erro para o índice global
            for err in resultado.get('errors', []):
                err['index_global'] = offset + err.get('index', 0)
            return resultado

        if resp.status_code >= 500 and tentativa < RETRY_MAX:
            print(f'  [batch {batch_num}] Erro {resp.status_code}, tentativa {tentativa}/{RETRY_MAX}')
            time.sleep(RETRY_WAIT)
            continue

        resp.raise_for_status()

    raise RuntimeError(f'Batch {batch_num} falhou após {RETRY_MAX} tentativas.')


def importar_todos(radares: list[dict]) -> dict:
    """Divide em batches e importa todos, acumulando o resultado."""
    total    = len(radares)
    batches  = [radares[i:i + BATCH_SIZE] for i in range(0, total, BATCH_SIZE)]
    acum     = {'created': 0, 'updated': 0, 'skipped': 0, 'errors': []}

    print(f'Total: {total} radares | {len(batches)} batch(es) de até {BATCH_SIZE}')

    for num, batch in enumerate(batches, start=1):
        offset = (num - 1) * BATCH_SIZE
        print(f'  Enviando batch {num}/{len(batches)} ({len(batch)} registros)...')
        res = enviar_batch(batch, num, offset)
        acum['created']  += res.get('created', 0)
        acum['updated']  += res.get('updated', 0)
        acum['skipped']  += res.get('skipped', 0)
        acum['errors']   += res.get('errors', [])

    return acum


# ---------------------------------------------------------------------------
# Exemplo de uso
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    if API_TOKEN == 'COLE_SEU_TOKEN_AQUI':
        print('Configure a variável WAZE_API_TOKEN ou edite API_TOKEN neste script.')
        print('Obtenha seu token em: https://<seu-dominio>/perfil/api-token')
        sys.exit(1)

    # Carrega os dados (JSON ou gera lista de exemplo)
    arquivo = Path('radares.json')
    if arquivo.exists():
        radares = json.loads(arquivo.read_text(encoding='utf-8'))
        print(f'Lido {len(radares)} radares de {arquivo}')
    else:
        print('Arquivo radares.json não encontrado. Usando dados de exemplo.')
        radares = [
            {
                'numero_serie':   'ABC123456',
                'numero_inmetro': '001/2025',
                'tipo':           'fixo',
                'sentido':        'Bairro/Centro',
                'logradouro':     'Av. Brasil',
                'municipio':      'São Paulo',
                'uf':             'SP',
                'latitude':       '-23.5505199',
                'longitude':      '-46.6333094',
                'velocidade':     '60',
                'data_instalacao':'01/01/2025',
                'faixas': [
                    {'numero': '1', 'numero_serie': 'ABC123456', 'numero_inmetro': '001/2025'}
                ]
            }
        ]

    resultado = importar_todos(radares)

    print('\n=== Resultado ===')
    print(f"Criados : {resultado['created']}")
    print(f"Atualizados: {resultado['updated']}")
    print(f"Ignorados  : {resultado['skipped']}")
    print(f"Erros      : {len(resultado['errors'])}")

    if resultado['errors']:
        print('\nDetalhes dos erros:')
        for err in resultado['errors']:
            print(f"  [{err.get('index_global', '?')}] {err.get('field','?')}: {err.get('message','?')}")
