#!/usr/bin/env python3
"""
importar_escolas.py
===================
Importa o CSV do Censo Escolar INEP para o banco MySQL do WazeToolbox,
respeitando a entity EscolaInep:
  - INSERT para escolas novas (pelo codigoInep / identity_hash)
  - UPDATE apenas se row_hash mudou (dados alterados)
  - Preserva latitude, longitude, link_waze, link_area_escolar existentes

Dependências:
    pip install pandas mysql-connector-python tqdm

Uso:
    python importar_escolas.py --csv microdados_escolas.csv
    python importar_escolas.py --csv dados.csv --host localhost --user root --password senha --database wazetoolbox
"""

import argparse
import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

import mysql.connector
import pandas as pd
from tqdm import tqdm

# ──────────────────────────────────────────────────────────────────────────────
# Mapeamento coluna CSV → coluna do banco (entity EscolaInep)
# Ajuste os nomes das colunas conforme o cabeçalho real do seu CSV INEP.
# ──────────────────────────────────────────────────────────────────────────────
COLUMN_MAP: dict[str, str] = {
    "Restrição de Atendimento":                   "restricao_atendimento",
    "Escola":                                     "escola",
    "Código INEP":                                "codigo_inep",
    "UF":                                         "uf",
    "Município":                                  "municipio",
    "Localização":                                "localizacao",
    "Localidade Diferenciada":                    "localidade_diferenciada",
    "Categoria Administrativa":                   "categoria_administrativa",
    "Endereço":                                   "endereco",
    "Telefone":                                   "telefone",
    "Dependência Administrativa":                 "dependencia_administrativa",
    "Categoria Escola Privada":                   "categoria_escola_privada",
    "Conveniada Poder Público":                   "conveniada",
    "Regulamentação pelo Conselho de Educação":   "regulamentacao",
    "Porte":                                      "porte",
    "Etapas e Modalidade de Ensino Oferecidas":   "etapas_ensino",
    "Outras Ofertas Educacionais":                "outras_ofertas",
    "Latitude":                                   "latitude",
    "Longitude":                                  "longitude",
}

# ──────────────────────────────────────────────────────────────────────────────
# Helpers
# ──────────────────────────────────────────────────────────────────────────────

def sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def make_identity_hash(codigo_inep: str) -> str:
    """Hash imutável baseado no Código INEP."""
    return sha256(codigo_inep.strip().upper())


def make_row_hash(d: dict) -> str:
    """Hash de todos os dados mapeados — detecta qualquer alteração no CSV."""
    skip = {"row_hash", "identity_hash", "imported_at", "updated_at",
            "link_waze", "permanent_hazard_id", "link_area_escolar", "raw_data"}
    parts = {k: str(v or "").strip() for k, v in d.items() if k not in skip}
    return sha256(json.dumps(parts, sort_keys=True, ensure_ascii=False))


def clean(val) -> str | None:
    if val is None:
        return None
    try:
        if pd.isna(val):
            return None
    except (TypeError, ValueError):
        pass
    v = str(val).strip()
    return v or None


def csv_row_to_db(csv_row: pd.Series) -> dict:
    d = {}
    for csv_col, db_col in COLUMN_MAP.items():
        d[db_col] = clean(csv_row.get(csv_col))
    return d


# ──────────────────────────────────────────────────────────────────────────────
# Banco de dados
# ──────────────────────────────────────────────────────────────────────────────

def connect(cfg: dict) -> mysql.connector.MySQLConnection:
    return mysql.connector.connect(
        host=cfg["host"],
        port=cfg.get("port", 3306),
        user=cfg["user"],
        password=cfg["password"],
        database=cfg["database"],
        charset="utf8mb4",
        autocommit=False,
    )


