#!/usr/bin/env python3
"""
Exemplo de cliente Python para a API de importação de radares do ToolboxWaze.

Uso:
    python api-import-example.py

Requisitos:
    pip install requests
"""

import hashlib
import json
import math
import os
import requests

# ---------------------------------------------------------------------------
# Configuração
# ---------------------------------------------------------------------------
API_URL   = os.getenv("TOOLBOX_API_URL", "https://wazetoolbox.acheireviews.com.br")
API_TOKEN = os.getenv("TOOLBOX_API_TOKEN", "SEU_TOKEN_AQUI")
BATCH_SIZE = 500

HEADERS = {
    "Authorization": f"Bearer {API_TOKEN}",
    "Content-Type":  "application/json",
    "Accept":        "application/json",
}


# ---------------------------------------------------------------------------
# Função de envio em batches
# ---------------------------------------------------------------------------
def importar_radares(radares: list[dict]) -> dict:
    """
    Envia todos os radares em batches de BATCH_SIZE.
    Retorna um resumo acumulado: {created, updated, skipped, errors}.
    """
    total = len(radares)
    batches = math.ceil(total / BATCH_SIZE)
    resumo = {"created": 0, "updated": 0, "skipped": 0, "errors": []}

    print(f"Enviando {total} radares em {batches} batch(es) de até {BATCH_SIZE}...")

    for i in range(batches):
        inicio = i * BATCH_SIZE
        fim    = inicio + BATCH_SIZE
        batch  = radares[inicio:fim]

        print(f"  Batch {i + 1}/{batches} ({len(batch)} registros)...", end=" ", flush=True)

        resp = requests.post(
            f"{API_URL}/api/radares/importar",
            headers=HEADERS,
            data=json.dumps(batch, ensure_ascii=False).encode("utf-8"),
            timeout=120,
        )

        if resp.status_code not in (200, 207):
            print(f"ERRO HTTP {resp.status_code}: {resp.text[:200]}")
            continue

        data = resp.json()
        resumo["created"]  += data.get("created", 0)
        resumo["updated"]  += data.get("updated", 0)
        resumo["skipped"]  += data.get("skipped", 0)
        resumo["errors"]   += data.get("errors", [])

        print(f"✓  created={data.get('created',0)}  updated={data.get('updated',0)}  skipped={data.get('skipped',0)}  erros={len(data.get('errors',[]))}")

    return resumo


# ---------------------------------------------------------------------------
# Estrutura esperada por radar
# ---------------------------------------------------------------------------
# Cada item do array segue este formato. Campos opcionais podem ser omitidos.
# "faixas" é opcional — se ausente, as faixas existentes não são alteradas.
EXEMPLO_RADAR = {
    "sigla_uf":                "SP",
    "municipio":               "São Paulo",
    "logradouro":              "Av. Paulista, 1000",
    "cep":                     "01310-100",
    "nome_empresa":            "EMPRESA XYZ LTDA",
    "cnpj_empresa":            "00.000.000/0001-00",
    "tipo_medidor":            "Fixo",
    "marca_medidor":           "PARDINI",
    "modelo_medidor":          "PV-300",
    "numero_serie":            "ABC123456",
    "capacidade":              "80km/h",
    "situacao":                "ATIVO",
    "data_verificacao":        "03/11/2025",
    "data_ultima_verificacao": "03/11/2025",
    "data_validade":           "03/11/2026",
    "data_lacre":              "03/11/2025",
    "lacre":                   "L12345",
    "numero_certificado":      "CERT-2025-001",
    "orgao_verificador":       "IPT",
    "latitude":                "-23.5630",
    "longitude":               "-46.6543",
    # Faixas (opcional)
    "faixas": [
        {
            "numero_faixa":      "1",
            "numero_inmetro":    "001/2025",
            "numero_serie":      "SN-001",
            "sentido":           "Crescente",
            "velocidade_nominal": "80",
        },
        {
            "numero_faixa":      "2",
            "numero_inmetro":    "002/2025",
            "numero_serie":      "SN-002",
            "sentido":           "Decrescente",
            "velocidade_nominal": "80",
        },
    ],
}


# ---------------------------------------------------------------------------
# Exemplo de execução
# ---------------------------------------------------------------------------
if __name__ == "__main__":
    # Substitua por sua lista real de radares
    radares = [EXEMPLO_RADAR] * 5  # 5 cópias de exemplo

    resumo = importar_radares(radares)

    print("\n=== RESUMO FINAL ===")
    print(f"  Criados:    {resumo['created']}")
    print(f"  Atualizados:{resumo['updated']}")
    print(f"  Ignorados:  {resumo['skipped']}")
    print(f"  Erros:      {len(resumo['errors'])}")
    if resumo["errors"]:
        print("  Detalhes dos erros:")
        for e in resumo["errors"][:10]:
            print(f"    [index={e['index']}] {e['msg']}")
