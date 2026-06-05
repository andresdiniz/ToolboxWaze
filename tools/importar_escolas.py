#!/usr/bin/env python3
"""
importar_escolas.py — ToolboxWaze
Importa CSV do Censo Escolar INEP para a tabela escola_inep.

Detecta automaticamente o formato do CSV:
  - CSV Analysé Detalhada MEC   : colunas 'Código INEP', 'Escola', 'Município', 'UF'
  - Microdados MEC               : colunas 'CO_ENTIDADE', 'NO_ENTIDADE', etc.
  - Separador ',' ou ';' detectado automaticamente
  - Encoding utf-8-sig ou latin-1 detectado automaticamente

Regras de importação:
  - codigo_inep novo       → INSERT com todos os campos
  - codigo_inep existente  → UPDATE apenas campos MEC
                             (nunca toca em latitude, longitude,
                              link_waze, permanent_hazard_id, link_area_escolar)
  - row_hash igual         → SEM MUDANÇA (pula)

Geocodiðficação Nominatim (opcional, após import):
  Camada 1: endereço completo + município + UF + Brasil
  Camada 2: nome da escola + município + UF + Brasil
  Camada 3: município + UF + Brasil (fallback mínimo)
  - Delay 1.1 s entre requests (rate-limit Nominatim)
  - Nunca sobrescreve coords já existentes
"""

import csv
import hashlib
import json
import os
import time
import tkinter as tk
from datetime import datetime
from tkinter import filedialog, messagebox, ttk
from threading import Thread
from urllib.parse import urlencode
from urllib.request import urlopen, Request

try:
    import pymysql
except ImportError:
    import subprocess, sys
    subprocess.check_call([sys.executable, "-m", "pip", "install", "pymysql"])
    import pymysql

# ── Configuração do banco ───────────────────────────────────────────────────────────────
DB_CONFIG = dict(
    host="localhost",
    port=3306,
    user="u629736858_wazetoolbox",
    password="SUA_SENHA_AQUI",
    database="u629736858_wazetoolbox",
    charset="utf8mb4",
    autocommit=False,
)

# ── Mapeamento de colunas (MEC Detalhado + Microdados) ────────────────────────────────
# Cada chave = coluna no MySQL; valor = lista de nomes possíveis no CSV
FIELD_MAP = {
    "codigo_inep":                ["Código INEP", "Codigo INEP", "CO_ENTIDADE", "CO_ESCOLA", "CO_INEP"],
    "restricao_atendimento":      ["Restrição de Atendimento", "Restricao de Atendimento"],
    "escola":                     ["Escola", "NO_ENTIDADE", "Nome da Escola"],
    "uf":                         ["UF", "SG_UF"],
    "municipio":                  ["Município", "Municipio", "NO_MUNICIPIO"],
    "localizacao":                ["Localização", "Localizacao", "TP_LOCALIZACAO"],
    "localidade_diferenciada":    ["Localidade Diferenciada", "TP_LOCALIZACAO_DIFERENCIADA"],
    "categoria_administrativa":   ["Categoria Administrativa", "TP_CATEGORIA_ESCOLA_PRIVADA"],
    "endereco":                   ["Endereço", "Endereco", "DS_ENDERECO"],
    "telefone":                   ["Telefone", "NU_DDD"],
    "dependencia_administrativa": ["Dependência Administrativa", "Dependencia Administrativa", "TP_DEPENDENCIA"],
    "categoria_escola_privada":   ["Categoria Escola Privada", "TP_CATEGORIA_ESCOLA_PRIVADA"],
    "conveniada":                 ["Conveniada Poder Público", "Conveniada Poder Publico", "IN_CONVENIADA_PP"],
    "regulamentacao":             ["Regulamentação", "Regulamentacao", "TP_REGULAMENTACAO"],
    "porte":                      ["Porte", "TP_PORTE_ESCOLA"],
    "etapas_ensino":              ["Etapas e Modalidade de Ensino Oferecidas"],
    "outras_ofertas":             ["Outras Ofertas Educacionais"],
    # Coordenadas do CSV (quando já existirem)
    "_lat_csv":                   ["Latitude", "NU_LATITUDE"],
    "_lon_csv":                   ["Longitude", "NU_LONGITUDE"],
}

