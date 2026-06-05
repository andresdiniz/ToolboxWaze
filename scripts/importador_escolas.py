import tkinter as tk
from tkinter import ttk, filedialog, messagebox
import threading
import csv
import os
import time
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

# ── Paleta ─────────────────────────────────────────────────────────────────────
BG         = "#1e1e2e"
SURFACE    = "#2a2a3d"
SURFACE2   = "#313145"
ACCENT     = "#7c3aed"
ACCENT2    = "#a78bfa"
SUCCESS    = "#22c55e"
WARNING    = "#f59e0b"
ERROR      = "#ef4444"
TEXT       = "#e2e8f0"
TEXT_MUTED = "#94a3b8"
BORDER     = "#3f3f5c"


class SchoolImportApp(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("Importador de Escolas - Logs por Escola")
        self.geometry("1100x720")
        self.minsize(900, 580)
        self.configure(bg=BG)
        self.resizable(True, True)

        self._stop_flag  = False
        self._running    = False
        self._records    = []
        self._file_path  = tk.StringVar(value="Nenhum arquivo selecionado")
        self._progress   = tk.DoubleVar(value=0)
        self._prog_label = tk.StringVar(value="Aguardando...")

        self._build_ui()
        self._check_deps()

    # ── Deps ────────────────────────────────────────────────────────────────
    def _check_deps(self):
        missing = []
        if not HAS_PANDAS: missing.append("pandas")
        if not HAS_GEOPY:  missing.append("geopy")
        if missing:
            self._log("Instale as dependencias: pip install " + " ".join(missing), WARNING)
        else:
            self._log("Todas as dependencias encontradas. Pronto para usar.", SUCCESS)

    # ── UI ──────────────────────────────────────────────────────────────────
    def _build_ui(self):
        # Cabecalho
        hdr = tk.Frame(self, bg=SURFACE, pady=12)
        hdr.pack(fill="x")
        tk.Label(hdr, text="Importador de Escolas",
                 font=("Segoe UI", 16, "bold"),
                 bg=SURFACE, fg=ACCENT2).pack(side="left", padx=18)
        tk.Label(hdr, text="MEC / Geocodificacao via Nominatim",
                 font=("Segoe UI", 10), bg=SURFACE, fg=TEXT_MUTED).pack(side="left")

        # Barra de arquivo
        fb = tk.Frame(self, bg=SURFACE2, pady=8, padx=12)
        fb.pack(fill="x", pady=(1, 0))
        tk.Button(fb, text="Escolher CSV", command=self._pick_file,
                  bg=ACCENT, fg="white", relief="flat",
                  font=("Segoe UI", 9, "bold"), padx=12, pady=4,
                  cursor="hand2").pack(side="left")
        tk.Label(fb, textvariable=self._file_path, bg=SURFACE2, fg=TEXT_MUTED,
                 font=("Segoe UI", 9)).pack(side="left", padx=14)

        # Botoes
        ctrl = tk.Frame(self, bg=BG, pady=8, padx=12)
        ctrl.pack(fill="x")
        self._btn_start = tk.Button(ctrl, text="Iniciar", command=self._start,
            bg=SUCCESS, fg="white", relief="flat",
            font=("Segoe UI", 9, "bold"), padx=14, pady=5, cursor="hand2")
        self._btn_start.pack(side="left", padx=(0, 6))
        self._btn_stop = tk.Button(ctrl, text="Parar", command=self._stop,
            bg=ERROR, fg="white", relief="flat",
            font=("Segoe UI", 9, "bold"), padx=14, pady=5,
            cursor="hand2", state="disabled")
        self._btn_stop.pack(side="left", padx=(0, 6))
        tk.Button(ctrl, text="Exportar", command=self._export,
            bg=SURFACE, fg=TEXT, relief="flat",
            font=("Segoe UI", 9), padx=14, pady=5,
            cursor="hand2").pack(side="left", padx=(0, 6))
        tk.Button(ctrl, text="Limpar", command=self._clear,
            bg=SURFACE, fg=TEXT_MUTED, relief="flat",
            font=("Segoe UI", 9), padx=14, pady=5,
            cursor="hand2").pack(side="left")
        tk.Label(ctrl, textvariable=self._prog_label,
                 bg=BG, fg=TEXT_MUTED,
                 font=("Segoe UI", 9)).pack(side="right", padx=8)

        # Barra de progresso
        pf = tk.Frame(self, bg=BG, padx=12)
        pf.pack(fill="x")
        sty = ttk.Style(self)
        sty.theme_use("default")
        sty.configure("P.Horizontal.TProgressbar",
                      troughcolor=SURFACE, background=ACCENT2,
                      thickness=8, borderwidth=0)
        ttk.Progressbar(pf, variable=self._progress, maximum=100,
                        style="P.Horizontal.TProgressbar").pack(fill="x", pady=(0, 8))

        # PanedWindow
        paned = tk.PanedWindow(self, orient="vertical",
                               bg=BORDER, sashwidth=5, sashrelief="flat")
        paned.pack(fill="both", expand=True, padx=12, pady=(0, 12))

        # Log
        lf = tk.Frame(paned, bg=SURFACE, bd=0)
        paned.add(lf, minsize=160)
        tk.Label(lf, text="Log de Execucao", font=("Segoe UI", 9, "bold"),
                 bg=SURFACE, fg=TEXT_MUTED, pady=4).pack(anchor="w", padx=8)
        ls = tk.Scrollbar(lf, bg=SURFACE, troughcolor=SURFACE2)
        ls.pack(side="right", fill="y")
        self._log_box = tk.Text(lf, bg=SURFACE, fg=TEXT,
                                font=("Consolas", 9),
                                insertbackground=TEXT, wrap="word",
                                relief="flat", padx=8, pady=4,
                                yscrollcommand=ls.set, state="disabled")
        self._log_box.pack(fill="both", expand=True)
        ls.config(command=self._log_box.yview)
        self._log_box.tag_config("success", foreground=SUCCESS)
        self._log_box.tag_config("warning", foreground=WARNING)
        self._log_box.tag_config("error",   foreground=ERROR)
        self._log_box.tag_config("accent",  foreground=ACCENT2)
        self._log_box.tag_config("muted",   foreground=TEXT_MUTED)

        # Tabela
        tf = tk.Frame(paned, bg=SURFACE, bd=0)
        paned.add(tf, minsize=120)
        tk.Label(tf, text="Registros por Escola", font=("Segoe UI", 9, "bold"),
                 bg=SURFACE, fg=TEXT_MUTED, pady=4).pack(anchor="w", padx=8)
        cols = ("Escola", "Acao Realizada", "Status", "Horario")
        sy = tk.Scrollbar(tf)
        sy.pack(side="right", fill="y")
        sx = tk.Scrollbar(tf, orient="horizontal")
        sx.pack(side="bottom", fill="x")
        sty.configure("D.Treeview",
                      background=SURFACE2, foreground=TEXT,
                      fieldbackground=SURFACE2, borderwidth=0, rowheight=22)
        sty.configure("D.Treeview.Heading",
                      background=SURFACE, foreground=ACCENT2,
                      relief="flat", font=("Segoe UI", 9, "bold"))
        sty.map("D.Treeview",
                background=[("selected", ACCENT)],
                foreground=[("selected", "white")])
        self._tree = ttk.Treeview(tf, columns=cols, show="headings",
                                  style="D.Treeview",
                                  yscrollcommand=sy.set,
                                  xscrollcommand=sx.set)
        for col, w in zip(cols, [290, 220, 120, 110]):
            self._tree.heading(col, text=col)
            self._tree.column(col, width=w, anchor="w")
        self._tree.pack(fill="both", expand=True)
        sy.config(command=self._tree.yview)
        sx.config(command=self._tree.xview)

    # ── Log / Tabela ─────────────────────────────────────────────────────────
    def _log(self, msg: str, color: str = TEXT):
        tags = {SUCCESS: "success", WARNING: "warning", ERROR: "error",
                ACCENT2: "accent", TEXT_MUTED: "muted"}
        ts = datetime.now().strftime("%H:%M:%S")
        self._log_box.configure(state="normal")
        self._log_box.insert("end", f"[{ts}]  {msg}\n", tags.get(color, ""))
        self._log_box.see("end")
        self._log_box.configure(state="disabled")

    def _add_record(self, escola, acao, status):
        ts = datetime.now().strftime("%H:%M:%S")
        self._records.append({"escola": escola, "acao": acao,
                               "status": status, "ts": ts})
        tag = "success" if status == "OK" else ("error" if status == "ERRO" else "")
        self._tree.insert("", "end", values=(escola, acao, status, ts), tags=(tag,))
        self._tree.tag_configure("success", foreground=SUCCESS)
        self._tree.tag_configure("error",   foreground=ERROR)
        self._tree.yview_moveto(1)

    def _set_progress(self, pct, label):
        self._progress.set(pct)
        self._prog_label.set(label)

    # ── Arquivo ──────────────────────────────────────────────────────────────
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
        if self._running:
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

        self.after(0, self._log, f"Iniciando: {total} escola(s)...", ACCENT2)
        geo = Nominatim(user_agent="school_importer_v1") if HAS_GEOPY else None

        for i, row in enumerate(rows, 1):
            if self._stop_flag:
                self.after(0, self._log, "Processamento interrompido.", WARNING)
                break

            nome   = row.get("NO_ENTIDADE") or row.get("nome") or f"Escola #{i}"
            status = str(row.get("TP_SITUACAO_FUNCIONAMENTO") or row.get("situacao") or "1")
            lat    = row.get("NU_LATITUDE")  or row.get("lat")  or ""
            lon    = row.get("NU_LONGITUDE") or row.get("lon")  or ""
            uf     = row.get("SG_UF")        or row.get("uf")   or ""
            mun    = row.get("NO_MUNICIPIO") or row.get("municipio") or ""

            if lat and lon:
                acao, st = f"Coords presentes ({lat}, {lon})", "OK"
                self.after(0, self._log, f"OK  {nome} — {lat}, {lon}", SUCCESS)
            elif status != "1":
                acao, st = f"Ignorada (situacao={status})", "IGNORADA"
                self.after(0, self._log, f"--  {nome} — ignorada", TEXT_MUTED)
            else:
                acao, st = self._geocode(geo, nome, mun, uf)

            self.after(0, self._add_record, nome, acao, st)
            pct = (i / total) * 100
            self.after(0, self._set_progress, pct,
                       f"{i} / {total}  ({pct:.1f}%)")
            if i % 50 == 0:
                self.after(0, self._log,
                           f"Progresso: {i}/{total} processadas", TEXT_MUTED)
            time.sleep(0.02)

        self.after(0, self._log,
                   f"Concluido! {len(self._records)} registros.", SUCCESS)
        self._finish()

    # ── Geocodificacao ────────────────────────────────────────────────────────
    def _geocode(self, geo, nome, mun, uf):
        if not HAS_GEOPY or geo is None:
            return "geopy nao instalado", "SKIP"
        try:
            time.sleep(1.1)
            loc = geo.geocode(f"{nome}, {mun}, {uf}, Brasil", timeout=10)
            if loc:
                self.after(0, self._log,
                           f"GEO {nome} -> {loc.latitude:.5f}, {loc.longitude:.5f}",
                           SUCCESS)
                return f"Geocodificada: {loc.latitude:.5f},{loc.longitude:.5f}", "OK"
            self.after(0, self._log, f"AVISO {nome} — sem resultado", WARNING)
            return "Sem resultado", "SEM COORDS"
        except GeocoderTimedOut:
            self.after(0, self._log, f"TIMEOUT {nome}", WARNING)
            return "Timeout", "TIMEOUT"
        except Exception as e:
            self.after(0, self._log, f"ERRO {nome}: {e}", ERROR)
            return f"Erro: {e}", "ERRO"

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
        if not path:
            return
        try:
            if path.endswith(".xlsx") and HAS_PANDAS:
                df = pd.DataFrame(self._records)
                df.columns = ["Escola", "Acao Realizada", "Status", "Horario"]
                df.to_excel(path, index=False)
            else:
                with open(path, "w", newline="", encoding="utf-8") as f:
                    w = csv.DictWriter(f,
                        fieldnames=["escola", "acao", "status", "ts"])
                    w.writeheader()
                    w.writerows(self._records)
            self._log(f"Exportado: {os.path.basename(path)}", SUCCESS)
            messagebox.showinfo("Exportar", f"Salvo em:\n{path}")
        except Exception as e:
            messagebox.showerror("Erro", str(e))

    # ── Finalizar ─────────────────────────────────────────────────────────────
    def _finish(self):
        self._running = False
        self.after(0, lambda: self._btn_start.config(state="normal"))
        self.after(0, lambda: self._btn_stop.config(state="disabled"))


if __name__ == "__main__":
    app = SchoolImportApp()
    app.mainloop()
