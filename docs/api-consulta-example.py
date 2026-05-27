#!/usr/bin/env python3
"""
Cliente Python para a API de consulta de radares do ToolboxWaze.

Uso:
    # Consulta individual
    python api-consulta-example.py --serie ABC123
    python api-consulta-example.py --inmetro 001/2025

    # Consulta em lote (arquivo .txt com um número por linha)
    python api-consulta-example.py --lote-serie series.txt
    python api-consulta-example.py --lote-inmetro inmetros.txt

Requisitos:
    pip install requests
"""

import argparse
import json
import math
import os
import logging
import requests

logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
log = logging.getLogger(__name__)

API_URL   = os.environ["TOOLBOX_API_URL"]    # ex: https://wazetoolbox.exemplo.com.br
API_TOKEN = os.environ["TOOLBOX_API_TOKEN"]
LOTE_SIZE = 100

HEADERS = {
    "Authorization": f"Bearer {API_TOKEN}",
    "Content-Type":  "application/json",
    "Accept":        "application/json",
}


# ---------------------------------------------------------------------------
# Consulta individual
# ---------------------------------------------------------------------------

def consultar_por_serie(numero_serie: str) -> dict | None:
    """
    Busca um radar pelo número de série do medidor ou da faixa.
    Retorna o dict do radar ou None se não encontrado.
    """
    resp = requests.get(
        f"{API_URL}/api/radares/consultar",
        headers=HEADERS,
        params={"numero_serie": numero_serie},
        timeout=30,
    )
    if resp.status_code == 404:
        return None
    resp.raise_for_status()
    return resp.json()


def consultar_por_inmetro(numero_inmetro: str) -> dict | None:
    """
    Busca um radar pelo número INMETRO da faixa.
    Retorna o dict do radar ou None se não encontrado.
    """
    resp = requests.get(
        f"{API_URL}/api/radares/consultar",
        headers=HEADERS,
        params={"numero_inmetro": numero_inmetro},
        timeout=30,
    )
    if resp.status_code == 404:
        return None
    resp.raise_for_status()
    return resp.json()


# ---------------------------------------------------------------------------
# Consulta em lote
# ---------------------------------------------------------------------------

def consultar_lote_serie(numeros: list[str]) -> list[dict]:
    """
    Consulta até LOTE_SIZE números de série por requisição.
    Retorna lista acumulada de radares encontrados.
    """
    return _consultar_lote({"numeros_serie": numeros})


def consultar_lote_inmetro(numeros: list[str]) -> list[dict]:
    """
    Consulta até LOTE_SIZE números INMETRO por requisição.
    Retorna lista acumulada de radares encontrados.
    """
    return _consultar_lote({"numeros_inmetro": numeros})


def _consultar_lote(payload_base: dict) -> list[dict]:
    chave    = list(payload_base.keys())[0]
    numeros  = payload_base[chave]
    total    = len(numeros)
    batches  = math.ceil(total / LOTE_SIZE) if total else 0
    todos    = []

    log.info("Consultando %d número(s) em %d batch(es)...", total, batches)

    for i in range(batches):
        inicio = i * LOTE_SIZE
        batch  = numeros[inicio: inicio + LOTE_SIZE]

        resp = requests.post(
            f"{API_URL}/api/radares/consultar/lote",
            headers=HEADERS,
            json={chave: batch},
            timeout=60,
        )
        resp.raise_for_status()
        data = resp.json()
        todos.extend(data.get("resultados", []))
        log.info("  Batch %d/%d → %d encontrado(s)", i + 1, batches, len(data.get("resultados", [])))

    return todos


# ---------------------------------------------------------------------------
# Exibição do radar
# ---------------------------------------------------------------------------

def exibir_radar(radar: dict) -> None:
    print("\n" + "─" * 60)
    print(f"  Medidor:   {radar.get('tipo_medidor')} | {radar.get('marca_medidor')} {radar.get('modelo_medidor')}")
    print(f"  N/S:       {radar.get('numero_serie')}")
    print(f"  Local:     {radar.get('logradouro')}, {radar.get('municipio')}/{radar.get('sigla_uf')}")
    print(f"  Situação:  {radar.get('situacao')}")
    print(f"  Validade:  {radar.get('data_validade')}")
    print(f"  Verificação efetiva: {radar.get('data_verificacao_efetiva')}")
    if radar.get('latitude'):
        print(f"  Coords:    {radar.get('latitude')}, {radar.get('longitude')}")
    if radar.get('link_waze'):
        print(f"  Waze:      {radar.get('link_waze')}")
    faixas = radar.get('faixas', [])
    if faixas:
        print(f"  Faixas ({len(faixas)}):")
        for f in faixas:
            print(f"    [{f.get('numero_faixa')}] INMETRO={f.get('numero_inmetro')}  "
                  f"N/S={f.get('numero_serie')}  "
                  f"{f.get('sentido')}  {f.get('velocidade_nominal')}km/h")
    print("─" * 60)


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(description="Consulta radares via API ToolboxWaze")
    grp = parser.add_mutually_exclusive_group(required=True)
    grp.add_argument("--serie",        metavar="NS",       help="Número de série único")
    grp.add_argument("--inmetro",      metavar="NI",       help="Número INMETRO único")
    grp.add_argument("--lote-serie",   metavar="ARQUIVO",  help="Arquivo .txt com números de série (1 por linha)")
    grp.add_argument("--lote-inmetro", metavar="ARQUIVO",  help="Arquivo .txt com números INMETRO (1 por linha)")
    args = parser.parse_args()

    if args.serie:
        radar = consultar_por_serie(args.serie)
        if radar is None:
            log.warning("Radar não encontrado para N/S: %s", args.serie)
        else:
            exibir_radar(radar)

    elif args.inmetro:
        radar = consultar_por_inmetro(args.inmetro)
        if radar is None:
            log.warning("Radar não encontrado para INMETRO: %s", args.inmetro)
        else:
            exibir_radar(radar)

    elif args.lote_serie:
        with open(args.lote_serie) as f:
            numeros = [l.strip() for l in f if l.strip()]
        radares = consultar_lote_serie(numeros)
        log.info("%d radar(es) encontrado(s) de %d consultado(s).", len(radares), len(numeros))
        for r in radares:
            exibir_radar(r)

    elif args.lote_inmetro:
        with open(args.lote_inmetro) as f:
            numeros = [l.strip() for l in f if l.strip()]
        radares = consultar_lote_inmetro(numeros)
        log.info("%d radar(es) encontrado(s) de %d consultado(s).", len(radares), len(numeros))
        for r in radares:
            exibir_radar(r)


if __name__ == "__main__":
    main()