NOMINATIM_UA  = "ToolboxWaze/1.0 (wazetoolbox.acheireviews.com.br)"
NOMINATIM_URL = "https://nominatim.openstreetmap.org/search"
GEO_DELAY     = 1.1


# ── Helpers de CSV ──────────────────────────────────────────────────────────────────

def detect_csv(path: str) -> tuple[str, str]:
    """Detecta encoding (utf-8-sig | latin-1) e separador (, | ;)."""
    for enc in ("utf-8-sig", "latin-1"):
        try:
            with open(path, encoding=enc, errors="strict") as f:
                first = f.readline()
            sep = "," if first.count(",") >= first.count(";") else ";"
            return enc, sep
        except UnicodeDecodeError:
            continue
    return "latin-1", ";"


def resolve_header(csv_header: list) -> dict:
    """Mapeia colunas do CSV para chaves do FIELD_MAP."""
    mapping = {}
    for db_col, candidates in FIELD_MAP.items():
        for candidate in candidates:
            if candidate in csv_header:
                mapping[db_col] = csv_header.index(candidate)
                break
    return mapping


def row_to_dict(row: list, header_map: dict) -> dict:
    data = {}
    for col, idx in header_map.items():
        val = row[idx].strip() if idx < len(row) else ""
        data[col] = val if val and val.lower() not in ("nan", "none") else None
    return data


def make_row_hash(data: dict) -> str:
    db_cols = [k for k in FIELD_MAP if not k.startswith("_")]
    serialized = json.dumps(
        {k: data.get(k) for k in sorted(db_cols)},
        ensure_ascii=False, sort_keys=True
    )
    return hashlib.sha256(serialized.encode()).hexdigest()


def make_identity_hash(codigo_inep: str) -> str:
    return hashlib.sha256(codigo_inep.strip().encode()).hexdigest()


# ── Geocodificação Nominatim ─────────────────────────────────────────────────────────────

def _nominatim(q: str) -> tuple[float, float] | None:
    params = urlencode({"q": q, "format": "json", "limit": "1", "countrycodes": "br"})
    req = Request(f"{NOMINATIM_URL}?{params}", headers={"User-Agent": NOMINATIM_UA})
    try:
        with urlopen(req, timeout=10) as resp:
            results = json.loads(resp.read().decode())
        if results:
            return float(results[0]["lat"]), float(results[0]["lon"])
    except Exception:
        pass
    return None


def geocode_escola(
    escola: str | None, endereco: str | None,
    municipio: str | None, uf: str | None,
    max_layer: int = 3
) -> tuple[float | None, float | None, str]:
    """
    Geocodifica em até 3 camadas. Retorna (lat, lon, camada).
    camada: '1-endereco' | '2-escola' | '3-municipio' | 'falhou'
    """
    base = ", ".join(filter(None, [municipio, uf, "Brasil"]))

    if max_layer >= 1 and endereco:
        res = _nominatim(f"{endereco}, {base}")
        time.sleep(GEO_DELAY)
        if res:
            return res[0], res[1], "1-endereco"

    if max_layer >= 2 and escola:
        res = _nominatim(f"{escola}, {base}")
        time.sleep(GEO_DELAY)
        if res:
            return res[0], res[1], "2-escola"

    if max_layer >= 3 and municipio:
        res = _nominatim(base)
        time.sleep(GEO_DELAY)
        if res:
            return res[0], res[1], "3-municipio"

    return None, None, "falhou"


# ── Paleta de cores ──────────────────────────────────────────────────────────────────
BG      = "#1e1e2e"
SURFACE = "#2a2a3e"
TEXT    = "#cdd6f4"
MUTED   = "#6c7086"
GREEN   = "#a6e3a1"
YELLOW  = "#f9e2af"
RED     = "#f38ba8"
TEAL    = "#89dceb"
ACCENT  = "#89b4fa"
PURPLE  = "#cba6f7"
FONT    = ("Consolas", 10)
FONT_SM = ("Consolas", 9)
FONT_B  = ("Consolas", 10, "bold")


