#!/usr/bin/env python3
"""
importar_escolas.py — ToolboxWaze
Importa CSV do Censo Escolar INEP para a tabela escola_inep.

Regras:
  - codigo_inep novo          → INSERT com todos os campos do CSV
  - codigo_inep já existe     → UPDATE apenas dos campos do MEC
                                 (nunca toca em latitude, longitude,
                                  link_waze, permanent_hazard_id,
                                  link_area_escolar, row_hash, identity_hash,
                                  raw_data, imported_at)
  - row_hash igual            → SEM MUDANÇA (pula silenciosamente)
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
# Chave = nome da coluna no banco, Valor = lista de possíveis nomes no CSV
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

# Campos que o MEC NÃO deve sobrescrever (preenchidos manualmente no ToolboxWaze)
PROTECTED = {"latitude", "longitude", "link_waze", "permanent_hazard_id",
             "link_area_escolar", "row_hash", "identity_hash", "raw_data", "imported_at"}


# ── Helpers ───────────────────────────────────────────────────────────────────

def sha256(text: str) -> str:
    return hashlib.sha256(text.encode()).hexdigest()


def resolve_header(csv_header: list) -> dict:
    """Retorna {coluna_db: índice_csv} para as colunas encontradas."""
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


# ── GUI ───────────────────────────────────────────────────────────────────────

BG      = "#1e1e2e"
SURFACE = "#2a2a3e"
TEXT    = "#cdd6f4"
MUTED   = "#6c7086"
GREEN   = "#a6e3a1"
YELLOW  = "#f9e2af"
RED     = "#f38ba8"
TEAL    = "#89dceb"
ACCENT  = "#89b4fa"


class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("ToolboxWaze — Importar Escolas INEP")
        self.geometry("1100x700")
        self.configure(bg=BG)
        self.resizable(True, True)

        self._running = False
        self._stats   = {"inseridos": 0, "atualizados": 0, "sem_mudanca": 0, "erros": 0}

        self._build_ui()

    # ── UI ────────────────────────────────────────────────────────────────────

    def _build_ui(self):
        top = tk.Frame(self, bg=BG, pady=8, padx=12)
        top.pack(fill="x")

        tk.Label(top, text="📁 CSV:", bg=BG, fg=TEXT, font=("Consolas", 10)).pack(side="left")
        self._csv_var = tk.StringVar()
        tk.Entry(top, textvariable=self._csv_var, bg=SURFACE, fg=TEXT,
                 insertbackground=TEXT, width=60,
                 relief="flat", font=("Consolas", 10)).pack(side="left", padx=6)
        tk.Button(top, text="Escolher", bg=ACCENT, fg=BG, font=("Consolas", 10, "bold"),
                  relief="flat", padx=8, command=self._choose_file).pack(side="left", padx=4)

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

        self._lbl_stats = tk.Label(top, text="", bg=BG, fg=MUTED, font=("Consolas", 9))
        self._lbl_stats.pack(side="right", padx=12)

        # Progress
        prog_frame = tk.Frame(self, bg=BG, padx=12)
        prog_frame.pack(fill="x")
        self._prog_var = tk.DoubleVar()
        self._prog_lbl = tk.Label(prog_frame, text="Aguardando...", bg=BG,
                                  fg=MUTED, font=("Consolas", 9))
        self._prog_lbl.pack(side="left")
        self._prog = ttk.Progressbar(prog_frame, variable=self._prog_var,
                                     maximum=100, length=400)
        self._prog.pack(side="left", padx=8, pady=4)

        # Paned
        paned = tk.PanedWindow(self, orient="vertical", bg=BG, sashwidth=6)
        paned.pack(fill="both", expand=True, padx=12, pady=(0, 8))

        # Log
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
        for tag, color in [("OK", GREEN), ("WARN", YELLOW),
                           ("ERR", RED), ("INFO", TEAL), ("MUTED", MUTED)]:
            self._log.tag_config(tag, foreground=color)

        # Tabela
        tbl_frame = tk.Frame(paned, bg=BG)
        paned.add(tbl_frame, minsize=150)
        tk.Label(tbl_frame, text="Resumo por escola", bg=BG, fg=MUTED,
                 font=("Consolas", 9)).pack(anchor="w")

        cols = ("codigo_inep", "escola", "uf", "municipio", "acao")
        self._tree = ttk.Treeview(tbl_frame, columns=cols, show="headings", height=8)
        style = ttk.Style()
        style.theme_use("clam")
        style.configure("Treeview", background=SURFACE, fieldbackground=SURFACE,
                        foreground=TEXT, rowheight=22, font=("Consolas", 9))
        style.configure("Treeview.Heading", background=BG, foreground=MUTED,
                        font=("Consolas", 9, "bold"))
        style.map("Treeview", background=[("selected", ACCENT)],
                  foreground=[("selected", BG)])

        widths = {"codigo_inep": 120, "escola": 360, "uf": 50,
                  "municipio": 160, "acao": 120}
        for c in cols:
            self._tree.heading(c, text=c.replace("_", " ").title())
            self._tree.column(c, width=widths[c], anchor="w")

        self._tree.tag_configure("INSERIDO",    foreground=GREEN)
        self._tree.tag_configure("ATUALIZADO",  foreground=YELLOW)
        self._tree.tag_configure("SEM_MUDANCA", foreground=MUTED)
        self._tree.tag_configure("ERRO",        foreground=RED)

        sb_tree = tk.Scrollbar(tbl_frame, command=self._tree.yview)
        self._tree.configure(yscrollcommand=sb_tree.set)
        sb_tree.pack(side="right", fill="y")
        sb_x = tk.Scrollbar(tbl_frame, orient="horizontal", command=self._tree.xview)
        self._tree.configure(xscrollcommand=sb_x.set)
        sb_x.pack(side="bottom", fill="x")
        self._tree.pack(fill="both", expand=True)

    # ── Ações ─────────────────────────────────────────────────────────────────

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
        self._stats = {"inseridos": 0, "atualizados": 0, "sem_mudanca": 0, "erros": 0}
        self._prog_var.set(0)
        self._prog_lbl.config(text="Aguardando...")
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

    # ── Worker ────────────────────────────────────────────────────────────────

    def _run(self, csv_path: str):
        try:
            conn = pymysql.connect(**DB_CONFIG)
        except Exception as e:
            self._log_line(f"❌ Falha na conexão: {e}", "ERR")
            self._finish()
            return

        self._log_line("✅ Conectado ao banco.", "OK")

        try:
            with open(csv_path, newline="", encoding="utf-8-sig") as f:
                reader = csv.reader(f, delimiter=";")
                header = next(reader)
                all_rows = list(reader)
        except Exception as e:
            self._log_line(f"❌ Erro ao ler CSV: {e}", "ERR")
            conn.close()
            self._finish()
            return

        header_map = resolve_header(header)
        if "codigo_inep" not in header_map:
            self._log_line("❌ Coluna 'Código INEP' não encontrada no CSV.", "ERR")
            conn.close()
            self._finish()
            return

        total = len(all_rows)
        self._log_line(f"📋 {total} linhas encontradas no CSV.", "INFO")

        # Carrega todos os codigo_inep existentes + row_hash atual
        with conn.cursor() as cur:
            cur.execute(
                "SELECT codigo_inep, row_hash FROM escola_inep"
            )
            existing = {row[0]: row[1] for row in cur.fetchall()}

        self._log_line(f"🗄️  {len(existing)} escolas já no banco.", "INFO")

        batch_size = 50
        now_str    = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

        for i, row in enumerate(all_rows):
            if not self._running:
                break

            pct = (i + 1) / total * 100
            s   = self._stats
            self.after(0, lambda p=pct, n=i+1, _s=dict(s): (
                self._prog_var.set(p),
                self._prog_lbl.config(
                    text=f"{n}/{total}  {p:.1f}%  "
                         f"✅{_s['inseridos']}  "
                         f"🔄{_s['atualizados']}  "
                         f"─{_s['sem_mudanca']}  "
                         f"❌{_s['erros']}"
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
                    # ── INSERT ────────────────────────────────────────────
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
                            ) VALUES (
                                %s,%s,%s,%s,%s,%s,%s,%s,%s,%s,
                                %s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s
                            )""",
                            (
                                data.get("restricao_atendimento"),
                                data.get("escola"),
                                codigo_inep,
                                data.get("uf"),
                                data.get("municipio"),
                                data.get("localizacao"),
                                data.get("localidade_diferenciada"),
                                data.get("categoria_administrativa"),
                                data.get("endereco"),
                                data.get("telefone"),
                                data.get("dependencia_administrativa"),
                                data.get("categoria_escola_privada"),
                                data.get("conveniada"),
                                data.get("regulamentacao"),
                                data.get("porte"),
                                data.get("etapas_ensino"),
                                data.get("outras_ofertas"),
                                row_hash,
                                identity_hash,
                                json.dumps(data, ensure_ascii=False),
                                now_str,
                            )
                        )
                    conn.commit()
                    self._stats["inseridos"] += 1
                    acao = "INSERIDO"
                    self._log_line(
                        f"  ✅ INSERIDO   {codigo_inep}  {data.get('escola','')[:50]}", "OK"
                    )

                elif existing[codigo_inep] == row_hash:
                    # ── SEM MUDANÇA ───────────────────────────────────────
                    self._stats["sem_mudanca"] += 1
                    acao = "SEM_MUDANCA"

                else:
                    # ── UPDATE — nunca toca em coords/links ───────────────
                    with conn.cursor() as cur:
                        cur.execute(
                            """UPDATE escola_inep SET
                                restricao_atendimento    = %s,
                                escola                   = %s,
                                uf                       = %s,
                                municipio                = %s,
                                localizacao              = %s,
                                localidade_diferenciada  = %s,
                                categoria_administrativa = %s,
                                endereco                 = %s,
                                telefone                 = %s,
                                dependencia_administrativa  = %s,
                                categoria_escola_privada    = %s,
                                conveniada               = %s,
                                regulamentacao           = %s,
                                porte                    = %s,
                                etapas_ensino            = %s,
                                outras_ofertas           = %s,
                                row_hash                 = %s,
                                raw_data                 = %s,
                                updated_at               = %s
                            WHERE codigo_inep = %s
                            """,
                            (
                                data.get("restricao_atendimento"),
                                data.get("escola"),
                                data.get("uf"),
                                data.get("municipio"),
                                data.get("localizacao"),
                                data.get("localidade_diferenciada"),
                                data.get("categoria_administrativa"),
                                data.get("endereco"),
                                data.get("telefone"),
                                data.get("dependencia_administrativa"),
                                data.get("categoria_escola_privada"),
                                data.get("conveniada"),
                                data.get("regulamentacao"),
                                data.get("porte"),
                                data.get("etapas_ensino"),
                                data.get("outras_ofertas"),
                                row_hash,
                                json.dumps(data, ensure_ascii=False),
                                now_str,
                                codigo_inep,
                            )
                        )
                    conn.commit()
                    self._stats["atualizados"] += 1
                    acao = "ATUALIZADO"
                    self._log_line(
                        f"  🔄 ATUALIZADO {codigo_inep}  {data.get('escola','')[:50]}", "WARN"
                    )

            except Exception as e:
                self._stats["erros"] += 1
                acao = "ERRO"
                self._log_line(f"  ❌ ERRO  {codigo_inep}: {e}", "ERR")
                conn.rollback()

            self.after(0, lambda d=data, a=acao: self._tree.insert(
                "", "end",
                values=(
                    d.get("codigo_inep", ""),
                    d.get("escola", "")[:60],
                    d.get("uf", ""),
                    d.get("municipio", ""),
                    a.replace("_", " "),
                ),
                tags=(a,)
            ))

            if (i + 1) % batch_size == 0:
                s = self._stats
                self._log_line(
                    f"── {i+1}/{total} | ✅ {s['inseridos']} inseridos | "
                    f"🔄 {s['atualizados']} atualizados | "
                    f"─ {s['sem_mudanca']} sem mudança | "
                    f"❌ {s['erros']} erros", "MUTED"
                )

        conn.close()
        s = self._stats
        self._log_line(
            f"\n✔ Concluído!  ✅ {s['inseridos']} inseridos | "
            f"🔄 {s['atualizados']} atualizados | "
            f"─ {s['sem_mudanca']} sem mudança | "
            f"❌ {s['erros']} erros", "OK"
        )
        self.after(0, lambda: self._lbl_stats.config(
            text=f"✅{s['inseridos']}  🔄{s['atualizados']}  ─{s['sem_mudanca']}  ❌{s['erros']}"
        ))
        self._finish()

    # ── Util ──────────────────────────────────────────────────────────────────

    def _log_line(self, msg: str, tag: str = "INFO"):
        ts = datetime.now().strftime("%H:%M:%S")
        self.after(0, lambda m=f"[{ts}] {msg}\n", t=tag: self._append_log(m, t))

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
