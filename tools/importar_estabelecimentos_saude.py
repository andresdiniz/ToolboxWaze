#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""ToolboxWaze - Importador CNES 1.3. Python 3.10+. pip install pymysql"""
import csv
import io
import json
import os
import queue
import re
import sys
import threading
import traceback
import unicodedata
from datetime import datetime
from pathlib import Path

UFS=set('AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'.split())
VAZIOS={'','-','N/A','NULL','NAN','NONE'}
COMP=re.compile(r'^\d{4}/(0[1-9]|1[0-2])$')
TABELA='estabelecimento_saude'

class Cancelado(Exception):pass
class ResultadoIncerto(Exception):pass


def nome(d):
    return d.get('NOME FANTASIA') or d.get('RAZÃO SOCIAL') or 'Estabelecimento CNES '+d['CNES']


def objeto(v,correcoes=False):
    if isinstance(v,(bytes,bytearray)):v=v.decode('utf-8')
    if isinstance(v,str):v=json.loads(v)
    if correcoes and v==[]:return {}
    if not isinstance(v,dict):raise ValueError('JSON invalido no banco.')
    return v


def preparar_config(valores):
    # Recebe somente strings capturadas pela thread principal.
    cfg={k:(str(v) if k=='password' else str(v).strip()) for k,v in valores.items()}
    cfg['port']=int(cfg['port'])
    if not 1<=cfg['port']<=65535:raise ValueError('Porta invalida.')
    if not all(cfg.get(k) for k in ('host','user','database')):raise ValueError('Preencha host, usuario e banco.')
    if not cfg.get('password'):raise ValueError('Digite a senha do MySQL antes de importar.')
    if cfg.get('ssl_ca') and not Path(cfg['ssl_ca']).is_file():raise ValueError('CA nao encontrado. Deixe vazio se nao utilizar.')
    return cfg


def ler_csv(path,stop):
    bruto=Path(path).read_bytes()
    for enc in ('utf-8-sig','cp1252','latin-1'):
        try:texto=bruto.decode(enc);break
        except UnicodeDecodeError:continue
    if not texto.strip():raise ValueError('Arquivo vazio.')
    sep=max(('\t',';',','),key=texto.splitlines()[0].count)
    if not texto.splitlines()[0].count(sep):raise ValueError('Separador nao identificado.')
    reader=csv.reader(io.StringIO(texto),delimiter=sep,strict=True)
    header=[unicodedata.normalize('NFC',c.strip()) for c in next(reader)]
    indices=[i for i,c in enumerate(header) if c]
    names=[header[i] for i in indices]
    if len(names)!=len(set(names)):raise ValueError('Cabecalhos duplicados.')
    req={'CNES','UF','NOME FANTASIA','MUNICÍPIO','STATUS DO ESTABELECIMENTO'}
    if not req.issubset(names):raise ValueError('Colunas ausentes: '+', '.join(sorted(req-set(names))))
    records,errors={},[]
    for row in reader:
        if stop.is_set():raise Cancelado('Validacao cancelada. Nada gravado.')
        if not any(v.strip() for v in row):continue
        try:
            if len(row)<=max(indices):raise ValueError('Linha incompleta.')
            if any(v.strip() for i,v in enumerate(row) if i not in indices):raise ValueError('Conteudo em coluna excedente ou sem nome.')
            d={header[i]:row[i].strip() for i in indices}
            d={k:'' if v.upper() in VAZIOS else v for k,v in d.items()}
            if not re.fullmatch(r'\d{1,7}',d['CNES']):raise ValueError('CNES invalido.')
            d['CNES']=d['CNES'].zfill(7);d['UF']=d['UF'].upper()
            if d['UF'] not in UFS:raise ValueError('UF invalida.')
            if d.get('location'):
                p=re.split(r'[\s;,]+',d['location'].strip())
                if len(p)!=2:raise ValueError('Location deve conter latitude e longitude.')
                lat,lon=map(float,p)
                if not (-90<=lat<=90 and -180<=lon<=180):raise ValueError('Coordenadas invalidas.')
                d['location']=f'{lat:.8f} {lon:.8f}'
            c=d['CNES']
            if c in records and records[c]!=d:raise ValueError('CNES duplicado com dados divergentes: '+c)
            records[c]=d
        except (ValueError,OverflowError) as exc:errors.append(f'Linha {reader.line_num}: {exc}')
    if errors:raise ValueError('\n'.join(errors[:20])+f'\nTotal: {len(errors)} erros. Nada gravado.')
    if not records:raise ValueError('Nenhum registro valido.')
    return records,enc,sep,len(names),sum(not d.get('NOME FANTASIA') for d in records.values())