# ── Aplicação ──────────────────────────────────────────────────────────────────────

class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("ToolboxWaze — Importar Escolas INEP")
        self.geometry("1200x760")
        self.configure(bg=BG)
        self.resizable(True, True)
        self._running = False
        self._stats   = dict(
            inseridos=0, atualizados=0, sem_mudanca=0, erros=0,
            geo_ok=0, geo_falhou=0
        )
        self._build_ui()

    # ── UI ────────────────────────────────────────────────────────────────────
    def _build_ui(self):
        # ─ Título ─────────────────────────────────────────────────────────────
        tk.Label(self,
            text="📍 ToolboxWaze — Importar Escolas INEP",
            bg=BG, fg=ACCENT, font=("Consolas", 13, "bold")
        ).pack(pady=(12, 2))
        tk.Label(self,
            text="Detecta automaticamente formato MEC Detalhado e Microdados | INSERT/UPDATE com row_hash | Geocodificação Nominatim em 3 camadas",
            bg=BG, fg=MUTED, font=FONT_SM
        ).pack(pady=(0, 8))

        # ─ Linha do CSV ────────────────────────────────────────────────────────
        top = tk.Frame(self, bg=BG, pady=6, padx=12)
        top.pack(fill="x")
        tk.Label(top, text="📁 CSV:", bg=BG, fg=TEXT, font=FONT).pack(side="left")
        self._csv_var = tk.StringVar()
        tk.Entry(top, textvariable=self._csv_var, bg=SURFACE, fg=TEXT,
                 insertbackground=TEXT, width=58, relief="flat",
                 font=FONT).pack(side="left", padx=6)
        tk.Button(top, text="Escolher", bg=ACCENT, fg=BG, font=FONT_B,
                  relief="flat", padx=8,
                  command=self._choose_file).pack(side="left", padx=4)
        self._btn_start = tk.Button(top, text="▶ Iniciar", bg=GREEN, fg=BG,
                                    font=FONT_B, relief="flat", padx=10,
                                    command=self._start)
        self._btn_start.pack(side="left", padx=6)
        self._btn_stop = tk.Button(top, text="⏹ Parar", bg=RED, fg=BG,
                                   font=FONT_B, relief="flat", padx=10,
                                   state="disabled", command=self._stop)
        self._btn_stop.pack(side="left", padx=2)
        tk.Button(top, text="🗑 Limpar", bg=SURFACE, fg=TEXT, font=FONT,
                  relief="flat", padx=8,
                  command=self._clear).pack(side="left", padx=6)
        self._lbl_stats = tk.Label(top, text="", bg=BG, fg=MUTED, font=FONT_SM)
        self._lbl_stats.pack(side="right", padx=12)

        # ─ Opções ────────────────────────────────────────────────────────────
        opt = tk.Frame(self, bg=BG, padx=12, pady=2)
        opt.pack(fill="x")
        self._geo_var = tk.BooleanVar(value=True)
        tk.Checkbutton(opt,
            text="📍 Geocodificar escolas sem coordenadas (Nominatim)",
            variable=self._geo_var, bg=BG, fg=TEAL,
            selectcolor=SURFACE, activebackground=BG,
            activeforeground=TEAL, font=FONT_SM,
        ).pack(side="left")
        self._skip_par_var = tk.BooleanVar(value=True)
        tk.Checkbutton(opt,
            text="Pular paralisadas/extintas na geocodificação",
            variable=self._skip_par_var, bg=BG, fg=MUTED,
            selectcolor=SURFACE, activebackground=BG,
            activeforeground=MUTED, font=FONT_SM,
        ).pack(side="left", padx=16)
        tk.Label(opt, text="Camada máx:", bg=BG, fg=MUTED,
                 font=FONT_SM).pack(side="left", padx=(12, 2))
        self._max_layer_var = tk.StringVar(value="3")
        om = tk.OptionMenu(opt, self._max_layer_var, "1", "2", "3")
        om.config(bg=SURFACE, fg=TEXT, activebackground=ACCENT,
                  font=FONT_SM, relief="flat", bd=0)
        om.pack(side="left")

        # ─ Progresso ─────────────────────────────────────────────────────────
        pf = tk.Frame(self, bg=BG, padx=12)
        pf.pack(fill="x")
        self._prog_lbl = tk.Label(pf, text="Aguardando...",
                                  bg=BG, fg=MUTED, font=FONT_SM)
        self._prog_lbl.pack(side="left")
        self._prog_var = tk.DoubleVar()
        style = ttk.Style(self)
        style.theme_use("clam")
        style.configure("Tool.Horizontal.TProgressbar",
                        troughcolor="#313145", background=ACCENT,
                        bordercolor="#313145", lightcolor=ACCENT, darkcolor=ACCENT)
        self._prog = ttk.Progressbar(pf, variable=self._prog_var,
                                     style="Tool.Horizontal.TProgressbar",
                                     maximum=100, length=420)
        self._prog.pack(side="left", padx=8, pady=4)
        self._phase_lbl = tk.Label(pf, text="", bg=BG, fg=PURPLE,
                                   font=("Consolas", 9, "bold"))
        self._phase_lbl.pack(side="left", padx=4)

        # ─ Painel divisível: log + tabela ──────────────────────────────────────
        paned = tk.PanedWindow(self, orient="vertical", bg=BG, sashwidth=6)
        paned.pack(fill="both", expand=True, padx=12, pady=(0, 8))

        # Log
        lf = tk.Frame(paned, bg=BG)
        paned.add(lf, minsize=200)
        tk.Label(lf, text=" Log de Execução",
                 bg="#313145", fg=ACCENT, font=FONT_SM,
                 anchor="w").pack(fill="x")
        self._log_widget = tk.Text(
            lf, bg=SURFACE, fg=TEXT, font=("Consolas", 9),
            state="disabled", relief="flat", wrap="word"
        )
        sb_log = tk.Scrollbar(lf, command=self._log_widget.yview, bg="#313145")
        self._log_widget.configure(yscrollcommand=sb_log.set)
        sb_log.pack(side="right", fill="y")
        self._log_widget.pack(fill="both", expand=True)
        for tag, color in [
            ("OK", GREEN), ("WARN", YELLOW), ("ERR", RED),
            ("INFO", TEAL), ("MUTED", MUTED), ("GEO", PURPLE), ("ID", "#c9a0dc")
        ]:
            self._log_widget.tag_config(tag, foreground=color)

        # Tabela
        tf = tk.Frame(paned, bg=BG)
        paned.add(tf, minsize=150)
        tk.Label(tf, text=" Resumo por escola",
                 bg="#313145", fg=ACCENT, font=FONT_SM,
                 anchor="w").pack(fill="x")
        cols = ("codigo_inep", "escola", "uf", "municipio", "import", "geo")
        self._tree = ttk.Treeview(tf, columns=cols, show="headings", height=8)
        style.configure("Treeview", background=SURFACE, fieldbackground=SURFACE,
                        foreground=TEXT, rowheight=22, font=FONT_SM)
        style.configure("Treeview.Heading", background=BG, foreground=MUTED,
                        font=("Consolas", 9, "bold"))
        style.map("Treeview",
                  background=[("selected", ACCENT)],
                  foreground=[("selected", BG)])
        widths = {"codigo_inep": 110, "escola": 340, "uf": 45,
                  "municipio": 160, "import": 115, "geo": 130}
        for c in cols:
            self._tree.heading(c, text=c.replace("_", " ").title())
            self._tree.column(c, width=widths[c], anchor="w")
        for tag, color in [
            ("INSERIDO", GREEN), ("ATUALIZADO", YELLOW),
            ("SEM_MUDANCA", MUTED), ("ERRO", RED)
        ]:
            self._tree.tag_configure(tag, foreground=color)
        sb_tv = tk.Scrollbar(tf, command=self._tree.yview)
        sb_tx = tk.Scrollbar(tf, orient="horizontal", command=self._tree.xview)
        self._tree.configure(yscrollcommand=sb_tv.set, xscrollcommand=sb_tx.set)
        sb_tv.pack(side="right", fill="y")
        sb_tx.pack(side="bottom", fill="x")
        self._tree.pack(fill="both", expand=True)

    # ── Ações UI ─────────────────────────────────────────────────────────────────
    def _choose_file(self):
        path = filedialog.askopenfilename(
            title="Escolha o CSV do INEP",
            filetypes=[("CSV", "*.csv"), ("Todos", "*.*")]
        )
        if path:
            self._csv_var.set(path)
            self._log_line(f"► Arquivo selecionado: {os.path.basename(path)}", "INFO")

    def _clear(self):
        self._log_widget.configure(state="normal")
        self._log_widget.delete("1.0", "end")
        self._log_widget.configure(state="disabled")
        for row in self._tree.get_children():
            self._tree.delete(row)
        self._stats = dict(inseridos=0, atualizados=0, sem_mudanca=0, erros=0,
                           geo_ok=0, geo_falhou=0)
        self._prog_var.set(0)
        self._prog_lbl.config(text="Aguardando...")
        self._phase_lbl.config(text="")
        self._lbl_stats.config(text="")

    def _start(self):
        csv_path = self._csv_var.get().strip()
        if not csv_path or not os.path.isfile(csv_path):
            messagebox.showerror("Erro", "Selecione um arquivo CSV válido.")
            return
        self._running = True
        self._btn_start.config(state="disabled")
        self._btn_stop.config(state="normal")
        Thread(target=self._run, args=(csv_path,), daemon=True).start()

    def _stop(self):
        self._running = False
        self._log_line("⏹ Interrompido pelo usuário.", "WARN")

    # ── Worker principal ──────────────────────────────────────────────────────────
    def _run(self, csv_path: str):
        # ─ Conexão ao banco ──────────────────────────────────────────
        try:
            conn = pymysql.connect(**DB_CONFIG)
        except Exception as e:
            self._log_line(f"❌ Falha na conexão: {e}", "ERR")
            self._finish(); return
        self._log_line("✅ Conectado ao banco.", "OK")

        # ─ Leitura e detecção do CSV ────────────────────────────────
        enc, sep = detect_csv(csv_path)
        try:
            with open(csv_path, newline="", encoding=enc) as f:
                reader    = csv.reader(f, delimiter=sep)
                header    = next(reader)
                all_rows  = list(reader)
        except Exception as e:
            self._log_line(f"❌ Erro ao ler CSV: {e}", "ERR")
            conn.close(); self._finish(); return

        header_map = resolve_header(header)
        col_id = next((c for c in FIELD_MAP["codigo_inep"] if c in header), None)
        if "codigo_inep" not in header_map:
            self._log_line("❌ Coluna de ID INEP não encontrada no CSV.", "ERR")
            self._log_line(f"   Colunas detectadas: {header[:8]}", "MUTED")
            conn.close(); self._finish(); return

        total = len(all_rows)
        self._log_line(
            f"📋 {total} linhas | encoding={enc} | sep='{sep}' | "
            f"coluna ID: '{col_id}'", "INFO"
        )
        self.after(0, lambda: self._phase_lbl.config(text="📥 IMPORTANDO"))

        # ─ Carrega existentes do banco ───────────────────────────────
        with conn.cursor() as cur:
            cur.execute("SELECT codigo_inep, row_hash FROM escola_inep")
            existing = {r[0]: r[1] for r in cur.fetchall()}
        self._log_line(f"🗏️  {len(existing)} escolas já no banco.", "INFO")

        now_str  = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        iid_map: dict[str, str] = {}

        # ──────────────── FASE 1: IMPORT ───────────────────
        for i, row in enumerate(all_rows):
            if not self._running:
                break

            pct = (i + 1) / total * 100
            s   = self._stats
            self.after(0, lambda p=pct, n=i+1, _s=dict(s): (
                self._prog_var.set(p),
                self._prog_lbl.config(
                    text=(
                        f"{n}/{total}  {p:.1f}%  "
                        f"✅{_s['inseridos']} │ 🔄{_s['atualizados']} "
                        f"│ ─{_s['sem_mudanca']} │ ❌{_s['erros']}"
                    )
                )
            ))

            data        = row_to_dict(row, header_map)
            codigo_inep = (data.get("codigo_inep") or "").strip()
            if not codigo_inep:
                continue

            row_hash      = make_row_hash(data)
            identity_hash = make_identity_hash(codigo_inep)
            nome          = (data.get("escola") or "")[:55]

            try:
                if codigo_inep not in existing:
                    # ─── INSERT ───────────────────────────────────────
                    # Aproveita lat/lon do próprio CSV se disponíveis
                    lat_csv = data.get("_lat_csv")
                    lon_csv = data.get("_lon_csv")
                    with conn.cursor() as cur:
                        cur.execute(
                            """INSERT INTO escola_inep (
                                restricao_atendimento, escola, codigo_inep,
                                uf, municipio, localizacao,
                                localidade_diferenciada, categoria_administrativa,
                                endereco, telefone, dependencia_administrativa,
                                categoria_escola_privada, conveniada,
                                regulamentacao, porte, etapas_ensino,
                                outras_ofertas, latitude, longitude,
                                row_hash, identity_hash, raw_data, imported_at
                            ) VALUES (
                                %s,%s,%s,%s,%s,%s,%s,%s,%s,%s,
                                %s,%s,%s,%s,%s,%s,%s,%s,%s,
                                %s,%s,%s,%s
                            )""",
                            (
                                data.get("restricao_atendimento"),
                                data.get("escola"), codigo_inep,
                                data.get("uf"), data.get("municipio"),
                                data.get("localizacao"),
                                data.get("localidade_diferenciada"),
                                data.get("categoria_administrativa"),
                                data.get("endereco"), data.get("telefone"),
                                data.get("dependencia_administrativa"),
                                data.get("categoria_escola_privada"),
                                data.get("conveniada"), data.get("regulamentacao"),
                                data.get("porte"), data.get("etapas_ensino"),
                                data.get("outras_ofertas"),
                                lat_csv, lon_csv,
                                row_hash, identity_hash,
                                json.dumps(data, ensure_ascii=False), now_str,
                            )
                        )
                    conn.commit()
                    self._stats["inseridos"] += 1
                    acao = "INSERIDO"
                    self._log_id(i + 1, total, codigo_inep, nome, "✅ INSERIDO", "OK")

                elif existing[codigo_inep] == row_hash:
                    self._stats["sem_mudanca"] += 1
                    acao = "SEM_MUDANCA"

                else:
                    # ─── UPDATE (nunca toca em coords/waze) ───────────
                    with conn.cursor() as cur:
                        cur.execute(
                            """UPDATE escola_inep SET
                                restricao_atendimento    = %s,
                                escola = %s, uf = %s, municipio = %s,
                                localizacao = %s,
                                localidade_diferenciada  = %s,
                                categoria_administrativa = %s,
                                endereco = %s, telefone = %s,
                                dependencia_administrativa = %s,
                                categoria_escola_privada   = %s,
                                conveniada = %s, regulamentacao = %s,
                                porte = %s, etapas_ensino = %s,
                                outras_ofertas = %s,
                                row_hash = %s, raw_data = %s,
                                updated_at = %s
                            WHERE codigo_inep = %s""",
                            (
                                data.get("restricao_atendimento"),
                                data.get("escola"), data.get("uf"),
                                data.get("municipio"), data.get("localizacao"),
                                data.get("localidade_diferenciada"),
                                data.get("categoria_administrativa"),
                                data.get("endereco"), data.get("telefone"),
                                data.get("dependencia_administrativa"),
                                data.get("categoria_escola_privada"),
                                data.get("conveniada"), data.get("regulamentacao"),
                                data.get("porte"), data.get("etapas_ensino"),
                                data.get("outras_ofertas"),
                                row_hash,
                                json.dumps(data, ensure_ascii=False),
                                now_str, codigo_inep,
                            )
                        )
                    conn.commit()
                    self._stats["atualizados"] += 1
                    acao = "ATUALIZADO"
                    self._log_id(i + 1, total, codigo_inep, nome, "🔄 ATUALIZADO", "WARN")

            except Exception as e:
                self._stats["erros"] += 1
                acao = "ERRO"
                self._log_id(i + 1, total, codigo_inep, nome, f"❌ ERRO: {e}", "ERR")
                conn.rollback()

            iid = self._tree_insert(
                codigo_inep, nome,
                data.get("uf", ""), data.get("municipio", ""),
                acao, ""
            )
            iid_map[codigo_inep] = iid

            if (i + 1) % 50 == 0:
                s = self._stats
                self._log_line(
                    f"── {i+1}/{total} | ✅{s['inseridos']} ins | "
                    f"🔄{s['atualizados']} upd | ─{s['sem_mudanca']} ║ ❌{s['erros']}",
                    "MUTED"
                )

        # ──────────── FASE 2: GEOCODIFICAÇÃO ───────────────
        if self._running and self._geo_var.get():
            self.after(0, lambda: self._phase_lbl.config(text="📍 GEOCODIFICANDO"))
            self._log_line("\n📍 Iniciando geocodificação (Nominatim)...", "GEO")

            codigos_no_csv  = list(iid_map.keys())
            sem_coords: list[dict] = []
            for offset in range(0, len(codigos_no_csv), 500):
                lote = codigos_no_csv[offset:offset + 500]
                phs  = ",".join(["%s"] * len(lote))
                with conn.cursor(pymysql.cursors.DictCursor) as cur:
                    cur.execute(
                        f"""SELECT codigo_inep, escola, endereco, municipio,
                                   uf, restricao_atendimento
                            FROM escola_inep
                            WHERE codigo_inep IN ({phs})
                              AND (latitude IS NULL OR latitude = '')""",
                        lote
                    )
                    sem_coords.extend(cur.fetchall())

            total_geo = len(sem_coords)
            self._log_line(f"📍 {total_geo} escolas sem coordenadas.", "GEO")
            max_layer = int(self._max_layer_var.get())
            skip_par  = self._skip_par_var.get()
            self._prog_var.set(0)

            for gi, er in enumerate(sem_coords):
                if not self._running:
                    break

                pct = (gi + 1) / max(total_geo, 1) * 100
                s   = self._stats
                self.after(0, lambda p=pct, n=gi+1, t=total_geo, _s=dict(s): (
                    self._prog_var.set(p),
                    self._prog_lbl.config(
                        text=f"{n}/{t}  {p:.1f}%  📍✅{_s['geo_ok']}  📍❌{_s['geo_falhou']}"
                    )
                ))

                codigo = er["codigo_inep"]
                nome   = (er["escola"] or "")[:50]
                end    = er["endereco"]
                mun    = er["municipio"]
                uf_    = er["uf"]
                rest   = (er["restricao_atendimento"] or "").upper()

                if skip_par and ("PARALISAD" in rest or "EXTINT" in rest):
                    self._log_line(f"  ⏩ SKIP paralisada {codigo} {nome}", "MUTED")
                    self._update_tree_geo(iid_map.get(codigo, ""), "skip")
                    continue

                lat, lon, camada = geocode_escola(
                    nome, end, mun, uf_, max_layer
                )

                if lat is not None:
                    try:
                        with conn.cursor() as cur:
                            cur.execute(
                                """UPDATE escola_inep
                                   SET latitude = %s, longitude = %s,
                                       updated_at = %s
                                   WHERE codigo_inep = %s
                                     AND (latitude IS NULL OR latitude = '')""",
                                (str(lat), str(lon), now_str, codigo)
                            )
                        conn.commit()
                        self._stats["geo_ok"] += 1
                        self._log_id(gi + 1, total_geo, codigo, nome,
                                     f"📍 [{camada}] → {lat:.6f}, {lon:.6f}", "GEO")
                        self._update_tree_geo(iid_map.get(codigo, ""), camada)
                    except Exception as e:
                        self._stats["geo_falhou"] += 1
                        self._log_line(f"  ❌ GEO DB ERR {codigo}: {e}", "ERR")
                        conn.rollback()
                        self._update_tree_geo(iid_map.get(codigo, ""), "db-erro")
                else:
                    self._stats["geo_falhou"] += 1
                    self._log_id(gi + 1, total_geo, codigo, nome,
                                 "⚠️ sem coords — revisar manualmente", "WARN")
                    self._update_tree_geo(iid_map.get(codigo, ""), "falhou")

                if (gi + 1) % 20 == 0:
                    s = self._stats
                    self._log_line(
                        f"── geo {gi+1}/{total_geo} | 📍✅{s['geo_ok']}  📍❌{s['geo_falhou']}",
                        "MUTED"
                    )

        conn.close()

        # ─ Resumo final ─────────────────────────────────────────────────────────
        s = self._stats
        self._log_line(
            f"\n✔ Concluído!\n"
            f"  Import : ✅{s['inseridos']} ins │ 🔄{s['atualizados']} upd "
            f"│ ─{s['sem_mudanca']} sem mud │ ❌{s['erros']} err\n"
            f"  Geo    : 📍✅{s['geo_ok']} encontradas │ 📍❌{s['geo_falhou']} não encontradas",
            "OK"
        )
        self.after(0, lambda: (
            self._phase_lbl.config(text="✔ Pronto"),
            self._lbl_stats.config(
                text=(
                    f"✅{s['inseridos']} 🔄{s['atualizados']} "
                    f"─{s['sem_mudanca']} ❌{s['erros']} "
                    f"📍✅{s['geo_ok']} 📍❌{s['geo_falhou']}"
                )
            )
        ))
        self._finish()

    # ── Helpers de UI ──────────────────────────────────────────────────────────────
    def _tree_insert(self, codigo, escola, uf, mun, acao, geo) -> str:
        return self._tree.insert(
            "", "end",
            values=(codigo, escola, uf, mun, acao.replace("_", " "), geo),
            tags=(acao,)
        )

    def _update_tree_geo(self, iid: str, geo_status: str):
        if not iid:
            return
        color_map = {
            "1-endereco":   GREEN,
            "2-escola":     YELLOW,
            "3-municipio":  ACCENT,
            "falhou":       RED,
            "skip":         MUTED,
            "db-erro":      RED,
        }
        color = color_map.get(geo_status, MUTED)
        self.after(0, lambda i=iid, g=geo_status, c=color: (
            self._tree.set(i, "geo", g),
            self._tree.tag_configure(f"geo_{i}", foreground=c),
        ))

    def _log_id(self, idx: int, total: int, codigo: str, nome: str,
                msg: str, tag: str):
        """Log padronizado com ID INEP destacado em roxo."""
        ts   = datetime.now().strftime("%H:%M:%S")
        id_t = f"ID {codigo}"
        line = f"[{ts}]  [{idx:>6}/{total}] {id_t:<13} {nome[:48]:<48}  {msg}\n"
        self.after(0, lambda m=line, t=tag, k=id_t: self._append_log_id(m, t, k))

    def _append_log_id(self, msg: str, tag: str, id_tag: str):
        self._log_widget.configure(state="normal")
        pos = self._log_widget.index("end")
        self._log_widget.insert("end", msg, tag)
        full = self._log_widget.get(f"{pos} linestart", f"{pos} lineend")
        s = full.find(id_tag)
        if s != -1:
            base = self._log_widget.index(f"{pos} linestart")
            self._log_widget.tag_add("ID",
                f"{base} + {s} chars",
                f"{base} + {s + len(id_tag)} chars")
        self._log_widget.see("end")
        self._log_widget.configure(state="disabled")

    def _log_line(self, msg: str, tag: str = "INFO"):
        ts = datetime.now().strftime("%H:%M:%S")
        self.after(0, lambda m=f"[{ts}] {msg}\n", t=tag: self._append_log(m, t))

    def _append_log(self, msg: str, tag: str):
        self._log_widget.configure(state="normal")
        self._log_widget.insert("end", msg, tag)
        self._log_widget.see("end")
        self._log_widget.configure(state="disabled")

    def _finish(self):
        self._running = False
        self.after(0, lambda: (
            self._btn_start.config(state="normal"),
            self._btn_stop.config(state="disabled"),
        ))


if __name__ == "__main__":
    app = App()
    app.mainloop()
