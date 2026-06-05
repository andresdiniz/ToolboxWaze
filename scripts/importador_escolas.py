import tkinter as tk
from tkinter import ttk, filedialog, messagebox
import threading
import csv
import os
import time
import webbrowser
import hashlib
import json
from datetime import datetime

try:
    import pandas as pd
    HAS_PANDAS = True
except ImportError:
    HAS_PANDAS = False

try:
    from geopy.geocoders import Nominatim
    from geopy.exc import GeocoderTimedOut
    HAS_GEOPY = True
except ImportError:
    HAS_GEOPY = False

try:
    import mysql.connector
    HAS_MYSQL = True
except ImportError:
    HAS_MYSQL = False

# ── Paleta ──────────────────────────────────────────────────────────────────
BG         = "#1e1e2e"
SURFACE    = "#2a2a3d"
SURFACE2   = "#313145"
ACCENT     = "#7c3aed"
ACCENT2    = "#a78bfa"
SUCCESS    = "#22c55e"
WARNING    = "#f59e0b"
ERROR      = "#ef4444"
INFO       = "#38bdf8"
TEXT       = "#e2e8f0"
TEXT_MUTED = "#94a3b8"
BORDER     = "#3f3f5c"

# ── Credenciais padrao do .env ───────────────────────────────────────────────
DEFAULT_DB = {
    "host":   "212.85.3.243",
    "port":   "3306",
    "user":   "u629736858_wazetoolbox",
    "passwd": "@Ndre2026.",
    "db":     "u629736858_wazetoolbox",
}