def conferir_estrutura(conn):
    with conn.cursor() as cur:
        cur.execute('SELECT id,cnes,dados_originais,correcoes,importado_em,editado_em,editado_por,versao FROM estabelecimento_saude LIMIT 0');cur.fetchall()
        cur.execute('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',(TABELA,));r=cur.fetchone()
        if not r or str(r[0]).upper()!='INNODB':raise ValueError('Tabela precisa usar InnoDB.')
        cur.execute('SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY INDEX_NAME,SEQ_IN_INDEX',(TABELA,))
        indexes={}
        for n,u,c in cur.fetchall():
            if not int(u):indexes.setdefault(n,[]).append(c)
        if ['cnes'] not in indexes.values():raise ValueError('Indice UNIQUE em cnes nao encontrado.')
    conn.rollback()


def importar(conn,records,update,stop,emit):
    conferir_estrutura(conn)
    emit('log','Estrutura e indice UNIQUE conferidos.')
    stats=dict(inseridos=0,atualizados=0,ignorados=0)
    esperados={};commit_iniciado=False
    try:
        conn.begin()
        with conn.cursor() as cur:
            for i,(c,d) in enumerate(records.items(),1):
                if stop.is_set():raise Cancelado('Cancelado antes do COMMIT.')
                cur.execute('SELECT id,dados_originais,correcoes FROM estabelecimento_saude WHERE cnes=%s FOR UPDATE',(c,));old=cur.fetchone()
                payload=json.dumps(d,ensure_ascii=False,allow_nan=False)
                if old is None:
                    cur.execute('INSERT INTO estabelecimento_saude (cnes,dados_originais,correcoes,importado_em,versao) VALUES (%s,%s,%s,UTC_TIMESTAMP(),1)',(c,payload,'{}'))
                    esperados[c]=(d,{});stats['inseridos']+=1;acao='INSERCAO PENDENTE'
                elif not update:stats['ignorados']+=1;acao='IGNORADO'
                else:
                    original,correcoes=objeto(old[1]),objeto(old[2],True)
                    if original==d:stats['ignorados']+=1;acao='SEM MUDANCA'
                    else:
                        a,b=str(original.get('COMPETÊNCIA','')),str(d.get('COMPETÊNCIA',''))
                        if not COMP.fullmatch(a) or not COMP.fullmatch(b):raise ValueError(f'CNES {c}: competencia invalida; esperado AAAA/MM.')
                        if b<a:raise ValueError(f'CNES {c}: competencia {b} anterior a {a}.')
                        cur.execute('UPDATE estabelecimento_saude SET dados_originais=%s,importado_em=UTC_TIMESTAMP(),versao=versao+1 WHERE id=%s',(payload,old[0]))
                        esperados[c]=(d,correcoes);stats['atualizados']+=1;acao='ATUALIZACAO PENDENTE'
                if i<=2000:emit('linha',(c,nome(d),d['UF'],d['MUNICÍPIO'],acao))
                if i%100==0 or i==len(records):emit('progresso',(i,len(records),dict(stats)))
        if stop.is_set():raise Cancelado('Cancelado antes do COMMIT.')
        emit('log','Enviando COMMIT. Nao feche o programa.');commit_iniciado=True;conn.commit()
    except BaseException as exc:
        if commit_iniciado:raise ResultadoIncerto('Erro durante COMMIT: resultado incerto. Confira o banco antes de repetir.') from exc
        try:conn.rollback();emit('log','ROLLBACK concluido: gravacoes desta execucao desfeitas.')
        except Exception:emit('log','ROLLBACK nao confirmado; confira o banco.')
        raise
    emit('log','COMMIT aceito. Conferindo registros gravados...')
    try:
        codigos=list(esperados)
        with conn.cursor() as cur:
            for ini in range(0,len(codigos),200):
                lote=codigos[ini:ini+200];ph=','.join(['%s']*len(lote))
                cur.execute(f'SELECT cnes,dados_originais,correcoes FROM estabelecimento_saude WHERE cnes IN ({ph})',lote)
                encontrados={str(c):(objeto(d),objeto(v,True)) for c,d,v in cur.fetchall()}
                for c in lote:
                    if encontrados.get(c)!=esperados[c]:raise ValueError('Releitura divergente/ausente no CNES '+c)
                if ini%10000==0 or ini+200>=len(codigos):emit('log',f'Conferencia: {min(ini+200,len(codigos))}/{len(codigos)}.')
        conn.rollback()
    except Exception as exc:raise ResultadoIncerto('COMMIT aceito, mas conferencia incompleta: '+str(exc)+'. Nao presuma rollback.') from exc
    stats['conferidos']=len(esperados)
    return stats


