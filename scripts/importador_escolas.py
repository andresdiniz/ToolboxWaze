
import tkinter as tk
from tkinter import ttk, filedialog, messagebox
import threading
import pandas as pd
import time
import os
from datetime import datetime
from geopy.geocoders import Nominatim
from geopy.exc import GeocoderTimedOut, GeocoderServiceError

# ─── Paleta de cores ────────────────────────────────────────────────────────
BG       = "#1e1e2e"
SURFACE  = "#2a2a3e"
SURFACE2 = "#313145"
ACCENT   = "#4f98a3"
SUCCESS  = "#6daa45"
WARNING  = "#e8af34"
ERROR    = "#dd6974"
TEXT     = "#cdccca"
MUTED    = "#797876"
WHITE    = "#f0efed"
FONT_BODY  = ("Segoe UI", 10)
FONT_BOLD  = ("Segoe UI", 10, "bold")
FONT_TITLE = ("Segoe UI", 13, "bold")
FONT_MONO  = ("Consolas", 9)

# Colunas candidatas para ID da escola (ordem de prioridade)
ID_COLS = ["CO_ENTIDADE", "CO_ESCOLA", "ID_ESCOLA", "CO_INEP", "ID"]


def extrair_id(row: dict) -> str:
    """Retorna o primeiro ID encontrado nas colunas candidatas, ou '—'."""
    for col in ID_COLS:
        val = str(row.get(col, "")).strip()
        if val and val.lower() not in ("nan", "none", ""):
            return val
    return "—"


# ─── Geocodificador com estratégia em camadas ────────────────────────────────
class GeocoderCamadas:
    def __init__(self):
        self.geo = Nominatim(user_agent="escola_geocoder_br/1.0", timeout=8)
        self._delay = 1.1  # respeita rate-limit Nominatim

    def _tentar(self, query: str):
        try:
            time.sleep(self._delay)
            loc = self.geo.geocode(query, country_codes="br", language="pt")
            return (loc.latitude, loc.longitude, query) if loc else None
        except GeocoderTimedOut:
            return None
        except GeocoderServiceError:
            return None

    def geocodificar(self, row: dict):
        """
        Estratégia em 3 camadas:
          1. Endereço completo + município + UF
          2. Nome da escola + município + UF
          3. Centroide do município
        """
        municipio = str(row.get("NO_MUNICIPIO", "")).strip()
        uf        = str(row.get("SG_UF", "")).strip()
        nome      = str(row.get("NO_ENTIDADE", "")).strip()
        logr      = str(row.get("DS_ENDERECO", "")).strip()
        numero    = str(row.get("NU_ENDERECO", "")).strip()
        bairro    = str(row.get("NO_BAIRRO",   "")).strip()

        # Camada 1 — endereço completo
        partes = [p for p in [logr, numero, bairro, municipio, uf, "Brasil"] if p and p != "nan"]
        res = self._tentar(", ".join(partes))
        if res:
            return {"lat": res[0], "lon": res[1], "estrategia": "endereco_completo", "status": "ok"}

        # Camada 2 — nome da escola + município + UF
        res = self._tentar(f"{nome}, {municipio}, {uf}, Brasil")
        if res:
            return {"lat": res[0], "lon": res[1], "estrategia": "nome_escola", "status": "ok"}

        # Camada 3 — centroide do município
        res = self._tentar(f"{municipio}, {uf}, Brasil")
        if res:
            return {"lat": res[0], "lon": res[1], "estrategia": "centroide_municipio", "status": "parcial"}

        return {"lat": None, "lon": None, "estrategia": "—", "status": "sem_coords"}