class SchoolImportApp(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("Importador de Escolas - escola_inep")
        self.geometry("1160x780")
        self.minsize(960, 600)
        self.configure(bg=BG)
        self.resizable(True, True)

        self._stop_flag  = False
        self._running    = False
        self._records    = []
        self._file_path  = tk.StringVar(value="Nenhum arquivo selecionado")
        self._progress   = tk.DoubleVar(value=0)
        self._prog_label = tk.StringVar(value="Aguardando...")
        self._mode       = tk.StringVar(value="csv_db")   # csv_only | csv_db
        self._db_conn    = None

        # variaveis DB
        self._db_host   = tk.StringVar(value=DEFAULT_DB["host"])
        self._db_port   = tk.StringVar(value=DEFAULT_DB["port"])
        self._db_user   = tk.StringVar(value=DEFAULT_DB["user"])
        self._db_passwd = tk.StringVar(value=DEFAULT_DB["passwd"])
        self._db_name   = tk.StringVar(value=DEFAULT_DB["db"])
        self._db_status = tk.StringVar(value="Nao conectado")

        self._build_ui()
        self._check_deps()

    # ── Deps ─────────────────────────────────────────────────────────────────
    def _check_deps(self):
        missing = []
        if not HAS_PANDAS: missing.append("pandas")
        if not HAS_GEOPY:  missing.append("geopy")
        if not HAS_MYSQL:  missing.append("mysql-connector-python")
        if missing:
            self._log("pip install " + " ".join(missing), WARNING)
        else:
            self._log("Dependencias OK. Pronto para usar.", SUCCESS)

    # ── UI ───────────────────────────────────────────────────────────────────
    def _build_ui(self):
        # Header
        hdr = tk.Frame(self, bg=SURFACE, pady=10)
        hdr.pack(fill="x")
        tk.Label(hdr, text="Importador de Escolas",
                 font=("Segoe UI", 15, "bold"), bg=SURFACE, fg=ACCENT2).pack(side="left", padx=16)
        tk.Label(hdr, text="tabela escola_inep  |  MEC/INEP + Nominatim",
                 font=("Segoe UI", 9), bg=SURFACE, fg=TEXT_MUTED).pack(side="left")

        # Notebook de configuracao
        nb_frame = tk.Frame(self, bg=BG)
        nb_frame.pack(fill="x", padx=12, pady=(6, 0))
        sty = ttk.Style(self)
        sty.theme_use("default")
        sty.configure("TNotebook", background=BG, borderwidth=0)
        sty.configure("TNotebook.Tab", background=SURFACE2, foreground=TEXT_MUTED,
                      padding=[10, 4], font=("Segoe UI", 9))
        sty.map("TNotebook.Tab", background=[("selected", SURFACE)],
                foreground=[("selected", ACCENT2)])
        nb = ttk.Notebook(nb_frame)
        nb.pack(fill="x")

        # Tab 1 — Arquivo
        tab_file = tk.Frame(nb, bg=SURFACE, pady=8, padx=12)
        nb.add(tab_file, text="  Arquivo CSV  ")
        tk.Button(tab_file, text="📂 Escolher CSV", command=self._pick_file,
                  bg=ACCENT, fg="white", relief="flat",
                  font=("Segoe UI", 9, "bold"), padx=12, pady=4, cursor="hand2").pack(side="left")
        tk.Label(tab_file, textvariable=self._file_path,
                 bg=SURFACE, fg=TEXT_MUTED, font=("Segoe UI", 9)).pack(side="left", padx=12)

        # Tab 2 — Banco de Dados
        tab_db = tk.Frame(nb, bg=SURFACE, pady=8, padx=12)
        nb.add(tab_db, text="  Conexão DB  ")
        self._build_db_tab(tab_db, sty)

        # Tab 3 — Modo
        tab_mode = tk.Frame(nb, bg=SURFACE, pady=8, padx=12)
        nb.add(tab_mode, text="  Modo  ")
        tk.Radiobutton(tab_mode, text="Apenas CSV (sem banco)",
                       variable=self._mode, value="csv_only",
                       bg=SURFACE, fg=TEXT, selectcolor=SURFACE2,
                       font=("Segoe UI", 9), activebackground=SURFACE).pack(side="left", padx=8)
        tk.Radiobutton(tab_mode, text="CSV + Gravar no banco (escola_inep)",
                       variable=self._mode, value="csv_db",
                       bg=SURFACE, fg=ACCENT2, selectcolor=SURFACE2,
                       font=("Segoe UI", 9, "bold"), activebackground=SURFACE).pack(side="left", padx=8)

        # Botoes de controle
        ctrl = tk.Frame(self, bg=BG, pady=8, padx=12)
        ctrl.pack(fill="x")
        self._btn_start = tk.Button(ctrl, text="▶ Iniciar", command=self._start,
            bg=SUCCESS, fg="white", relief="flat",
            font=("Segoe UI", 9, "bold"), padx=14, pady=5, cursor="hand2")
        self._btn_start.pack(side="left", padx=(0, 6))
        self._btn_stop = tk.Button(ctrl, text="■ Parar", command=self._stop,
            bg=ERROR, fg="white", relief="flat",
            font=("Segoe UI", 9, "bold"), padx=14, pady=5,
            cursor="hand2", state="disabled")
        self._btn_stop.pack(side="left", padx=(0, 6))
        tk.Button(ctrl, text="💾 Exportar", command=self._export,
            bg=SURFACE, fg=TEXT, relief="flat",
            font=("Segoe UI", 9), padx=14, pady=5, cursor="hand2").pack(side="left", padx=(0, 6))
        tk.Button(ctrl, text="🗑 Limpar", command=self._clear,
            bg=SURFACE, fg=TEXT_MUTED, relief="flat",
            font=("Segoe UI", 9), padx=14, pady=5, cursor="hand2").pack(side="left")
        tk.Label(ctrl, textvariable=self._prog_label,
                 bg=BG, fg=TEXT_MUTED, font=("Segoe UI", 9)).pack(side="right", padx=8)

        # Barra de progresso
        pf = tk.Frame(self, bg=BG, padx=12)
        pf.pack(fill="x")
        sty.configure("P.Horizontal.TProgressbar",
                      troughcolor=SURFACE, background=ACCENT2, thickness=8, borderwidth=0)
        ttk.Progressbar(pf, variable=self._progress, maximum=100,
                        style="P.Horizontal.TProgressbar").pack(fill="x", pady=(0, 6))

        # PanedWindow log + tabela
        paned = tk.PanedWindow(self, orient="vertical",
                               bg=BORDER, sashwidth=5, sashrelief="flat")
        paned.pack(fill="both", expand=True, padx=12, pady=(0, 12))

        # Log
        lf = tk.Frame(paned, bg=SURFACE, bd=0)
        paned.add(lf, minsize=150)
        tk.Label(lf, text="Log de Execução", font=("Segoe UI", 9, "bold"),
                 bg=SURFACE, fg=TEXT_MUTED, pady=4).pack(anchor="w", padx=8)
        ls = tk.Scrollbar(lf, bg=SURFACE, troughcolor=SURFACE2)
        ls.pack(side="right", fill="y")
        self._log_box = tk.Text(lf, bg=SURFACE, fg=TEXT, font=("Consolas", 9),
                                insertbackground=TEXT, wrap="word",
                                relief="flat", padx=8, pady=4,
                                yscrollcommand=ls.set, state="disabled")
        self._log_box.pack(fill="both", expand=True)
        ls.config(command=self._log_box.yview)
        self._log_box.tag_config("success", foreground=SUCCESS)
        self._log_box.tag_config("warning", foreground=WARNING)
        self._log_box.tag_config("error",   foreground=ERROR)
        self._log_box.tag_config("accent",  foreground=ACCENT2)
        self._log_box.tag_config("info",    foreground=INFO)
        self._log_box.tag_config("muted",   foreground=TEXT_MUTED)

        # Tabela
        tf = tk.Frame(paned, bg=SURFACE, bd=0)
        paned.add(tf, minsize=120)
        th = tk.Frame(tf, bg=SURFACE)
        th.pack(fill="x")
        tk.Label(th, text="Registros por Escola",
                 font=("Segoe UI", 9, "bold"), bg=SURFACE, fg=TEXT_MUTED, pady=4).pack(side="left", padx=8)
        tk.Label(th, text="📍 Duplo clique para abrir no Google Maps",
                 font=("Segoe UI", 8), bg=SURFACE, fg=ACCENT2).pack(side="left", padx=4)

        cols_visible = ("Escola", "Ação", "DB", "Status", "Horário")
        cols_all     = cols_visible + ("_lat", "_lon")
        sy = tk.Scrollbar(tf); sy.pack(side="right", fill="y")
        sx = tk.Scrollbar(tf, orient="horizontal"); sx.pack(side="bottom", fill="x")
        sty.configure("D.Treeview", background=SURFACE2, foreground=TEXT,
                      fieldbackground=SURFACE2, borderwidth=0, rowheight=22)
        sty.configure("D.Treeview.Heading", background=SURFACE, foreground=ACCENT2,
                      relief="flat", font=("Segoe UI", 9, "bold"))
        sty.map("D.Treeview", background=[("selected", ACCENT)], foreground=[("selected", "white")])
        self._tree = ttk.Treeview(tf, columns=cols_all, show="headings",
                                  style="D.Treeview",
                                  yscrollcommand=sy.set, xscrollcommand=sx.set,
                                  cursor="hand2")
        for col, w in zip(cols_visible, [280, 200, 90, 110, 100]):
            self._tree.heading(col, text=col)
            self._tree.column(col, width=w, anchor="w")
        for hidden in ("_lat", "_lon"):
            self._tree.heading(hidden, text="")
            self._tree.column(hidden, width=0, minwidth=0, stretch=False)
        self._tree.pack(fill="both", expand=True)
        sy.config(command=self._tree.yview)
        sx.config(command=self._tree.xview)
        self._tree.bind("<Double-1>", self._on_row_click)

    def _build_db_tab(self, parent, sty):
        row = tk.Frame(parent, bg=SURFACE)
        row.pack(fill="x")

        def lbl_entry(parent, label, var, w=180, show=None):
            f = tk.Frame(parent, bg=SURFACE)
            f.pack(side="left", padx=(0, 12))
            tk.Label(f, text=label, bg=SURFACE, fg=TEXT_MUTED,
                     font=("Segoe UI", 8)).pack(anchor="w")
            e = tk.Entry(f, textvariable=var, width=w // 9,
                         bg=SURFACE2, fg=TEXT, insertbackground=TEXT,
                         relief="flat", font=("Segoe UI", 9),
                         show=show or "")
            e.pack()
            return e

        lbl_entry(row, "Host",    self._db_host,   w=160)
        lbl_entry(row, "Porta",   self._db_port,   w=60)
        lbl_entry(row, "Usuário", self._db_user,   w=200)
        lbl_entry(row, "Senha",   self._db_passwd, w=160, show="*")
        lbl_entry(row, "Banco",   self._db_name,   w=200)

        row2 = tk.Frame(parent, bg=SURFACE)
        row2.pack(fill="x", pady=(6, 0))
        tk.Button(row2, text="🔌 Testar Conexão", command=self._test_connection,
                  bg=INFO, fg="white", relief="flat",
                  font=("Segoe UI", 9, "bold"), padx=12, pady=3, cursor="hand2").pack(side="left")
        tk.Label(row2, textvariable=self._db_status,
                 bg=SURFACE, fg=SUCCESS, font=("Segoe UI", 9)).pack(side="left", padx=12)

    # ── Conexao DB ────────────────────────────────────────────────────────────
    def _get_conn(self):
        if not HAS_MYSQL:
            raise RuntimeError("mysql-connector-python nao instalado")
        return mysql.connector.connect(
            host=self._db_host.get(),
            port=int(self._db_port.get()),
            user=self._db_user.get(),
            password=self._db_passwd.get(),
            database=self._db_name.get(),
            charset="utf8mb4",
            connect_timeout=10,
        )

    def _test_connection(self):
        try:
            conn = self._get_conn()
            conn.close()
            self._db_status.set("✅ Conectado com sucesso!")
            self._log("Conexao ao banco testada com sucesso.", SUCCESS)
        except Exception as e:
            self._db_status.set(f"❌ Erro: {e}")
            self._log(f"Falha na conexao: {e}", ERROR)

    # ── Clique duplo → Google Maps ─────────────────────────────────────────
    def _on_row_click(self, event):
        item = self._tree.focus()
        if not item: return
        values = self._tree.item(item, "values")
        if len(values) < 7: return
        lat, lon = str(values[5]).strip(), str(values[6]).strip()
        escola   = values[0]
        if lat and lon:
            url = f"https://www.google.com/maps?q={lat},{lon}"
            self._log(f"📍 Google Maps: {escola}", ACCENT2)
            webbrowser.open(url)
        else:
            self._log(f"Sem coordenadas: {escola}", WARNING)

    # ── Log / Tabela ──────────────────────────────────────────────────────────
    def _log(self, msg: str, color: str = TEXT):
        tags = {SUCCESS: "success", WARNING: "warning", ERROR: "error",
                ACCENT2: "accent", INFO: "info", TEXT_MUTED: "muted"}
        ts = datetime.now().strftime("%H:%M:%S")
        self._log_box.configure(state="normal")
        self._log_box.insert("end", f"[{ts}]  {msg}\n", tags.get(color, ""))
        self._log_box.see("end")
        self._log_box.configure(state="disabled")

    def _add_record(self, escola, acao, db_status, status, lat="", lon=""):
        ts = datetime.now().strftime("%H:%M:%S")
        self._records.append({"escola": escola, "acao": acao, "db": db_status,
                               "status": status, "ts": ts, "lat": lat, "lon": lon})
        tag = "success" if status == "OK" else ("error" if status == "ERRO" else "")
        self._tree.insert("", "end",
                          values=(escola, acao, db_status, status, ts, lat, lon),
                          tags=(tag,))
        self._tree.tag_configure("success", foreground=SUCCESS)
        self._tree.tag_configure("error",   foreground=ERROR)
        self._tree.yview_moveto(1)

    def _set_progress(self, pct, label):
        self._progress.set(pct)
        self._prog_label.set(label)

    # ── Arquivo ───────────────────────────────────────────────────────────────
    def _pick_file(self):
        p = filedialog.askopenfilename(
            title="Selecione o CSV",
            filetypes=[("CSV", "*.csv"), ("Todos", "*.*")])
        if p:
            self._file_path.set(p)
            self._log(f"Arquivo: {os.path.basename(p)}", ACCENT2)

    # ── Iniciar / Parar / Limpar ──────────────────────────────────────────────
    def _start(self):
        p = self._file_path.get()
        if not os.path.isfile(p):
            messagebox.showerror("Erro", "Selecione um arquivo CSV valido.")
            return
        if self._running: return
        if self._mode.get() == "csv_db":
            try:
                self._db_conn = self._get_conn()
                self._log("Banco conectado.", SUCCESS)
            except Exception as e:
                messagebox.showerror("Erro de Conexao", str(e))
                return
        self._stop_flag = False
        self._running   = True
        self._btn_start.config(state="disabled")
        self._btn_stop.config(state="normal")
        threading.Thread(target=self._process, args=(p,), daemon=True).start()

    def _stop(self):
        self._stop_flag = True
        self._log("Interrupcao solicitada...", WARNING)

    def _clear(self):
        self._log_box.configure(state="normal")
        self._log_box.delete("1.0", "end")
        self._log_box.configure(state="disabled")
        for r in self._tree.get_children():
            self._tree.delete(r)
        self._records.clear()
        self._set_progress(0, "Aguardando...")

    # ── Processamento (thread) ────────────────────────────────────────────────
    def _process(self, path):
        try:
            rows = self._read_csv(path)
        except Exception as e:
            self.after(0, self._log, f"Erro ao ler CSV: {e}", ERROR)
            self._finish()
            return

        total = len(rows)
        if total == 0:
            self.after(0, self._log, "CSV sem dados.", WARNING)
            self._finish()
            return

        use_db = self._mode.get() == "csv_db" and self._db_conn is not None
        self.after(0, self._log,
                   f"Iniciando: {total} escola(s)  |  modo: {'CSV + DB' if use_db else 'CSV Only'}",
                   ACCENT2)

        geo = Nominatim(user_agent="school_importer_v2") if HAS_GEOPY else None
        inserted = updated = skipped = 0

        for i, row in enumerate(rows, 1):
            if self._stop_flag:
                self.after(0, self._log, "Processamento interrompido.", WARNING)
                break

            nome   = str(row.get("NO_ENTIDADE")   or row.get("escola")    or f"Escola #{i}").strip()
            codigo = str(row.get("CO_ENTIDADE")   or row.get("codigo_inep") or "").strip()
            status = str(row.get("TP_SITUACAO_FUNCIONAMENTO") or row.get("situacao") or "1").strip()
            lat    = str(row.get("NU_LATITUDE")   or row.get("latitude")  or "").strip().replace(",", ".")
            lon    = str(row.get("NU_LONGITUDE")  or row.get("longitude") or "").strip().replace(",", ".")
            uf     = str(row.get("SG_UF")         or row.get("uf")        or "").strip()
            mun    = str(row.get("NO_MUNICIPIO")  or row.get("municipio") or "").strip()
            loc    = str(row.get("TP_LOCALIZACAO") or row.get("localizacao") or "").strip()
            end    = str(row.get("DS_ENDERECO")   or row.get("endereco")  or "").strip()
            dep    = str(row.get("TP_DEPENDENCIA") or row.get("dependencia") or "").strip()
            etapas = str(row.get("etapas_ensino") or "").strip()

            geo_acao = ""
            if lat and lon:
                acao = f"Coords OK ({lat}, {lon})"
                st   = "OK"
                self.after(0, self._log, f"OK  {nome} — {lat}, {lon}", SUCCESS)
            elif status != "1":
                acao, st, lat, lon = f"Ignorada (sit={status})", "IGNORADA", "", ""
                self.after(0, self._log, f"--  {nome} — ignorada", TEXT_MUTED)
            else:
                acao, st, lat, lon = self._geocode(geo, nome, mun, uf)

            # ── Grava no banco ─────────────────────────────────────────────
            db_status = "-"
            if use_db and st in ("OK", "IGNORADA", "SEM COORDS", "TIMEOUT"):
                try:
                    r = self._upsert_escola(row, nome, codigo, uf, mun, lat, lon,
                                            loc, end, dep, etapas)
                    db_status = r
                    if r == "INSERT": inserted += 1
                    elif r == "UPDATE": updated += 1
                    else: skipped += 1
                    self.after(0, self._log,
                               f"DB {r:<6}  {nome}", INFO)
                except Exception as e:
                    db_status = "ERR"
                    self.after(0, self._log, f"DB ERRO {nome}: {e}", ERROR)

            self.after(0, self._add_record, nome, acao, db_status, st, lat, lon)
            pct = (i / total) * 100
            self.after(0, self._set_progress, pct, f"{i} / {total}  ({pct:.1f}%)")
            if i % 50 == 0:
                self.after(0, self._log,
                           f"Progresso {i}/{total}  |  insert={inserted} update={updated} skip={skipped}",
                           TEXT_MUTED)
            time.sleep(0.02)

        if use_db:
            try:
                self._db_conn.commit()
                self._db_conn.close()
            except Exception:
                pass
        self.after(0, self._log,
                   f"Concluido! {len(self._records)} linhas  |  "
                   f"INSERT={inserted}  UPDATE={updated}  SKIP={skipped}",
                   SUCCESS)
        self._finish()

    # ── Upsert na tabela escola_inep ─────────────────────────────────────────
    def _upsert_escola(self, raw_row, nome, codigo, uf, municipio,
                       lat, lon, localizacao, endereco, dependencia, etapas):
        row_str  = json.dumps(raw_row, ensure_ascii=False, sort_keys=True)
        row_hash = hashlib.sha256(row_str.encode()).hexdigest()
        id_hash  = hashlib.sha256(codigo.encode()).hexdigest() if codigo else None
        now      = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")

        cursor = self._db_conn.cursor(dictionary=True)

        # Verifica se ja existe pelo codigo_inep
        cursor.execute(
            "SELECT id, row_hash FROM escola_inep WHERE codigo_inep = %s LIMIT 1",
            (codigo,)
        )
        existing = cursor.fetchone()

        if existing:
            if existing["row_hash"] == row_hash:
                cursor.close()
                return "SKIP"
            cursor.execute("""
                UPDATE escola_inep SET
                    escola = %s, uf = %s, municipio = %s,
                    latitude = %s, longitude = %s,
                    localizacao = %s, endereco = %s,
                    dependencia_administrativa = %s,
                    etapas_ensino = %s,
                    row_hash = %s, raw_data = %s, updated_at = %s
                WHERE id = %s
            """, (
                nome, uf, municipio,
                lat or None, lon or None,
                localizacao or None, endereco or None,
                dependencia or None,
                etapas or None,
                row_hash, json.dumps(raw_row, ensure_ascii=False),
                now, existing["id"]
            ))
            cursor.close()
            return "UPDATE"
        else:
            cursor.execute("""
                INSERT INTO escola_inep
                    (escola, codigo_inep, uf, municipio,
                     latitude, longitude, localizacao, endereco,
                     dependencia_administrativa, etapas_ensino,
                     row_hash, identity_hash, raw_data,
                     imported_at, updated_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NULL)
            """, (
                nome, codigo or None, uf, municipio,
                lat or None, lon or None,
                localizacao or None, endereco or None,
                dependencia or None, etapas or None,
                row_hash, id_hash,
                json.dumps(raw_row, ensure_ascii=False),
                now
            ))
            cursor.close()
            return "INSERT"

    # ── Geocodificacao ────────────────────────────────────────────────────────
    def _geocode(self, geo, nome, mun, uf):
        if not HAS_GEOPY or geo is None:
            return "geopy nao instalado", "SKIP", "", ""
        try:
            time.sleep(1.1)
            loc = geo.geocode(f"{nome}, {mun}, {uf}, Brasil", timeout=10)
            if loc:
                lat_s = f"{loc.latitude:.5f}"
                lon_s = f"{loc.longitude:.5f}"
                self.after(0, self._log,
                           f"GEO {nome} -> {lat_s}, {lon_s}", SUCCESS)
                return f"Geocodificada: {lat_s},{lon_s}", "OK", lat_s, lon_s
            self.after(0, self._log, f"AVISO {nome} — sem resultado", WARNING)
            return "Sem resultado", "SEM COORDS", "", ""
        except GeocoderTimedOut:
            self.after(0, self._log, f"TIMEOUT {nome}", WARNING)
            return "Timeout", "TIMEOUT", "", ""
        except Exception as e:
            self.after(0, self._log, f"ERRO {nome}: {e}", ERROR)
            return f"Erro: {e}", "ERRO", "", ""

    # ── Leitura CSV ───────────────────────────────────────────────────────────
    def _read_csv(self, path):
        if HAS_PANDAS:
            df = pd.read_csv(path, sep=None, engine="python",
                             encoding="utf-8", on_bad_lines="skip")
            return df.fillna("").to_dict(orient="records")
        with open(path, newline="", encoding="utf-8") as f:
            return list(csv.DictReader(f))

    # ── Exportar ──────────────────────────────────────────────────────────────
    def _export(self):
        if not self._records:
            messagebox.showinfo("Exportar", "Nenhum registro para exportar.")
            return
        path = filedialog.asksaveasfilename(
            defaultextension=".csv",
            filetypes=[("CSV", "*.csv"), ("Excel", "*.xlsx")])
        if not path: return
        try:
            if path.endswith(".xlsx") and HAS_PANDAS:
                df = pd.DataFrame(self._records)
                df.columns = ["Escola", "Acao", "DB", "Status", "Horario", "Lat", "Lon"]
                df.to_excel(path, index=False)
            else:
                with open(path, "w", newline="", encoding="utf-8") as f:
                    w = csv.DictWriter(f, fieldnames=["escola","acao","db","status","ts","lat","lon"])
                    w.writeheader()
                    w.writerows(self._records)
            self._log(f"Exportado: {os.path.basename(path)}", SUCCESS)
            messagebox.showinfo("Exportar", f"Salvo em:\n{path}")
        except Exception as e:
            messagebox.showerror("Erro", str(e))

    # ── Finalizar ─────────────────────────────────────────────────────────────
    def _finish(self):
        self._running   = False
        self._db_conn   = None
        self.after(0, lambda: self._btn_start.config(state="normal"))
        self.after(0, lambda: self._btn_stop.config(state="disabled"))


if __name__ == "__main__":
    app = SchoolImportApp()
    app.mainloop()