def executar_interface():
    import tkinter as tk
    from tkinter import ttk,filedialog,messagebox

    class App(tk.Tk):
        def __init__(self):
            super().__init__();self.title('ToolboxWaze - Importador CNES 1.3');self.geometry('1150x800');self.minsize(850,620)
            self.events=queue.Queue();self.stop=threading.Event();self.running=False;self.fields={};self.controls=[]
            self.montar();self.protocol('WM_DELETE_WINDOW',self.fechar);self.after(100,self.processar);self.after(250,self.lift)

        def montar(self):
            base=ttk.Frame(self,padding=16);base.pack(fill='both',expand=True)
            ttk.Label(base,text='Estabelecimentos de saude - CNES 1.3',font=('Segoe UI',16,'bold')).pack(anchor='w')
            ttk.Label(base,text='Nome fantasia vazio permitido | A senha nao e apagada apos validar o arquivo').pack(anchor='w',pady=(5,12))
            line=ttk.Frame(base);line.pack(fill='x');self.path=tk.StringVar()
            e=ttk.Entry(line,textvariable=self.path);e.pack(side='left',fill='x',expand=True);self.controls.append(e)
            b=ttk.Button(line,text='Escolher CSV/TSV',command=self.escolher);b.pack(side='left',padx=8);self.controls.append(b)
            box=ttk.LabelFrame(base,text='Conexao MySQL',padding=12);box.pack(fill='x',pady=12)
            defaults=dict(host=os.getenv('DB_HOST','212.85.3.243'),port=os.getenv('DB_PORT','3306'),user=os.getenv('DB_USER','u629736858_wazetoolbox'),database=os.getenv('DB_NAME','u629736858_wazetoolbox'),password='',ssl_ca=os.getenv('DB_SSL_CA',''))
            labels=dict(host='Host',port='Porta',user='Usuario',database='Banco',password='Senha MySQL (nao salva)',ssl_ca='CA (opcional)')
            for i,(k,v) in enumerate(defaults.items()):
                row,col=i//2,(i%2)*2;ttk.Label(box,text=labels[k]).grid(row=row,column=col,sticky='w',padx=5,pady=5)
                var=tk.StringVar(value=v);entry=ttk.Entry(box,textvariable=var,show='*' if k=='password' else '')
                entry.grid(row=row,column=col+1,sticky='ew',padx=5,pady=5);self.fields[k]=var;self.controls.append(entry)
            box.columnconfigure(1,weight=1);box.columnconfigure(3,weight=1)
            self.show_password=tk.BooleanVar(value=False)
            # A entrada da senha e obtida pela posicao na lista de campos de conexao.
            self.password_entry=self.controls[2+list(defaults).index('password')]
            show=ttk.Checkbutton(base,text='Mostrar senha na tela',variable=self.show_password,command=lambda:self.password_entry.configure(show='' if self.show_password.get() else '*'))
            show.pack(anchor='w');self.controls.append(show)
            ttk.Label(base,text='CA opcional. Sem CA, nao configuramos TLS validado; prefira tunel SSH.',wraplength=1050).pack(anchor='w',pady=5)
            self.update=tk.BooleanVar(value=False);b=ttk.Checkbutton(base,text='Atualizar fonte de CNES existentes (preserva correcoes manuais)',variable=self.update);b.pack(anchor='w',pady=8);self.controls.append(b)
            actions=ttk.Frame(base);actions.pack(fill='x')
            for title,write in [('Validar arquivo',False),('Importar e conferir salvamento',True)]:
                b=ttk.Button(actions,text=title,command=lambda w=write:self.iniciar(w));b.pack(side='left',padx=4);self.controls.append(b)
            self.cancel_btn=ttk.Button(actions,text='Cancelar',command=self.cancelar,state='disabled');self.cancel_btn.pack(side='left',padx=4)
            self.progress=ttk.Progressbar(base,maximum=100);self.progress.pack(fill='x',pady=10)
            self.status=ttk.Label(base,text='Aguardando arquivo.',wraplength=1050);self.status.pack(anchor='w')
            self.log=tk.Text(base,height=9,wrap='word',state='disabled');self.log.pack(fill='x',pady=10)
            ttk.Label(base,text='Pre-visualizacao: primeiros 2.000 registros. A importacao processa todos os registros validos.').pack(anchor='w')
            frame=ttk.Frame(base);frame.pack(fill='both',expand=True);cols=('cnes','nome','uf','municipio','acao');self.tree=ttk.Treeview(frame,columns=cols,show='headings')
            for k,t,w in zip(cols,['CNES','Nome / razao social','UF','Municipio','Resultado'],[85,340,45,170,220]):self.tree.heading(k,text=t);self.tree.column(k,width=w)
            scroll=ttk.Scrollbar(frame,orient='vertical',command=self.tree.yview);self.tree.configure(yscrollcommand=scroll.set);scroll.pack(side='right',fill='y');self.tree.pack(fill='both',expand=True)

        def report_callback_exception(self,exc,val,tb):
            text=''.join(traceback.format_exception(exc,val,tb));self.escrever(text);print(text,file=sys.stderr,flush=True);messagebox.showerror('Erro',str(val),parent=self)

        def escolher(self):
            path=filedialog.askopenfilename(parent=self,filetypes=[('CSV/TSV/TXT','*.csv *.tsv *.txt'),('Todos','*.*')])
            if path:self.path.set(path)

        def escrever(self,text):
            self.log.configure(state='normal');self.log.insert('end',f'[{datetime.now():%H:%M:%S}] {text}\n');self.log.see('end');self.log.configure(state='disabled')

        def bloquear(self,value):
            for w in self.controls:w.configure(state='disabled' if value else 'normal')
            self.cancel_btn.configure(state='normal' if value else 'disabled')

        def cancelar(self):self.stop.set();self.escrever('Cancelamento solicitado. Se COMMIT ja iniciou, aguarde.')

        def iniciar(self,write):
            if self.running:return
            path=self.path.get().strip()
            if not Path(path).is_file():messagebox.showerror('Arquivo','Selecione um arquivo valido.',parent=self);return
            # Snapshot de todos os campos, inclusive senha, ANTES de iniciar a thread.
            valores={k:v.get() for k,v in self.fields.items()}
            cfg={}
            if write:
                try:cfg=preparar_config(valores)
                except ValueError as exc:messagebox.showerror('Conexao',str(exc),parent=self);return
                if not messagebox.askyesno('Confirmar',f"Backup realizado?\nHost: {cfg['host']}:{cfg['port']}\nBanco: {cfg['database']}\nTabela: {TABELA}\nSenha capturada: SIM (conteudo oculto)\n\nContinuar?",parent=self):return
                self.escrever('Senha capturada: SIM. Conteudo nao sera registrado.')
            self.running=True;self.stop.clear();self.progress['value']=0;self.tree.delete(*self.tree.get_children());self.bloquear(True)
            threading.Thread(target=self.worker,args=(path,dict(cfg),self.update.get(),write),daemon=True).start()

        def worker(self,path,cfg,update,write):
            conn=None;emit=lambda n,v:self.events.put((n,v))
            try:
                emit('log','Validando arquivo...');records,enc,sep,n,sem_nome=ler_csv(path,self.stop)
                emit('log',f'{len(records)} CNES unicos; {n} campos; encoding={enc}; separador={sep!r}.')
                emit('log',f'{sem_nome} registros sem nome fantasia: aceitos e preservados.')
                if self.stop.is_set():raise Cancelado('Cancelado antes da conexao.')
                if not write:emit('validado','Arquivo validado. Nenhuma conexao ou gravacao. A senha foi mantida no campo.');return
                if not cfg.get('password'):raise ValueError('Senha nao capturada. Digite novamente e reinicie a importacao.')
                try:import pymysql
                except ImportError:raise ValueError('Instale: python -m pip install pymysql')
                ca=cfg.pop('ssl_ca','');cfg.update(charset='utf8mb4',autocommit=False,connect_timeout=20,read_timeout=120,write_timeout=120)
                if ca:cfg.update(ssl_ca=ca,ssl_verify_cert=True,ssl_verify_identity=True)
                emit('log','Conectando ao MySQL com senha preenchida...');conn=pymysql.connect(**cfg)
                with conn.cursor() as cur:cur.execute('SELECT DATABASE(),@@hostname');db,host=cur.fetchone()
                conn.rollback()
                if db!=cfg['database']:raise ValueError('Banco conectado difere do informado.')
                emit('log',f'Conectado: banco={db}; servidor={host}.')
                s=importar(conn,records,update,self.stop,emit)
                emit('sucesso',f"CONCLUIDO: {s['inseridos']} inseridos; {s['atualizados']} atualizados; {s['ignorados']} ignorados; {s['conferidos']} gravacoes relidas apos COMMIT.")
            except Exception as exc:
                # Nao registra cfg nem senha. Mascara a senha caso apareca numa excecao.
                text=str(exc);password=cfg.get('password','')
                if password:text=text.replace(password,'[SENHA OCULTA]')
                emit('erro',text)
            finally:
                if conn is not None:
                    try:conn.close()
                    except Exception:pass
                cfg.pop('password',None)
                emit('fim',None)

        def processar(self):
            for _ in range(300):
                try:name,value=self.events.get_nowait()
                except queue.Empty:break
                if name=='linha':self.tree.insert('','end',values=value)
                elif name=='progresso':
                    i,total,s=value;self.progress['value']=i/total*100;self.status.configure(text=f"{i}/{total} | Novos: {s['inseridos']} | Atualizados: {s['atualizados']} | Aguardando COMMIT e conferencia.")
                elif name in ('log','sucesso','erro','validado'):
                    self.escrever(value)
                    if name!='log':self.status.configure(text=value)
                    if name in ('sucesso','erro'):
                        for iid in self.tree.get_children():
                            vals=list(self.tree.item(iid,'values'))
                            if vals and 'PENDENTE' in vals[-1]:vals[-1]='GRAVADO E CONFERIDO' if name=='sucesso' else 'NAO CONFIRMADO - VER LOG';self.tree.item(iid,values=vals)
                elif name=='fim':
                    self.running=False;self.bloquear(False)
                    # Nao apaga a senha apos validar, importar ou falhar.
            self.after(100,self.processar)

        def fechar(self):
            if self.running:messagebox.showinfo('Aguarde','Solicite cancelamento e aguarde antes de fechar.',parent=self);return
            self.fields['password'].set('');self.destroy()

    print('Criando janela...',flush=True);app=App();print('Interface em execucao.',flush=True);app.mainloop();print('Janela encerrada.',flush=True)


def main():
    print(f'Arquivo: {Path(__file__).resolve()}',flush=True);print(f'Python: {sys.executable}',flush=True)
    try:executar_interface();return 0
    except Exception:
        text=traceback.format_exc();print(text,file=sys.stderr,flush=True)
        try:Path(__file__).with_name('importador_saude_erro.log').write_text(text,encoding='utf-8')
        except OSError:pass
        return 1


if __name__=='__main__':
    sys.exit(main())
