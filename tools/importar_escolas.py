#!/usr/bin/env python3
"""
importar_escolas.py — ToolboxWaze
Importa CSV do Censo Escolar INEP para a tabela escola_inep.

Regras de importação:
  - codigo_inep novo         → INSERT com todos os campos do CSV
  - codigo_inep já existe   → UPDATE apenas dos campos do MEC
                                (nunca toca em latitude, longitude,
                                 link_waze, permanent_hazard_id,
                                 link_area_escolar)
  - row_hash igual           → SEM MUDANÇA (pula)

Geocodificação Nominatim (opcional, após import):
  Camada 1: endereço completo + município + UF + Brasil
  Camada 2: nome da escola + município + UF + Brasil
  Camada 3: município + UF + Brasil  (fallback mínimo)
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

# ── Configuração do banco ──────────────────────────────────────────────────────
DB_CONFIG = dict(
    host="localhost",
    port=3306,
    user="u629736858_wazetoolbox",
    password="SUA_SENHA_AQUI",
    database="u629736858_wazetoolbox",
    charset="utf8mb4",
    autocommit=False,
)

# ── Mapeamento CSV → coluna MySQL ─────────────────────────────────────────────
FIELD_MAP = {
    "restricao_atendimento":      ["Restrição de Atendimento", "Restricao de Atendimento"],
    "escola":                     ["Escola", "Nome da Escola"],
    "codigo_inep":                ["Código INEP", "Codigo INEP", "Código Escola"],
    "uf":                         ["UF"],
    "municipio":                  ["Município", "Municipio"],
    "localizacao":                ["Localização", "Localizacao"],
    "localidade_diferenciada":    ["Localidade Diferenciada"],
    "categoria_administrativa":   ["Categoria Administrativa"],
    "endereco":                   ["Endereço", "Endereco"],
    "telefone":                   ["Telefone"],
    "dependencia_administrativa": ["Dependência Administrativa", "Dependencia Administrativa"],
    "categoria_escola_privada":   ["Categoria Escola Privada"],
    "conveniada":                 ["Conveniada Poder Público", "Conveniada Poder Publico"],
    "regulamentacao":             ["Regulamentação", "Regulamentacao"],
    "porte":                      ["Porte"],
    "etapas_ensino":              ["Etapas e Modalidade de Ensino Oferecidas"],
    "outras_ofertas":             ["Outras Ofertas Educacionais"],
}

NOMINATIM_UA  = "ToolboxWaze/1.0 (wazetoolbox.acheireviews.com.br)"
NOMINATIM_URL = "https://nominatim.openstreetmap.org/search"
GEO_DELAY     = 1.1   # segundos entre requests (respeita ToS Nominatim)


# ── Helpers ───────────────────────────────────────────────────────────────────

def sha256(text: str) -> str:
    return hashlib.sha256(text.encode()).hexdigest()


def resolve_header(csv_header: list) -> dict:
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
        data[col] = val if val else None
    return data


def make_row_hash(data: dict) -> str:
    serialized = json.dumps(
        {k: data.get(k) for k in sorted(FIELD_MAP.keys())},
        ensure_ascii=False, sort_keys=True
    )
    return sha256(serialized)


def make_identity_hash(codigo_inep: str) -> str:
    return sha256(codigo_inep.strip())


def nominatim_query(q: str) -> tuple[float, float] | None:
    """
    Faz uma query ao Nominatim e retorna (lat, lon) ou None.
    Adiciona countrycodes=BR para melhorar precisão.
    """
    params = urlencode({"q": q, "format": "json", "limit": "1", "countrycodes": "br"})
    url    = f"{NOMINATIM_URL}?{params}"
    req    = Request(url, headers={"User-Agent": NOMINATIM_UA})
    try:
        with urlopen(req, timeout=10) as resp:
            results = json.loads(resp.read().decode())
        if results:
            return float(results[0]["lat"]), float(results[0]["lon"])
    except Exception:
        pass
    return None


def geocode_escola(escola: str | None, endereco: str | None,
                   municipio: str | None, uf: str | None
                   ) -> tuple[float | None, float | None, str]:
    """
    Geocodifica em 3 camadas. Retorna (lat, lon, camada_usada).
    camada_usada: '1-endereco' | '2-escola' | '3-municipio' | 'falhou'
    """
    base = f"{municipio or ''}, {uf or ''}, Brasil"

    # Camada 1 — endereço completo
    if endereco:
        result = nominatim_query(f"{endereco}, {base}")
        time.sleep(GEO_DELAY)
        if result:
            return result[0], result[1], "1-endereco"

    # Camada 2 — nome da escola + município + UF
    if escola:
        result = nominatim_query(f"{escola}, {base}")
        time.sleep(GEO_DELAY)
        if result:
            return result[0], result[1], "2-escola"

    # Camada 3 — somente município + UF (ponto central do município)
    if municipio:
        result = nominatim_query(base)
        time.sleep(GEO_DELAY)
        if result:
            return result[0], result[1], "3-municipio"

    return None, None, "falhou"


# ── Cores GUI ───────────────────────────────────────────────────────────────────

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


class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("ToolboxWaze — Importar Escolas INEP")
        self.geometry("1150x740")
        self.configure(bg=BG)
        self.resizable(True, True)

        self._running = False
        self._stats   = {
            "inseridos": 0, "atualizados": 0, "sem_mudanca": 0, "erros": 0,
            "geo_ok": 0,    "geo_falhou": 0,
        }
        self._build_ui()

    # ── UI ────────────────────────────────────────────────────────────────────

    def _build_ui(self):
        # ─ Linha 1: CSV + botões ───────────────────────────────────────
        top = tk.Frame(self, bg=BG, pady=8, padx=12)
        top.pack(fill="x")

        tk.Label(top, text="📁 CSV:", bg=BG, fg=TEXT,
                 font=("Consolas", 10)).pack(side="left")
        self._csv_var = tk.StringVar()
        tk.Entry(top, textvariable=self._csv_var, bg=SURFACE, fg=TEXT,
                 insertbackground=TEXT, width=55,
                 relief="flat", font=("Consolas", 10)).pack(side="left", padx=6)
        tk.Button(top, text="Escolher", bg=ACCENT, fg=BG,
                  font=("Consolas", 10, "bold"), relief="flat",
                  padx=8, command=self._choose_file).pack(side="left", padx=4)

        self._btn_start = tk.Button(top, text="▶ Iniciar", bg=GREEN, fg=BG,
                                    font=("Consolas", 10, "bold"), relief="flat",
                                    padx=10, command=self._start)
        self._btn_start.pack(side="left", padx=6)

        self._btn_stop = tk.Button(top, text="⏹ Parar", bg=RED, fg=BG,
                                   font=("Consolas", 10, "bold"), relief="flat",
                                   padx=10, state="disabled", command=self._stop)
        self._btn_stop.pack(side="left", padx=2)

        tk.Button(top, text="🗑 Limpar", bg=SURFACE, fg=TEXT,
                  font=("Consolas", 10), relief="flat",
                  padx=8, command=self._clear).pack(side="left", padx=6)

        self._lbl_stats = tk.Label(top, text="", bg=BG, fg=MUTED,
                                   font=("Consolas", 9))
        self._lbl_stats.pack(side="right", padx=12)

        # ─ Linha 2: opções ──────────────────────────────────────────
        opt = tk.Frame(self, bg=BG, padx=12, pady=2)
        opt.pack(fill="x")

        self._geo_var = tk.BooleanVar(value=True)
        tk.Checkbutton(
            opt, text="📍 Geocodificar escolas sem coordenadas (Nominatim)",
            variable=self._geo_var,
            bg=BG, fg=TEAL, selectcolor=SURFACE, activebackground=BG,
            activeforeground=TEAL, font=("Consolas", 9),
        ).pack(side="left")

        self._skip_paralisadas_var = tk.BooleanVar(value=True)
        tk.Checkbutton(
            opt,
            text="Pular paralisadas/extintas na geocodificação",
            variable=self._skip_paralisadas_var,
            bg=BG, fg=MUTED, selectcolor=SURFACE, activebackground=BG,
            activeforeground=MUTED, font=("Consolas", 9),
        ).pack(side="left", padx=16)

        tk.Label(opt, text="Camada máx:", bg=BG, fg=MUTED,
                 font=("Consolas", 9)).pack(side="left", padx=(12, 2))
        self._max_layer_var = tk.StringVar(value="3")
        tk.OptionMenu(opt, self._max_layer_var, "1", "2", "3").configure(
            bg=SURFACE, fg=TEXT, activebackground=ACCENT,
            font=("Consolas", 9), relief="flat", bd=0,
        )
        tk.OptionMenu(opt, self._max_layer_var, "1", "2", "3").pack(
            side="left")

        # ─ Progress ────────────────────────────────────────────────
        prog_frame = tk.Frame(self, bg=BG, padx=12)
        prog_frame.pack(fill="x")
        self._prog_var = tk.DoubleVar()
        self._prog_lbl = tk.Label(prog_frame, text="Aguardando...",
                                  bg=BG, fg=MUTED, font=("Consolas", 9))
        self._prog_lbl.pack(side="left")
        self._prog = ttk.Progressbar(prog_frame, variable=self._prog_var,
                                     maximum=100, length=380)
        self._prog.pack(side="left", padx=8, pady=4)
        self._phase_lbl = tk.Label(prog_frame, text="", bg=BG, fg=PURPLE,
                                   font=("Consolas", 9, "bold"))
        self._phase_lbl.pack(side="left", padx=4)

        # ─ Paned: log + tabela ─────────────────────────────────────
        paned = tk.PanedWindow(self, orient="vertical", bg=BG, sashwidth=6)
        paned.pack(fill="both", expand=True, padx=12, pady=(0, 8))

        log_frame = tk.Frame(paned, bg=BG)
        paned.add(log_frame, minsize=200)
        tk.Label(log_frame, text="Log", bg=BG, fg=MUTED,
                 font=("Consolas", 9)).pack(anchor="w")
        self._log = tk.Text(log_frame, bg=SURFACE, fg=TEXT,
                            font=("Consolas", 9), state="disabled",
                            relief="flat", wrap="word")
        sb_log = tk.Scrollbar(log_frame, command=self._log.yview)
        self._log.configure(yscrollcommand=sb_log.set)
        sb_log.pack(side="right", fill="y")
        self._log.pack(fill="both", expand=True)
        for tag, color in [("OK", GREEN), ("WARN", YELLOW), ("ERR", RED),
                           ("INFO", TEAL), ("MUTED", MUTED), ("GEO", PURPLE)]:
            self._log.tag_config(tag, foreground=color)

        tbl_frame = tk.Frame(paned, bg=BG)
        paned.add(tbl_frame, minsize=150)
        tk.Label(tbl_frame, text="Resumo por escola", bg=BG, fg=MUTED,
                 font=("Consolas", 9)).pack(anchor="w")

        cols = ("codigo_inep", "escola", "uf", "municipio", "import", "geo")
        self._tree = ttk.Treeview(tbl_frame, columns=cols,
                                  show="headings", height=8)
        style = ttk.Style()
        style.theme_use("clam")
        style.configure("Treeview", background=SURFACE, fieldbackground=SURFACE,
                        foreground=TEXT, rowheight=22, font=("Consolas", 9))
        style.configure("Treeview.Heading", background=BG, foreground=MUTED,
                        font=("Consolas", 9, "bold"))
        style.map("Treeview", background=[("selected", ACCENT)],
                  foreground=[("selected", BG)])

        widths = {"codigo_inep": 110, "escola": 320, "uf": 45,
                  "municipio": 150, "import": 110, "geo": 120}
        for c in cols:
            self._tree.heading(c, text=c.replace("_", " ").title())
            self._tree.column(c, width=widths[c], anchor="w")

        for tag, color in [
            ("INSERIDO", GREEN), ("ATUALIZADO", YELLOW),
            ("SEM_MUDANCA", MUTED), ("ERRO", RED),
        ]:
            self._tree.tag_configure(tag, foreground=color)

        sb_tree = tk.Scrollbar(tbl_frame, command=self._tree.yview)
        self._tree.configure(yscrollcommand=sb_tree.set)
        sb_tree.pack(side="right", fill="y")
        sb_x = tk.Scrollbar(tbl_frame, orient="horizontal",
                             command=self._tree.xview)
        self._tree.configure(xscrollcommand=sb_x.set)
        sb_x.pack(side="bottom", fill="x")
        self._tree.pack(fill="both", expand=True)

    # ── Ações UI ───────────────────────────────────────────────────────────

    def _choose_file(self):
        path = filedialog.askopenfilename(
            title="Escolha o CSV do INEP",
            filetypes=[("CSV", "*.csv"), ("Todos", "*.*")]
        )
        if path:
            self._csv_var.set(path)

    def _clear(self):
        self._log.configure(state="normal")
        self._log.delete("1.0", "end")
        self._log.configure(state="disabled")
        for row in self._tree.get_children():
            self._tree.delete(row)
        self._stats = {
            "inseridos": 0, "atualizados": 0,
            "sem_mudanca": 0, "erros": 0,
            "geo_ok": 0, "geo_falhou": 0,
        }
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

    # ── Worker principal ────────────────────────────────────────────────────

    def _run(self, csv_path: str):
        # ─ Conexão ──────────────────────────────────────────────────
        try:
            conn = pymysql.connect(**DB_CONFIG)
        except Exception as e:
            self._log_line(f"❌ Falha na conexão: {e}", "ERR")
            self._finish()
            return
        self._log_line("✅ Conectado ao banco.", "OK")

        # ─ Leitura do CSV ──────────────────────────────────────────
        try:
            with open(csv_path, newline="", encoding="utf-8-sig") as f:
                reader = csv.reader(f, delimiter=";")
                header    = next(reader)
                all_rows  = list(reader)
        except Exception as e:
            self._log_line(f"❌ Erro ao ler CSV: {e}", "ERR")
            conn.close(); self._finish(); return

        header_map = resolve_header(header)
        if "codigo_inep" not in header_map:
            self._log_line("❌ Coluna 'Código INEP' não encontrada no CSV.", "ERR")
            conn.close(); self._finish(); return

        total = len(all_rows)
        self._log_line(f"📋 {total} linhas no CSV.", "INFO")
        self.after(0, lambda: self._phase_lbl.config(text="📥 IMPORTANDO"))

        # ─ Carrega existentes do banco ──────────────────────────────
        with conn.cursor() as cur:
            cur.execute("SELECT codigo_inep, row_hash FROM escola_inep")
            existing = {r[0]: r[1] for r in cur.fetchall()}
        self._log_line(f"🗄️  {len(existing)} escolas já no banco.", "INFO")

        now_str   = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        # iid_map: codigo_inep -> iid na treeview (para atualizar coluna geo depois)
        iid_map: dict[str, str] = {}

        # ─ FASE 1: Import ───────────────────────────────────────────
        for i, row in enumerate(all_rows):
            if not self._running:
                break

            pct = (i + 1) / total * 100
            s   = self._stats
            self.after(0, lambda p=pct, n=i+1, _s=dict(s): (
                self._prog_var.set(p),
                self._prog_lbl.config(
                    text=f"{n}/{total}  {p:.1f}%  "
                         f"✅{_s['inseridos']} │ 🔄{_s['atualizados']} "
                         f"│ ─{_s['sem_mudanca']} │ ❌{_s['erros']}"
                )
            ))

            data        = row_to_dict(row, header_map)
            codigo_inep = (data.get("codigo_inep") or "").strip()
            if not codigo_inep:
                continue

            row_hash      = make_row_hash(data)
            identity_hash = make_identity_hash(codigo_inep)

            try:
                if codigo_inep not in existing:
                    with conn.cursor() as cur:
                        cur.execute(
                            """INSERT INTO escola_inep (
                                restricao_atendimento, escola, codigo_inep,
                                uf, municipio, localizacao, localidade_diferenciada,
                                categoria_administrativa, endereco, telefone,
                                dependencia_administrativa, categoria_escola_privada,
                                conveniada, regulamentacao, porte,
                                etapas_ensino, outras_ofertas,
                                row_hash, identity_hash, raw_data, imported_at
                            ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,
                                      %s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
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
                                row_hash, identity_hash,
                                json.dumps(data, ensure_ascii=False), now_str,
                            )
                        )
                    conn.commit()
                    self._stats["inseridos"] += 1
                    acao = "INSERIDO"
                    self._log_line(
                        f"  ✅ INSERIDO   {codigo_inep}  "
                        f"{data.get('escola','')[:50]}", "OK")

                elif existing[codigo_inep] == row_hash:
                    self._stats["sem_mudanca"] += 1
                    acao = "SEM_MUDANCA"

                else:
                    with conn.cursor() as cur:
                        cur.execute(
                            """UPDATE escola_inep SET
                                restricao_atendimento    = %s, escola = %s,
                                uf = %s, municipio = %s, localizacao = %s,
                                localidade_diferenciada  = %s,
                                categoria_administrativa = %s,
                                endereco = %s, telefone = %s,
                                dependencia_administrativa = %s,
                                categoria_escola_privada   = %s,
                                conveniada = %s, regulamentacao = %s,
                                porte = %s, etapas_ensino = %s,
                                outras_ofertas = %s,
                                row_hash = %s, raw_data = %s, updated_at = %s
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
                    self._log_line(
                        f"  🔄 ATUALIZADO {codigo_inep}  "
                        f"{data.get('escola','')[:50]}", "WARN")

            except Exception as e:
                self._stats["erros"] += 1
                acao = "ERRO"
                self._log_line(f"  ❌ ERRO  {codigo_inep}: {e}", "ERR")
                conn.rollback()

            iid = self._tree_insert(
                data.get("codigo_inep", ""),
                data.get("escola", "")[:60],
                data.get("uf", ""),
                data.get("municipio", ""),
                acao, ""
            )
            iid_map[codigo_inep] = iid

            if (i + 1) % 50 == 0:
                s = self._stats
                self._log_line(
                    f"── {i+1}/{total} | ✅{s['inseridos']} ins | "
                    f"🔄{s['atualizados']} upd | ─{s['sem_mudanca']} ║ ❌{s['erros']}",
                    "MUTED")

        # ─ FASE 2: Geocodificação ──────────────────────────────────
        if self._running and self._geo_var.get():
            self.after(0, lambda: self._phase_lbl.config(text="📍 GEOCODIFICANDO"))
            self._log_line(
                "\n📍 Iniciando geocodificação (Nominatim)...", "GEO")

            # Busca escolas sem coords no banco (só as que vieram no CSV)
            codigos_no_csv = list(iid_map.keys())
            sem_coords: list[dict] = []

            # Consulta em lotes de 500 para não estourar query
            for offset in range(0, len(codigos_no_csv), 500):
                lote = codigos_no_csv[offset:offset + 500]
                placeholders = ",".join(["%s"] * len(lote))
                with conn.cursor(pymysql.cursors.DictCursor) as cur:
                    cur.execute(
                        f"""SELECT codigo_inep, escola, endereco, municipio, uf,
                                   restricao_atendimento
                            FROM escola_inep
                            WHERE codigo_inep IN ({placeholders})
                              AND (latitude IS NULL OR latitude = '')""",
                        lote
                    )
                    sem_coords.extend(cur.fetchall())

            total_geo = len(sem_coords)
            self._log_line(
                f"📍 {total_geo} escolas sem coordenadas para geocodificar.",
                "GEO")

            max_layer   = int(self._max_layer_var.get())
            skip_par    = self._skip_paralisadas_var.get()
            self._prog_var.set(0)

            for gi, escola_row in enumerate(sem_coords):
                if not self._running:
                    break

                pct = (gi + 1) / max(total_geo, 1) * 100
                s   = self._stats
                self.after(0, lambda p=pct, n=gi+1, t=total_geo, _s=dict(s): (
                    self._prog_var.set(p),
                    self._prog_lbl.config(
                        text=f"{n}/{t}  {p:.1f}%  "
                             f"📍✅{_s['geo_ok']}  📍❌{_s['geo_falhou']}"
                    )
                ))

                codigo = escola_row["codigo_inep"]
                nome   = escola_row["escola"] or ""
                end    = escola_row["endereco"]
                mun    = escola_row["municipio"]
                uf_    = escola_row["uf"]
                rest   = (escola_row["restricao_atendimento"] or "").upper()

                # Pula paralisadas/extintas se opção marcada
                if skip_par and ("PARALISAD" in rest or "EXTINT" in rest):
                    self._log_line(
                        f"  ⏩ SKIP paralisada {codigo} {nome[:40]}", "MUTED")
                    self._update_tree_geo(iid_map.get(codigo, ""), "skip")
                    continue

                # Geocodifica com camadas
                lat, lon, camada = geocode_escola(
                    nome if max_layer >= 2 else None,
                    end  if max_layer >= 1 else None,
                    mun, uf_
                )
                # Se max_layer=1 não usa camada 2 nem 3
                if max_layer == 1 and camada in ("2-escola", "3-municipio"):
                    lat = lon = None; camada = "falhou"
                if max_layer == 2 and camada == "3-municipio":
                    lat = lon = None; camada = "falhou"

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
                        self._log_line(
                            f"  📍 [{camada}] {codigo} {nome[:40]} "
                            f"→ {lat:.6f}, {lon:.6f}", "GEO")
                        self._update_tree_geo(
                            iid_map.get(codigo, ""), camada)
                    except Exception as e:
                        self._stats["geo_falhou"] += 1
                        self._log_line(
                            f"  ❌ GEO DB ERR {codigo}: {e}", "ERR")
                        conn.rollback()
                        self._update_tree_geo(
                            iid_map.get(codigo, ""), "db-erro")
                else:
                    self._stats["geo_falhou"] += 1
                    self._log_line(
                        f"  ⚠️ SEM COORDS {codigo} {nome[:40]}", "WARN")
                    self._update_tree_geo(
                        iid_map.get(codigo, ""), "falhou")

                if (gi + 1) % 20 == 0:
                    s = self._stats
                    self._log_line(
                        f"── geo {gi+1}/{total_geo} | "
                        f"📍✅{s['geo_ok']}  📍❌{s['geo_falhou']}",
                        "MUTED")

        conn.close()

        # ─ Resumo final ──────────────────────────────────────────────
        s = self._stats
        self._log_line(
            f"\n✔ Concluído!\n"
            f"  Import: ✅{s['inseridos']} ins | 🔄{s['atualizados']} upd | "
            f"─{s['sem_mudanca']} sem mud | ❌{s['erros']} err\n"
            f"  Geo:    📍✅{s['geo_ok']} encontradas | "
            f"📍❌{s['geo_falhou']} não encontradas",
            "OK"
        )
        self.after(0, lambda: (
            self._phase_lbl.config(text="✔ Pronto"),
            self._lbl_stats.config(
                text=f"✅{s['inseridos']} 🔄{s['atualizados']} "
                     f"─{s['sem_mudanca']} ❌{s['erros']} "
                     f"📍✅{s['geo_ok']} 📍❌{s['geo_falhou']}"
            )
        ))
        self._finish()

    # ── Helpers de UI ─────────────────────────────────────────────────────

    def _tree_insert(self, codigo, escola, uf, mun, acao, geo) -> str:
        iid = self._tree.insert(
            "", "end",
            values=(codigo, escola, uf, mun,
                    acao.replace("_", " "), geo),
            tags=(acao,)
        )
        return iid

    def _update_tree_geo(self, iid: str, geo_status: str):
        if not iid:
            return
        color_map = {
            "1-endereco": GREEN,
            "2-escola":   YELLOW,
            "3-municipio": ACCENT,
            "falhou":     RED,
            "skip":       MUTED,
            "db-erro":    RED,
        }
        color = color_map.get(geo_status, MUTED)
        self.after(0, lambda i=iid, g=geo_status, c=color: (
            self._tree.set(i, "geo", g),
            self._tree.tag_configure(f"geo_{i}", foreground=c),
        ))

    def _log_line(self, msg: str, tag: str = "INFO"):
        ts = datetime.now().strftime("%H:%M:%S")
        self.after(0, lambda m=f"[{ts}] {msg}\n", t=tag:
                   self._append_log(m, t))

    def _append_log(self, msg: str, tag: str):
        self._log.configure(state="normal")
        self._log.insert("end", msg, tag)
        self._log.see("end")
        self._log.configure(state="disabled")

    def _finish(self):
        self._running = False
        self.after(0, lambda: (
            self._btn_start.config(state="normal"),
            self._btn_stop.config(state="disabled"),
        ))


if __name__ == "__main__":
    app = App()
    app.mainloop()