def load_existing(cursor) -> dict[str, dict]:
    """
    Retorna dict: identity_hash → {id, row_hash, latitude, longitude,
                                    link_waze, link_area_escolar, permanent_hazard_id}
    Carregado inteiramente em memória para evitar N queries.
    """
    cursor.execute(
        """SELECT id, identity_hash, row_hash,
                  latitude, longitude,
                  link_waze, link_area_escolar, permanent_hazard_id
           FROM escola_inep
           WHERE identity_hash IS NOT NULL"""
    )
    return {
        row[1]: {
            "id":                  row[0],
            "row_hash":            row[2],
            "latitude":            row[3],
            "longitude":           row[4],
            "link_waze":           row[5],
            "link_area_escolar":   row[6],
            "permanent_hazard_id": row[7],
        }
        for row in cursor.fetchall()
    }


# ── SQL ────────────────────────────────────────────────────────────────────────

INSERT_SQL = """
    INSERT INTO escola_inep
        (restricao_atendimento, escola, codigo_inep, uf, municipio, localizacao,
         localidade_diferenciada, categoria_administrativa, endereco, telefone,
         dependencia_administrativa, categoria_escola_privada, conveniada,
         regulamentacao, porte, etapas_ensino, outras_ofertas,
         latitude, longitude,
         row_hash, identity_hash, raw_data, imported_at)
    VALUES
        (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
"""

UPDATE_SQL = """
    UPDATE escola_inep SET
        restricao_atendimento   = %s,
        escola                  = %s,
        uf                      = %s,
        municipio               = %s,
        localizacao             = %s,
        localidade_diferenciada = %s,
        categoria_administrativa= %s,
        endereco                = %s,
        telefone                = %s,
        dependencia_administrativa  = %s,
        categoria_escola_privada    = %s,
        conveniada              = %s,
        regulamentacao          = %s,
        porte                   = %s,
        etapas_ensino           = %s,
        outras_ofertas          = %s,
        latitude   = COALESCE(latitude,  %s),
        longitude  = COALESCE(longitude, %s),
        row_hash   = %s,
        raw_data   = %s,
        updated_at = %s
    WHERE id = %s
"""


def do_insert(cursor, d: dict, raw: str, now: datetime) -> None:
    cursor.execute(INSERT_SQL, (
        d.get("restricao_atendimento"),
        d.get("escola"),
        d.get("codigo_inep"),
        d.get("uf"),
        d.get("municipio"),
        d.get("localizacao"),
        d.get("localidade_diferenciada"),
        d.get("categoria_administrativa"),
        d.get("endereco"),
        d.get("telefone"),
        d.get("dependencia_administrativa"),
        d.get("categoria_escola_privada"),
        d.get("conveniada"),
        d.get("regulamentacao"),
        d.get("porte"),
        d.get("etapas_ensino"),
        d.get("outras_ofertas"),
        d.get("latitude"),
        d.get("longitude"),
        d["row_hash"],
        d["identity_hash"],
        raw,
        now,
    ))


def do_update(cursor, d: dict, existing_id: int, raw: str, now: datetime) -> None:
    cursor.execute(UPDATE_SQL, (
        d.get("restricao_atendimento"),
        d.get("escola"),
        d.get("uf"),
        d.get("municipio"),
        d.get("localizacao"),
        d.get("localidade_diferenciada"),
        d.get("categoria_administrativa"),
        d.get("endereco"),
        d.get("telefone"),
        d.get("dependencia_administrativa"),
        d.get("categoria_escola_privada"),
        d.get("conveniada"),
        d.get("regulamentacao"),
        d.get("porte"),
        d.get("etapas_ensino"),
        d.get("outras_ofertas"),
        d.get("latitude"),
        d.get("longitude"),
        d["row_hash"],
        raw,
        now,
        existing_id,
    ))


# ──────────────────────────────────────────────────────────────────────────────
# Importação
# ──────────────────────────────────────────────────────────────────────────────