# ─── Janela principal ────────────────────────────────────────────────────────
class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("Importador de Escolas — Geocodificação em Camadas")
        self.geometry("1160x740")
        self.minsize(820, 560)
        self.configure(bg=BG)
        self.resizable(True, True)

        self._df_result = pd.DataFrame()
        self._running   = False
        self._stop_flag = threading.Event()
        self._geocoder  = GeocoderCamadas()

        self._build_ui()
        self._log("Sistema iniciado. Aguardando arquivo CSV.", "info")

    # ── UI ──────────────────────────────────────────────────────────────────
    def _build_ui(self):
        tk.Label(self, text="📍 Importador de Escolas com Geocodificação",
                 bg=BG, fg=ACCENT, font=FONT_TITLE).pack(pady=(14, 4))
        tk.Label(self, text="Estratégia em camadas: endereço completo → nome+município → centroide",
                 bg=BG, fg=MUTED, font=FONT_BODY).pack(pady=(0, 10))

        # Barra de arquivo
        ff = tk.Frame(self, bg=SURFACE, padx=10, pady=8)
        ff.pack(fill="x", padx=16, pady=(0, 8))
        tk.Label(ff, text="Arquivo CSV:", bg=SURFACE, fg=TEXT, font=FONT_BOLD).pack(side="left")
        self._path_var = tk.StringVar(value="Nenhum arquivo selecionado")
        tk.Label(ff, textvariable=self._path_var, bg=SURFACE, fg=MUTED,
                 font=FONT_BODY, anchor="w").pack(side="left", fill="x", expand=True, padx=8)
        self._btn_browse = self._btn(ff, "📂 Abrir CSV", self._browse, ACCENT)
        self._btn_browse.pack(side="right")

        # Progresso
        pf = tk.Frame(self, bg=BG)
        pf.pack(fill="x", padx=16, pady=(0, 6))
        self._prog_label = tk.Label(pf, text="Aguardando...", bg=BG, fg=MUTED, font=FONT_BODY)
        self._prog_label.pack(anchor="w")
        style = ttk.Style(self)
        style.theme_use("clam")
        style.configure("Geo.Horizontal.TProgressbar",
                        troughcolor=SURFACE2, background=ACCENT,
                        bordercolor=SURFACE2, lightcolor=ACCENT, darkcolor=ACCENT)
        self._progress = ttk.Progressbar(pf, style="Geo.Horizontal.TProgressbar",
                                          orient="horizontal", mode="determinate")
        self._progress.pack(fill="x", pady=(2, 0))

        # Botões
        bf = tk.Frame(self, bg=BG)
        bf.pack(fill="x", padx=16, pady=(0, 8))
        self._btn_start  = self._btn(bf, "▶  Iniciar",  self._start,  SUCCESS)
        self._btn_stop   = self._btn(bf, "⏹  Parar",    self._stop,   ERROR,   state="disabled")
        self._btn_export = self._btn(bf, "💾 Exportar", self._export, WARNING, state="disabled")
        self._btn_clear  = self._btn(bf, "🗑 Limpar",   self._clear,  MUTED)
        for b in [self._btn_start, self._btn_stop, self._btn_export, self._btn_clear]:
            b.pack(side="left", padx=4)

        # Painel divisível
        paned = tk.PanedWindow(self, orient="vertical", bg=SURFACE2, sashwidth=5, sashrelief="flat")
        paned.pack(fill="both", expand=True, padx=16, pady=(0, 14))

        # Log
        lf = tk.Frame(paned, bg=SURFACE)
        tk.Label(lf, text=" Log de Execução", bg=SURFACE2, fg=ACCENT,
                 font=FONT_BOLD, anchor="w").pack(fill="x")
        self._log_text = tk.Text(lf, bg=SURFACE, fg=TEXT, font=FONT_MONO,
                                  state="disabled", wrap="word", relief="flat",
                                  insertbackground=TEXT, selectbackground=ACCENT)
        sb_l = tk.Scrollbar(lf, command=self._log_text.yview, bg=SURFACE2)
        self._log_text.configure(yscrollcommand=sb_l.set)
        sb_l.pack(side="right", fill="y")
        self._log_text.pack(fill="both", expand=True, padx=4, pady=4)
        for tag, fg in [("ok", SUCCESS), ("warn", WARNING), ("error", ERROR),
                        ("info", TEXT), ("accent", ACCENT), ("muted", MUTED),
                        ("id", "#c9a0dc")]:
            self._log_text.tag_config(tag, foreground=fg)
        paned.add(lf, minsize=140)

        # Tabela
        tf = tk.Frame(paned, bg=SURFACE)
        tk.Label(tf, text=" Resultados", bg=SURFACE2, fg=ACCENT,
                 font=FONT_BOLD, anchor="w").pack(fill="x")
        cols = ("ID INEP", "Escola", "Município", "UF", "Estratégia", "Lat", "Lon", "Status")
        self._tree = ttk.Treeview(tf, columns=cols, show="headings", height=8)
        style.configure("Treeview", background=SURFACE, foreground=TEXT,
                         fieldbackground=SURFACE, rowheight=22, font=FONT_BODY)
        style.configure("Treeview.Heading", background=SURFACE2,
                         foreground=ACCENT, font=FONT_BOLD)
        style.map("Treeview", background=[("selected", ACCENT)],
                   foreground=[("selected", WHITE)])
        widths = {"ID INEP": 90, "Escola": 250, "Município": 120, "UF": 40,
                  "Estratégia": 155, "Lat": 100, "Lon": 100, "Status": 90}
        anchors = {"ID INEP": "center", "Escola": "w", "Município": "w",
                   "UF": "center", "Estratégia": "w",
                   "Lat": "center", "Lon": "center", "Status": "center"}
        for c in cols:
            self._tree.heading(c, text=c)
            self._tree.column(c, width=widths[c], anchor=anchors[c])
        self._tree.tag_configure("ok",      foreground=SUCCESS)
        self._tree.tag_configure("parcial", foreground=WARNING)
        self._tree.tag_configure("sem",     foreground=ERROR)
        sb_tv = ttk.Scrollbar(tf, orient="vertical",   command=self._tree.yview)
        sb_tx = ttk.Scrollbar(tf, orient="horizontal", command=self._tree.xview)
        self._tree.configure(yscrollcommand=sb_tv.set, xscrollcommand=sb_tx.set)
        sb_tv.pack(side="right", fill="y")
        sb_tx.pack(side="bottom", fill="x")
        self._tree.pack(fill="both", expand=True, padx=4, pady=4)
        paned.add(tf, minsize=120)

    def _btn(self, parent, text, cmd, color, state="normal"):
        return tk.Button(parent, text=text, command=cmd, bg=color, fg=WHITE,
                         font=FONT_BOLD, relief="flat", padx=14, pady=6,
                         activebackground=ACCENT, activeforeground=WHITE,
                         cursor="hand2", state=state)

    # ── Log ─────────────────────────────────────────────────────────────────
    def _log(self, msg: str, kind: str = "info"):
        ts = datetime.now().strftime("%H:%M:%S")
        icon = {"ok":"✔","warn":"⚠","error":"✖","info":"ℹ","accent":"►","muted":"·","id":"#"}.get(kind,"·")
        line = f"[{ts}] {icon}  {msg}\n"
        self._log_text.configure(state="normal")
        self._log_text.insert("end", line, kind)
        self._log_text.see("end")
        self._log_text.configure(state="disabled")

    def _log_escola(self, idx: int, total: int, escola_id: str, nome: str, kind: str, detalhe: str):
        """Log padronizado com ID INEP destacado em roxo claro."""
        ts   = datetime.now().strftime("%H:%M:%S")
        icon = {"ok":"✔","warn":"⚠","error":"✖","accent":"►"}.get(kind,"·")
        linha = (
            f"[{ts}] {icon}  [{idx:>5}/{total}] "
            f"ID {escola_id:<8}  {nome[:45]:<45}  {detalhe}\n"
        )
        self._log_text.configure(state="normal")
        self._log_text.insert("end", linha, kind)
        # Destaca "ID XXXXXXXX" em roxo claro
        full_text = self._log_text.get("end - 2l", "end - 1l")
        start_idx = full_text.find(f"ID {escola_id}")
        if start_idx != -1:
            line_start = self._log_text.index("end - 2l")
            tag_start  = f"{line_start} + {start_idx} chars"
            tag_end    = f"{line_start} + {start_idx + len('ID ') + len(escola_id)} chars"
            self._log_text.tag_add("id", tag_start, tag_end)
        self._log_text.see("end")
        self._log_text.configure(state="disabled")

    # ── Arquivo ─────────────────────────────────────────────────────────────
    def _browse(self):
        path = filedialog.askopenfilename(filetypes=[("CSV", "*.csv"), ("Todos", "*.*")])
        if path:
            self._path_var.set(path)
            self._log(f"Arquivo selecionado: {os.path.basename(path)}", "accent")

    # ── Importação ──────────────────────────────────────────────────────────
    def _start(self):
        path = self._path_var.get()
        if not os.path.isfile(path):
            messagebox.showerror("Erro", "Selecione um arquivo CSV válido.")
            return
        self._stop_flag.clear()
        self._running = True
        self._btn_start.config(state="disabled")
        self._btn_stop.config(state="normal")
        self._btn_export.config(state="disabled")
        for item in self._tree.get_children():
            self._tree.delete(item)
        threading.Thread(target=self._run, args=(path,), daemon=True).start()

    def _stop(self):
        self._stop_flag.set()
        self._log("Interrupção solicitada — aguardando escola atual...", "warn")

    def _run(self, path: str):
        try:
            df = pd.read_csv(path, sep=";", encoding="latin-1", low_memory=False, dtype=str)
            df.columns = [c.strip() for c in df.columns]
        except Exception as e:
            self._log(f"Erro ao ler CSV: {e}", "error")
            self._finish()
            return

        total = len(df)
        self._log(f"CSV carregado — {total} registros | colunas: {list(df.columns[:6])}...", "info")
        self._progress["maximum"] = total

        resultados = []
        stats = {"ok": 0, "parcial": 0, "sem_coords": 0, "ignorado": 0}

        for i, (_, row) in enumerate(df.iterrows(), 1):
            if self._stop_flag.is_set():
                self._log("Processamento interrompido pelo usuário.", "warn")
                break

            row_dict  = row.to_dict()
            escola_id = extrair_id(row_dict)
            nome      = str(row.get("NO_ENTIDADE", f"Escola #{i}")).strip()
            municipio = str(row.get("NO_MUNICIPIO", "")).strip()
            uf        = str(row.get("SG_UF", "")).strip()
            situacao  = str(row.get("TP_SITUACAO_FUNCIONAMENTO", "1")).strip()

            self.after(0, self._update_prog, i, total, int(i / total * 100), escola_id, nome)

            # Já tem coordenadas salvas?
            try:
                lat = float(str(row.get("NU_LATITUDE",  "")).replace(",", "."))
                lon = float(str(row.get("NU_LONGITUDE", "")).replace(",", "."))
                if lat and lon:
                    res = {"lat": lat, "lon": lon, "estrategia": "coords_existentes", "status": "ok"}
                    stats["ok"] += 1
                    self._log_escola(i, total, escola_id, nome, "ok",
                                     f"coords existentes → {lat:.5f}, {lon:.5f}")
                    self._add_row(escola_id, nome, municipio, uf, res)
                    resultados.append({**row_dict, **res})
                    if i % 50 == 0:
                        self._log_resumo(i, total, stats)
                    continue
            except (ValueError, TypeError):
                pass

            # Paralisada / extinta — ignora geocodificação
            if situacao != "1":
                res = {"lat": None, "lon": None, "estrategia": "—", "status": "ignorado"}
                stats["ignorado"] += 1
                self._log_escola(i, total, escola_id, nome, "muted",
                                 f"situação={situacao} → ignorado")
                resultados.append({**row_dict, **res})
                continue

            # Geocodificação em camadas
            self._log_escola(i, total, escola_id, nome, "accent", "geocodificando...")
            res = self._geocoder.geocodificar(row_dict)

            if res["status"] == "ok":
                stats["ok"] += 1
                self._log_escola(i, total, escola_id, nome, "ok",
                                 f"✔ {res['estrategia']} → {res['lat']:.5f}, {res['lon']:.5f}")
            elif res["status"] == "parcial":
                stats["parcial"] += 1
                self._log_escola(i, total, escola_id, nome, "warn",
                                 f"⚠ centroide município → {res['lat']:.5f}, {res['lon']:.5f}")
            else:
                stats["sem_coords"] += 1
                self._log_escola(i, total, escola_id, nome, "error",
                                 "✖ sem_coords — revisar manualmente")

            self._add_row(escola_id, nome, municipio, uf, res)
            resultados.append({**row_dict, **res})

            if i % 50 == 0:
                self._log_resumo(i, total, stats)

        self._df_result = pd.DataFrame(resultados)
        self._log("─" * 70, "muted")
        self._log(
            f"Concluído! {total} registros | "
            f"✔ ok={stats['ok']}  ⚠ parcial={stats['parcial']}  "
            f"✖ sem_coords={stats['sem_coords']}  · ignorados={stats['ignorado']}",
            "ok"
        )
        self._finish()

    def _log_resumo(self, i, total, stats):
        self._log(
            f"── {i}/{total} processados | "
            f"ok={stats['ok']}  parcial={stats['parcial']}  "
            f"sem={stats['sem_coords']}  ignorado={stats['ignorado']}",
            "muted"
        )

    def _update_prog(self, i, total, pct, escola_id, nome):
        self._progress["value"] = i
        self._prog_label.config(
            text=f"{i}/{total} ({pct}%)  ID {escola_id}  —  {nome[:50]}",
            fg=ACCENT
        )

    def _add_row(self, escola_id, nome, municipio, uf, res):
        tag = {"ok": "ok", "parcial": "parcial",
               "sem_coords": "sem", "ignorado": "sem"}.get(res["status"], "sem")
        lat = f"{res['lat']:.6f}" if res["lat"] else "—"
        lon = f"{res['lon']:.6f}" if res["lon"] else "—"
        self.after(0, self._tree.insert, "", "end",
                   values=(escola_id, nome[:50], municipio, uf,
                            res["estrategia"], lat, lon, res["status"]),
                   tags=(tag,))

    def _finish(self):
        self._running = False
        self.after(0, lambda: self._btn_start.config(state="normal"))
        self.after(0, lambda: self._btn_stop.config(state="disabled"))
        if not self._df_result.empty:
            self.after(0, lambda: self._btn_export.config(state="normal"))
        self.after(0, lambda: self._prog_label.config(text="Concluído.", fg=SUCCESS))

    # ── Exportar ────────────────────────────────────────────────────────────
    def _export(self):
        if self._df_result.empty:
            messagebox.showinfo("Aviso", "Nenhum dado para exportar.")
            return
        path = filedialog.asksaveasfilename(
            defaultextension=".xlsx",
            filetypes=[("Excel", "*.xlsx"), ("CSV", "*.csv")],
            initialfile=f"escolas_{datetime.now().strftime('%Y%m%d_%H%M')}"
        )
        if not path:
            return
        try:
            if path.endswith(".csv"):
                self._df_result.to_csv(path, index=False, sep=";", encoding="utf-8-sig")
            else:
                with pd.ExcelWriter(path, engine="openpyxl") as writer:
                    self._df_result.to_excel(writer, index=False, sheet_name="Escolas")
                    wb = writer.book
                    ws = wb["Escolas"]
                    from openpyxl.styles import PatternFill, Font, Alignment
                    hf    = PatternFill("solid", fgColor="1F3864")
                    hfont = Font(bold=True, color="FFFFFF", name="Calibri")
                    for cell in ws[1]:
                        cell.fill = hf
                        cell.font = hfont
                        cell.alignment = Alignment(horizontal="center", vertical="center")
                    ws.freeze_panes = "A2"
                    ws.auto_filter.ref = ws.dimensions
                    for col in ws.columns:
                        w = max(len(str(c.value or "")) for c in col) + 3
                        ws.column_dimensions[col[0].column_letter].width = min(w, 50)
            self._log(f"Exportado com sucesso: {os.path.basename(path)}", "ok")
        except Exception as e:
            self._log(f"Erro ao exportar: {e}", "error")

    # ── Limpar ──────────────────────────────────────────────────────────────
    def _clear(self):
        self._log_text.configure(state="normal")
        self._log_text.delete("1.0", "end")
        self._log_text.configure(state="disabled")
        for item in self._tree.get_children():
            self._tree.delete(item)
        self._df_result = pd.DataFrame()
        self._progress["value"] = 0
        self._prog_label.config(text="Aguardando...", fg=MUTED)
        self._btn_export.config(state="disabled")
        self._log("Log e tabela limpos.", "muted")


if __name__ == "__main__":
    app = App()
    app.mainloop()
