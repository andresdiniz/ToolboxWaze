#!/usr/bin/env python3
"""
WazeToolbox — Exemplo de consulta de radares
=============================================

Autenticação
-----------
Cada usuário possui um token pessoal gerado em:

    https://<seu-dominio>/perfil/api-token

Como obter o token (passo a passo)
-----------------------------------
1. Acesse https://<seu-dominio>/perfil/api-token
2. Clique em "Gerar meu token"
3. Copie o token exibido (64 caracteres hexadecimais)
4. Exporte como variável de ambiente:

       export WAZE_API_TOKEN='seu_token_aqui'

   ...ou edite a constante API_TOKEN abaixo.

Revogação
----------
Acesse /perfil/api-token e clique em "Revogar token" para invalidar.
"""

import argparse
import json
import os
import sys
from pathlib import Path

import requests

# ---------------------------------------------------------------------------
# Configuração
# ---------------------------------------------------------------------------

BASE_URL  = os.getenv('WAZE_BASE_URL', 'https://<seu-dominio>')
API_TOKEN = os.getenv('WAZE_API_TOKEN', 'COLE_SEU_TOKEN_AQUI')

HEADERS = {
    'Authorization': f'Bearer {API_TOKEN}',
    'Accept':        'application/json',
}

# ---------------------------------------------------------------------------
# Funções
# ---------------------------------------------------------------------------

def consultar_individual(numero_serie: str | None = None,
                         numero_inmetro: str | None = None) -> dict | None:
    """Busca um único radar por Número de Série ou Número INMETRO."""
    params = {}
    if numero_serie:
        params['numero_serie'] = numero_serie
    elif numero_inmetro:
        params['numero_inmetro'] = numero_inmetro
    else:
        raise ValueError('Informe numero_serie ou numero_inmetro')

    resp = requests.get(
        f'{BASE_URL}/api/radares/consultar',
        headers=HEADERS,
        params=params,
        timeout=30,
    )

    if resp.status_code == 401:
        print('Erro 401: Token inválido. Obtenha um em /perfil/api-token')
        sys.exit(1)
    if resp.status_code == 404:
        return None
    resp.raise_for_status()
    return resp.json()


def consultar_lote(numeros_serie: list[str] | None = None,
                   numeros_inmetro: list[str] | None = None) -> list[dict]:
    """Busca até 100 radares em uma única requisição."""
    body = {}
    if numeros_serie:
        body['numeros_serie'] = numeros_serie
    if numeros_inmetro:
        body['numeros_inmetro'] = numeros_inmetro

    resp = requests.post(
        f'{BASE_URL}/api/radares/consultar/lote',
        headers={**HEADERS, 'Content-Type': 'application/json'},
        data=json.dumps(body),
        timeout=30,
    )

    if resp.status_code == 401:
        print('Erro 401: Token inválido. Obtenha um em /perfil/api-token')
        sys.exit(1)
    resp.raise_for_status()
    return resp.json().get('resultados', [])


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    if API_TOKEN == 'COLE_SEU_TOKEN_AQUI':
        print('Configure WAZE_API_TOKEN ou edite API_TOKEN neste script.')
        print('Obtenha em: https://<seu-dominio>/perfil/api-token')
        sys.exit(1)

    parser = argparse.ArgumentParser(description='Consulta radares via API WazeToolbox')
    group  = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--serie',        metavar='N', help='Busca por Número de Série')
    group.add_argument('--inmetro',      metavar='N', help='Busca por Número INMETRO')
    group.add_argument('--lote-serie',   metavar='FILE',
                       help='Arquivo .txt com um Número de Série por linha')
    group.add_argument('--lote-inmetro', metavar='FILE',
                       help='Arquivo .txt com um Número INMETRO por linha')
    args = parser.parse_args()

    if args.serie:
        resultado = consultar_individual(numero_serie=args.serie)
        if resultado:
            print(json.dumps(resultado, ensure_ascii=False, indent=2))
        else:
            print(f'Radar "{args.serie}" não encontrado.')

    elif args.inmetro:
        resultado = consultar_individual(numero_inmetro=args.inmetro)
        if resultado:
            print(json.dumps(resultado, ensure_ascii=False, indent=2))
        else:
            print(f'Radar INMETRO "{args.inmetro}" não encontrado.')

    elif args.lote_serie:
        numeros = Path(args.lote_serie).read_text().splitlines()
        numeros = [n.strip() for n in numeros if n.strip()]
        resultados = consultar_lote(numeros_serie=numeros)
        print(json.dumps(resultados, ensure_ascii=False, indent=2))
        print(f'\nEncontrados: {len(resultados)} de {len(numeros)}')

    elif args.lote_inmetro:
        numeros = Path(args.lote_inmetro).read_text().splitlines()
        numeros = [n.strip() for n in numeros if n.strip()]
        resultados = consultar_lote(numeros_inmetro=numeros)
        print(json.dumps(resultados, ensure_ascii=False, indent=2))
        print(f'\nEncontrados: {len(resultados)} de {len(numeros)}')