def run(args: argparse.Namespace) -> None:
    csv_path = Path(args.csv)
    if not csv_path.exists():
        print(f"[ERRO] CSV não encontrado: {csv_path}", file=sys.stderr)
        sys.exit(1)

    db_cfg = {
        "host":     args.host,
        "port":     args.port,
        "user":     args.user,
        "password": args.password,
        "database": args.database,
    }

    print(f"[INFO] Lendo CSV: {csv_path}")
    df = pd.read_csv(
        csv_path,
        sep=args.sep,
        encoding=args.encoding,
        dtype=str,
        keep_default_na=False,
        na_values=["", "NA", "N/A", "NULL", "null", "nan"],
        low_memory=False,
    )
    print(f"[INFO] {len(df):,} linhas lidas  |  colunas: {list(df.columns)[:5]}...")

    print("[INFO] Conectando ao banco...")
    conn = connect(db_cfg)
    cursor = conn.cursor()

    print("[INFO] Carregando registros existentes...")
    existing = load_existing(cursor)
    print(f"[INFO] {len(existing):,} escolas já no banco")

    now = datetime.now(tz=timezone.utc)
    stats = {"insert": 0, "update": 0, "skip": 0, "sem_codigo": 0, "erro": 0}
    pending = 0

    for _, csv_row in tqdm(df.iterrows(), total=len(df), desc="Processando", unit="escola"):
        d = csv_row_to_db(csv_row)

        if not d.get("codigo_inep"):
            stats["sem_codigo"] += 1
            continue

        identity = make_identity_hash(d["codigo_inep"])
        d["identity_hash"] = identity
        d["row_hash"]      = make_row_hash(d)
        raw_json           = json.dumps(csv_row.to_dict(), ensure_ascii=False, default=str)

        try:
            if identity in existing:
                ex = existing[identity]
                if ex["row_hash"] == d["row_hash"]:
                    stats["skip"] += 1
                    continue
                do_update(cursor, d, ex["id"], raw_json, now)
                stats["update"] += 1
            else:
                do_insert(cursor, d, raw_json, now)
                stats["insert"] += 1
                existing[identity] = {"id": None, "row_hash": d["row_hash"],
                                      "latitude": d.get("latitude"), "longitude": d.get("longitude"),
                                      "link_waze": None, "link_area_escolar": None,
                                      "permanent_hazard_id": None}
        except mysql.connector.Error as exc:
            stats["erro"] += 1
            tqdm.write(f"[WARN] Erro na escola {d.get('codigo_inep')}: {exc}")
            conn.rollback()
            pending = 0
            continue

        pending += 1
        if pending >= args.batch:
            conn.commit()
            pending = 0

    conn.commit()
    cursor.close()
    conn.close()

    total = sum(stats.values())
    print()
    print("=" * 52)
    print(f"  Total processado   : {total:>8,}")
    print(f"  ✅ Inseridos        : {stats['insert']:>8,}")
    print(f"  🔄 Atualizados      : {stats['update']:>8,}")
    print(f"  ⏭  Sem alteração    : {stats['skip']:>8,}")
    print(f"  ⚠️  Sem código INEP  : {stats['sem_codigo']:>8,}")
    print(f"  ❌ Erros banco      : {stats['erro']:>8,}")
    print("=" * 52)


# ──────────────────────────────────────────────────────────────────────────────
# CLI
# ──────────────────────────────────────────────────────────────────────────────

def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        description="Importa CSV INEP → tabela escola_inep com upsert inteligente",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    p.add_argument("--csv",      required=True,                       help="Caminho do arquivo CSV")
    p.add_argument("--host",     default="localhost",                  help="Host MySQL")
    p.add_argument("--port",     default=3306,       type=int,         help="Porta MySQL")
    p.add_argument("--user",     default="root",                       help="Usuário MySQL")
    p.add_argument("--password", default="",                           help="Senha MySQL")
    p.add_argument("--database", default="u629736858_wazetoolbox",     help="Nome do banco")
    p.add_argument("--batch",    default=500,        type=int,         help="Registros por commit")
    p.add_argument("--sep",      default=";",                          help="Separador do CSV")
    p.add_argument("--encoding", default="utf-8",                      help="Encoding do CSV")
    return p


if __name__ == "__main__":
    run(build_parser().parse_args())
