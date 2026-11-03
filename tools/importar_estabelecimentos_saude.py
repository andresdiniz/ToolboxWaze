#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""ToolboxWaze - Importador CNES 2.1.

Python 3.10+
Instalacao: python -m pip install pymysql
"""

import csv
import io
import json
import os
import queue
import re
import sys
import threading
import traceback
import time
import unicodedata
from datetime import datetime, timezone
from pathlib import Path

UFS = set(
    'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'.split()
)

VAZIOS = {'', '-', 'N/A', 'NULL', 'NAN', 'NONE'}
COMP = re.compile(r'^\d{4}/(0[1-9]|1[0-2])$')
TABELA = 'estabelecimento_saude'


class Cancelado(Exception):
    pass


class ResultadoIncerto(Exception):
    pass


def nome(d):
    return (
        d.get('NOME FANTASIA')
        or d.get('RAZÃO SOCIAL')
        or f'Estabelecimento CNES {d["CNES"]}'
    )


def objeto(valor, correcoes=False):
    if isinstance(valor, (bytes, bytearray)):
        valor = valor.decode('utf-8')

    if isinstance(valor, str):
        valor = json.loads(valor)

    if correcoes and valor == []:
        return {}

    if not isinstance(valor, dict):
        raise ValueError('JSON invalido no banco.')

    return valor


def preparar_config(valores):
    cfg = {
        chave: (
            str(valor)
            if chave == 'password'
            else str(valor).strip()
        )
        for chave, valor in valores.items()
    }

    cfg['port'] = int(cfg['port'])

    if not 1 <= cfg['port'] <= 65535:
        raise ValueError('Porta invalida.')

    if not all(cfg.get(chave) for chave in ('host', 'user', 'database')):
        raise ValueError('Preencha host, usuario e banco.')

    if not cfg.get('password'):
        raise ValueError('Digite a senha do MySQL antes de importar.')

    if cfg.get('ssl_ca') and not Path(cfg['ssl_ca']).is_file():
        raise ValueError(
            'CA nao encontrado. Deixe vazio se nao utilizar.'
        )

    return cfg




def mensagem_conexao(erro):
    codigo = erro.args[0] if getattr(erro, 'args', ()) else None
    original = str(erro)
    if codigo == 1045:
        return (
            'MySQL 1045: autenticação recusada pelo servidor. Uma senha foi enviada, '
            'mas isso não confirma que ela esteja correta.\n'
            'Confira: host remoto MySQL (não o endereço do site), usuário do banco, '
            'senha MySQL e autorização do IP de origem em MySQL remoto. '
            'Não use a senha do painel da hospedagem.\n' + original
        )
    if codigo == 1044:
        return 'MySQL 1044: o usuário não tem acesso ao banco selecionado.\n' + original
    if codigo == 1049:
        return 'MySQL 1049: o banco informado não foi encontrado no servidor.\n' + original
    if codigo in (2003, 2005):
        return 'Não foi possível alcançar o host/porta MySQL. Confira DNS, porta e regras de acesso remoto.\n' + original
    return original


def conectar_mysql(configuracao):
    try:
        import pymysql
    except ImportError as exc:
        raise ValueError('Instale: python -m pip install pymysql') from exc
    cfg = dict(configuracao)
    ca = cfg.pop('ssl_ca', '')
    if not cfg.get('password'):
        raise ValueError('Preencha a senha MySQL.')
    cfg.update(charset='utf8mb4', autocommit=False, connect_timeout=20,
               read_timeout=180, write_timeout=180)
    if ca:
        cfg.update(ssl_ca=ca, ssl_verify_cert=True, ssl_verify_identity=True)
    conn = None
    try:
        conn = pymysql.connect(**cfg)
        with conn.cursor() as cursor:
            cursor.execute('SELECT DATABASE(), CURRENT_USER(), USER(), @@hostname')
            row = cursor.fetchone()
            banco, conta, origem, servidor = list(row.values()) if isinstance(row, dict) else row
        conn.rollback()
        if banco != configuracao['database']:
            raise ValueError('Banco conectado difere do informado.')
        return conn, {'banco': banco, 'conta': conta, 'origem': origem, 'servidor': servidor}
    except Exception as exc:
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass
        message = mensagem_conexao(exc)
        password = cfg.get('password', '')
        if password:
            message = message.replace(password, '[SENHA OCULTA]')
        raise ValueError(message) from None
    finally:
        cfg.pop('password', None)


def relatar_etapa(emit, fase, atual=None, total=None, unidade='', detalhe=''):
    if emit:
        emit('etapa', dict(fase=fase, atual=atual, total=total,
                           unidade=unidade, detalhe=detalhe))


import codecs
import hashlib
import itertools
import sqlite3
import tempfile
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP

CORE = ('nome_fantasia','ativo','uf','municipio','logradouro','telefone','latitude','longitude')
LIMITES = {'nome_fantasia':255,'uf':2,'municipio':255,'logradouro':500,'telefone':50}
QUANT = Decimal('0.00000001')

def chave(value):
    return ''.join(c for c in unicodedata.normalize('NFKD',str(value).strip()) if not unicodedata.combining(c)).upper().replace('_',' ')

def clean(value):
    value = '' if value is None else unicodedata.normalize('NFC',str(value)).strip()
    return '' if chave(value) in VAZIOS else value

def mapa(dados):
    return {chave(k):clean(v) for k,v in dados.items()}

def json_txt(value):
    return json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(',',':'),allow_nan=False)

def coords(dados):
    d = mapa(dados)
    lat,lon = d.get('LATITUDE',''),d.get('LONGITUDE','')
    if bool(lat) != bool(lon): raise ValueError('Informe latitude e longitude juntas.')
    if not lat:
        loc = d.get('LOCATION','')
        if not loc: return None,None
        parts = re.split(r'[\s;]+',loc.strip())
        if len(parts) != 2:
            match = re.fullmatch(r'\s*([+-]?\d+(?:\.\d+)?)\s*,\s*([+-]?\d+(?:\.\d+)?)\s*',loc)
            if not match: raise ValueError('Location: esperado latitude e longitude.')
            parts = list(match.groups())
        lat,lon = parts
    try: lat,lon = Decimal(str(lat).replace(',','.')),Decimal(str(lon).replace(',','.'))
    except InvalidOperation as exc: raise ValueError('Coordenadas não numéricas.') from exc
    if not lat.is_finite() or not lon.is_finite() or abs(lat)>90 or abs(lon)>180: raise ValueError('Coordenadas fora da faixa válida.')
    if lat == 0 or lon == 0: return None,None
    return tuple(format(v.quantize(QUANT,rounding=ROUND_HALF_UP),'.8f') for v in (lat,lon))

def projetar(original,correcoes=None):
    d = mapa(original); cor = mapa(correcoes or {})
    aliases = {'NOME FANTASIA':'NOME FANTASIA','ATIVO':'STATUS DO ESTABELECIMENTO','MUNICIPIO':'MUNICIPIO','ENDERECO':'LOGRADOURO'}
    cor = {aliases.get(k,k):v for k,v in cor.items()}
    if 'LOCATION' in cor and 'LATITUDE' not in cor and 'LONGITUDE' not in cor:
        d.pop('LATITUDE',None); d.pop('LONGITUDE',None)
    d.update(cor)
    uf = d.get('UF','').upper(); municipio = re.sub(r'\s+',' ',d.get('MUNICIPIO','')).strip()
    if uf not in UFS: raise ValueError('UF inválida ou ausente.')
    if not municipio: raise ValueError('Município ausente.')
    situacao = chave(d.get('STATUS DO ESTABELECIMENTO',''))
    if situacao in {'ATIVO','ATIVA','1','TRUE','SIM'}: ativo=1
    elif situacao in {'INATIVO','INATIVA','DESATIVADO','DESATIVADA','0','FALSE','NAO'}: ativo=0
    else: raise ValueError('Situação CNES desconhecida; ATIVO não será presumido.')
    fantasia = d.get('NOME FANTASIA') or d.get('RAZAO SOCIAL') or 'Estabelecimento CNES '+d.get('CNES','')
    latitude,longitude = coords(d)
    result = {'nome_fantasia':re.sub(r'\s+',' ',fantasia),'ativo':ativo,'uf':uf,'municipio':municipio,
        'logradouro':re.sub(r'\s+',' ',d.get('LOGRADOURO','')).strip() or None,
        'telefone':re.sub(r'\s+',' ',d.get('TELEFONE','')).strip() or None,'latitude':latitude,'longitude':longitude}
    for field,limit in LIMITES.items():
        if result[field] is not None and len(result[field])>limit: raise ValueError(f'{field}: máximo {limit} caracteres, sem truncamento silencioso.')
    return result

def batches(iterable,size):
    it=iter(iterable)
    while batch:=list(itertools.islice(it,size)): yield batch

def ler_csv(caminho,stop,emit=None):
    total_bytes = os.path.getsize(caminho)
    ultimo_aviso = 0.0
    encoding=None
    for enc in ('utf-8-sig','cp1252','latin-1'):
        try:
            relatar_etapa(emit, 'Detectando encoding: ' + enc, 0, total_bytes, 'bytes')
            decoder=codecs.getincrementaldecoder(enc)(errors='strict')
            with open(caminho,'rb') as f:
                while block:=f.read(1024*1024):
                    if stop.is_set(): raise Cancelado('Validação cancelada; nada gravado.')
                    decoder.decode(block,final=False)
                    agora = time.monotonic()
                    if agora - ultimo_aviso >= 0.2:
                        relatar_etapa(emit, 'Detectando encoding: ' + enc, f.tell(), total_bytes, 'bytes')
                        ultimo_aviso = agora
                decoder.decode(b'',final=True)
            encoding=enc; break
        except UnicodeDecodeError: continue
    if not encoding: raise ValueError('Encoding desconhecido.')
    relatar_etapa(emit, 'Validando CSV/TSV', 0, total_bytes, 'bytes', 'Progresso aproximado pela leitura do arquivo.')
    registros={}; erros=[]; duplicates=0
    csv.field_size_limit(4*1024*1024)
    with open(caminho,encoding=encoding,newline='') as f:
        first=f.readline()
        if not first.strip(): raise ValueError('Arquivo vazio ou cabeçalho ausente.')
        sep=max(('\t',';',','),key=lambda s:len(next(csv.reader([first],delimiter=s))))
        f.seek(0); reader=csv.reader(f,delimiter=sep,strict=True)
        headers=[unicodedata.normalize('NFC',x.strip()) for x in next(reader)]
        idx=[i for i,k in enumerate(headers) if k]; keys=[chave(headers[i]) for i in idx]
        if not idx or len(keys)!=len(set(keys)): raise ValueError('Cabeçalho vazio ou duplicado.')
        required={'CNES','UF','MUNICIPIO','STATUS DO ESTABELECIMENTO'}
        if not required.issubset(keys): raise ValueError('Colunas ausentes: '+', '.join(sorted(required-set(keys))))
        cnes_key=headers[idx[keys.index('CNES')]]
        for row in reader:
            agora = time.monotonic()
            if agora - ultimo_aviso >= 0.2:
                relatar_etapa(emit, 'Validando CSV/TSV', min(f.buffer.tell(), max(0, total_bytes-1)), total_bytes, 'bytes', f'{len(registros):,} CNES válidos; linha física {reader.line_num}. Leitura aproximada; validação ainda em andamento.')
                ultimo_aviso = agora
            if stop.is_set(): raise Cancelado('Validação cancelada; nada gravado.')
            if not any(x.strip() for x in row): continue
            try:
                if len(row)<=max(idx): raise ValueError('Linha incompleta nos campos nomeados.')
                if any(v.strip() for i,v in enumerate(row) if i>=len(headers) or not headers[i]): raise ValueError('Conteúdo em coluna excedente/sem nome.')
                data={headers[i]:clean(row[i]) for i in idx}
                cnes=data[cnes_key]
                if not re.fullmatch(r'\d{1,7}',cnes): raise ValueError('CNES deve ter de 1 a 7 dígitos.')
                cnes=cnes.zfill(7); data[cnes_key]=cnes
                projected=projetar(data)
                comp=mapa(data).get('COMPETENCIA','')
                if comp and not COMP.fullmatch(comp): raise ValueError('Competência inválida; esperado AAAA/MM.')
                if cnes in registros:
                    if registros[cnes]!=data: raise ValueError('CNES duplicado com conteúdo divergente: '+cnes)
                    duplicates+=1
                registros[cnes]=data
            except (ValueError,InvalidOperation) as exc:
                erros.append(f'Linha física {reader.line_num}: {exc}')
                if len(erros)>=30: break
    if erros: raise ValueError('\n'.join(erros)+'\nNada gravado no MySQL.')
    if not registros: raise ValueError('Nenhum registro válido.')
    relatar_etapa(emit, 'Preparando resumo da validação', detalhe=f'{len(registros):,} CNES validados.')
    sem_nome = 0
    for i, data in enumerate(registros.values(), 1):
        if stop.is_set(): raise Cancelado('Validação cancelada; nada gravado.')
        sem_nome += not mapa(data).get('NOME FANTASIA')
        if i % 5000 == 0:
            relatar_etapa(emit, 'Preparando resumo da validação', i, len(registros), 'registros')
    return registros,encoding,sep,len(idx),sem_nome

def fetch_rows(cursor):
    rows=cursor.fetchall()
    if not rows or isinstance(rows[0],dict): return rows
    names=[d[0] for d in cursor.description]
    return [dict(zip(names,row)) for row in rows]

def schema_cols(conn):
    with conn.cursor() as c:
        c.execute('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',(TABELA,))
        return {r['COLUMN_NAME'] for r in fetch_rows(c)}

def garantir_estrutura(conn):
    cols=schema_cols(conn)
    if not cols:
        with conn.cursor() as c:
            c.execute(f'''CREATE TABLE {TABELA} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,cnes VARCHAR(10) NOT NULL,
                nome_fantasia VARCHAR(255) NULL,ativo TINYINT NOT NULL DEFAULT 1,uf VARCHAR(2) NULL,
                municipio VARCHAR(255) NULL,logradouro VARCHAR(500) NULL,telefone VARCHAR(50) NULL,
                latitude DECIMAL(12,8) NULL,longitude DECIMAL(12,8) NULL,link_waze VARCHAR(1000) NULL,
                row_hash VARCHAR(64) NOT NULL,imported_at DATETIME NOT NULL,updated_at DATETIME NULL,
                status VARCHAR(20) NULL,reservado_para VARCHAR(100) NULL,reservado_em DATETIME NULL,
                dados_originais LONGTEXT NOT NULL,correcoes LONGTEXT NOT NULL,importado_em DATETIME NOT NULL,
                editado_em DATETIME NULL,editado_por VARCHAR(180) NULL,versao INT UNSIGNED NOT NULL DEFAULT 1,
                UNIQUE KEY uniq_cnes(cnes),KEY idx_uf_municipio(uf,municipio),KEY idx_status(status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci''')
        cols=schema_cols(conn)
    return cols

def conferir_estrutura(conn):
    cols=garantir_estrutura(conn)
    required={'id','cnes','row_hash','imported_at','updated_at','dados_originais','correcoes',*CORE}
    if required-cols: raise ValueError('Tabela incompleta. Faltam: '+', '.join(sorted(required-cols))+'. Não foi executado ALTER automático.')
    with conn.cursor() as c:
        c.execute('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',(TABELA,))
        rows=fetch_rows(c)
        if not rows or rows[0]['ENGINE'].upper()!='INNODB': raise ValueError('Tabela deve ser InnoDB.')
        c.execute(f'SHOW INDEX FROM {TABELA}'); indexes={}
        for row in fetch_rows(c):
            if int(row['Non_unique'])==0: indexes.setdefault(row['Key_name'],[]).append((int(row['Seq_in_index']),row['Column_name']))
        if not any([col for _,col in sorted(parts)]==['cnes'] for parts in indexes.values()): raise ValueError('Falta índice UNIQUE somente em cnes; o nome do índice não importa.')
    with conn.cursor() as c:
        c.execute(f"SELECT cnes FROM {TABELA} WHERE cnes IS NULL OR cnes NOT REGEXP '^[0-9]{{7}}$' LIMIT 1")
        if c.fetchone(): raise ValueError('Há CNES existentes sem 7 dígitos. Audite a identidade antes de importar para não criar duplicados com zeros à esquerda.')
    conn.rollback(); return cols

def dbdate(value):
    if isinstance(value,datetime): return value.strftime('%Y-%m-%d %H:%M:%S')
    if value is None: return None
    try: return datetime.strptime(str(value)[:19],'%Y-%m-%d %H:%M:%S').strftime('%Y-%m-%d %H:%M:%S')
    except ValueError: return None

def normal(field,value):
    if field in ('dados_originais','correcoes'): return objeto(value,field=='correcoes')
    if field in ('latitude','longitude'): return None if value in (None,'') else format(Decimal(str(value)).quantize(QUANT),'.8f')
    if field in ('imported_at','updated_at','importado_em'): return dbdate(value)
    if field in ('ativo','versao'): return int(value)
    return value

def planejar(old,incoming,atualizar,cols,agora):
    original=objeto(old['dados_originais']) if old else incoming
    cor=objeto(old['correcoes'],True) if old else {}
    source_changed=False
    if old and atualizar and incoming!=original:
        before=mapa(original).get('COMPETENCIA',''); after=mapa(incoming).get('COMPETENCIA','')
        if before and (not COMP.fullmatch(before) or not COMP.fullmatch(after)): raise ValueError('Competência inválida; atualização da fonte bloqueada.')
        if before and after<before: raise ValueError('Arquivo mais antigo que a fonte no banco. Use reparo sem atualizar fonte.')
        original=incoming; source_changed=True
    values=projetar(original,cor)
    if old:
        try: baseline=projetar(objeto(old['dados_originais']))
        except ValueError: baseline=None
        if baseline and (dbdate(old.get('editado_em')) is not None or (old.get('nome_fantasia') and old.get('uf') and old.get('municipio'))):
            correction_keys=mapa(cor)
            names={'nome_fantasia':'NOME FANTASIA','ativo':'STATUS DO ESTABELECIMENTO','uf':'UF','municipio':'MUNICIPIO','logradouro':'LOGRADOURO','telefone':'TELEFONE','latitude':'LATITUDE','longitude':'LONGITUDE'}
            marked=dbdate(old.get('editado_em')) is not None
            for field in CORE:
                has_correction=names[field] in correction_keys or (field=='ativo' and 'ATIVO' in correction_keys) or (field in ('latitude','longitude') and 'LOCATION' in correction_keys)
                if has_correction: continue
                current=old.get(field)
                if current in (None,'') and not marked: continue
                if normal(field,current)!=normal(field,baseline[field]): values[field]=current
    if values['uf'] is None or str(values['uf']).strip().upper() not in UFS:
        raise ValueError('UF estruturada/manual inválida; corrija o registro explicitamente.')
    values['uf']=str(values['uf']).strip().upper()
    if not values['municipio'] or not str(values['municipio']).strip():
        raise ValueError('Município estruturado/manual ausente; corrija o registro explicitamente.')
    if int(values['ativo']) not in (0,1):
        raise ValueError('Situação estruturada/manual deve ser 0 ou 1.')
    values['latitude'],values['longitude']=coords({'latitude':values['latitude'],'longitude':values['longitude']})
    content=json_txt(original)
    values.update(dados_originais=content,row_hash=hashlib.sha256(content.encode('utf-8')).hexdigest())
    values['imported_at']=(dbdate(old.get('imported_at')) or dbdate(old.get('importado_em')) or agora) if old else agora
    if 'importado_em' in cols: values['importado_em']=agora if source_changed or not old else (dbdate(old.get('importado_em')) or values['imported_at'])
    if old and all(normal(k,old.get(k))==normal(k,v) for k,v in values.items()): return 'ignorados',None
    values['updated_at']=agora if old else None
    if 'versao' in cols: values['versao']=int(old.get('versao') or 1)+1 if old else 1
    if not old: values.update(cnes=mapa(incoming)['CNES'],correcoes='{}')
    return ('atualizados' if old else 'inseridos'),values

def ler_banco(conn,stop,emit):
    relatar_etapa(emit, 'Lendo JSON existente para reparo')
    conferir_estrutura(conn); registros={}; last=0
    while True:
        if stop.is_set(): raise Cancelado('Leitura de reparo cancelada; nada gravado.')
        with conn.cursor() as c:
            c.execute(f'SELECT id,cnes,dados_originais FROM {TABELA} WHERE id>%s ORDER BY id LIMIT 2000',(last,)); rows=fetch_rows(c)
        conn.rollback()
        if not rows: break
        for row in rows:
            cnes=str(row['cnes'])
            if not re.fullmatch(r'\d{7}',cnes): raise ValueError(f'CNES existente inválido no ID {row["id"]}; não será renomeado automaticamente.')
            registros[cnes]=objeto(row['dados_originais']); last=int(row['id'])
        relatar_etapa(emit, 'Lendo JSON existente para reparo', detalhe=f'{len(registros):,} registros preparados.')
    if not registros: raise ValueError('Tabela vazia.')
    return registros

def importar(conn,registros,atualizar,stop,emit,tamanho_lote=None):
    size=int(tamanho_lote or os.getenv('CNES_BATCH_SIZE','500'))
    if not 1<=size<=2000: raise ValueError('Lote precisa estar entre 1 e 2000.')
    relatar_etapa(emit, 'Conferindo estrutura do banco')
    cols=conferir_estrutura(conn)
    write_cols=list(CORE)+['dados_originais','row_hash','imported_at','updated_at']+[k for k in ('importado_em','versao') if k in cols]
    types={'nome_fantasia':'VARCHAR(255)','ativo':'TINYINT','uf':'VARCHAR(2)','municipio':'VARCHAR(255)','logradouro':'VARCHAR(500)',
        'telefone':'VARCHAR(50)','latitude':'DECIMAL(12,8)','longitude':'DECIMAL(12,8)','dados_originais':'LONGTEXT',
        'row_hash':'VARCHAR(64)','imported_at':'DATETIME','updated_at':'DATETIME','importado_em':'DATETIME','versao':'INT UNSIGNED'}
    stats={'inseridos':0,'atualizados':0,'ignorados':0}; lock=None; committing=False; committed=False
    emit('log',f'Lotes de {size}; INSERT múltiplo e UPDATE por tabela temporária. Sem SELECT por registro.')
    with tempfile.TemporaryDirectory(prefix='cnes_verificacao_') as temp:
        expected=sqlite3.connect(str(Path(temp)/'verificacao.sqlite'))
        expected.execute('CREATE TABLE esperado(cnes TEXT PRIMARY KEY,conteudo TEXT NOT NULL)')
        try:
            with conn.cursor() as c:
                c.execute('SELECT DATABASE(),@@max_allowed_packet'); row=c.fetchone(); info=list(row.values()) if isinstance(row,dict) else row
                packet=int(info[1]); lock='cnes_'+hashlib.sha256(str(info[0]).encode()).hexdigest()[:40]
                c.execute('SELECT GET_LOCK(%s,0)',(lock,)); row=c.fetchone(); ok=next(iter(row.values())) if isinstance(row,dict) else row[0]
                if ok!=1: lock=None; raise ValueError('Outro importador está ativo neste banco.')
                c.execute('DROP TEMPORARY TABLE IF EXISTS tw_cnes_stage')
                definitions=','.join(f'`{col}` {types[col]} NULL' for col in write_cols)
                c.execute(f'CREATE TEMPORARY TABLE tw_cnes_stage(row_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,{definitions}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')
            conn.rollback(); conn.begin(); processed=0
            relatar_etapa(emit, 'Gravando lotes (sem COMMIT)', 0, len(registros), 'registros')
            for codes in batches(sorted(registros),size):
                if stop.is_set(): raise Cancelado('Cancelado antes do COMMIT.')
                marks=','.join(['%s']*len(codes)); agora=datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
                with conn.cursor() as c:
                    c.max_stmt_length=max(4096,min(1024*1024,packet//2))
                    c.execute(f'SELECT * FROM {TABELA} WHERE cnes IN ({marks}) ORDER BY cnes FOR UPDATE',codes)
                    existing={str(r['cnes']):r for r in fetch_rows(c)}
                    inserts=[]; updates=[]; insert_cols=['cnes']+write_cols+['correcoes']
                    for cnes in codes:
                        old=existing.get(cnes); data=registros[cnes]
                        try: action,values=planejar(old,data,atualizar,cols,agora)
                        except (ValueError,TypeError,InvalidOperation) as exc: raise ValueError(f'CNES {cnes}: {exc}') from exc
                        stats[action]+=1; processed+=1
                        if values is not None:
                            if len(values['dados_originais'].encode('utf-8')) > packet//2:
                                raise ValueError(f'CNES {cnes}: JSON muito grande para max_allowed_packet do servidor.')
                            verification={k:normal(k,v) for k,v in values.items()}
                            verification.update(cnes=cnes,correcoes=objeto(old['correcoes'],True) if old else {})
                            expected.execute('INSERT OR REPLACE INTO esperado VALUES (?,?)',(cnes,json_txt(verification)))
                            if old: updates.append((old['id'],)+tuple(values[k] for k in write_cols))
                            else: inserts.append(tuple(values[k] for k in insert_cols))
                        if processed<=2000:
                            display=values or old
                            emit('linha',(cnes,display.get('nome_fantasia') or nome(data),display.get('uf') or '—',display.get('municipio') or '—','GRAVACAO PENDENTE' if values is not None else 'SEM MUDANÇA'))
                    if inserts:
                        fields=','.join('`'+k+'`' for k in insert_cols); params=','.join(['%s']*len(insert_cols))
                        c.executemany(f'INSERT INTO {TABELA}({fields}) VALUES ({params})',inserts)
                    if updates:
                        c.execute('DELETE FROM tw_cnes_stage')
                        fields=','.join(['row_id']+['`'+k+'`' for k in write_cols]); params=','.join(['%s']*(1+len(write_cols)))
                        c.executemany(f'INSERT INTO tw_cnes_stage({fields}) VALUES ({params})',updates)
                        sets=','.join(f't.`{k}`=s.`{k}`' for k in write_cols)
                        c.execute(f'UPDATE {TABELA} t JOIN tw_cnes_stage s ON s.row_id=t.id SET {sets}')
                expected.commit(); emit('progresso',(processed,len(registros),dict(stats)))
            if stop.is_set(): raise Cancelado('Cancelado antes do COMMIT.')
            relatar_etapa(emit, 'COMMIT em andamento', detalhe='Aguarde a resposta do servidor. Não feche o programa.')
            emit('log','Enviando COMMIT único. Não feche o programa.'); committing=True; conn.commit(); committed=True
            emit('log','COMMIT aceito. Conferindo colunas, JSON e correções em lotes...')
            checked=0
            total_conferencia = stats['inseridos'] + stats['atualizados']
            relatar_etapa(emit, 'Conferência após COMMIT', 0, total_conferencia, 'registros')
            for batch in batches(expected.execute('SELECT cnes,conteudo FROM esperado ORDER BY cnes'),size):
                codes=[r[0] for r in batch]; marks=','.join(['%s']*len(codes))
                with conn.cursor() as c:
                    c.execute(f'SELECT * FROM {TABELA} WHERE cnes IN ({marks})',codes); actual={str(r['cnes']):r for r in fetch_rows(c)}
                conn.rollback()
                for cnes,content in batch:
                    if cnes not in actual: raise ValueError('Registro ausente após COMMIT: '+cnes)
                    for field,value in json.loads(content).items():
                        if normal(field,actual[cnes].get(field))!=value: raise ValueError(f'CNES {cnes}: campo {field} divergente após COMMIT; confira edição concorrente.')
                    checked+=1
                relatar_etapa(emit, 'Conferência após COMMIT', checked, total_conferencia, 'registros')
            stats['conferidos']=checked; return stats
        except BaseException as exc:
            if committing:
                relatar_etapa(emit, 'Resultado requer conferência manual')
                prefix='COMMIT aceito, conferência incompleta' if committed else 'Falha durante COMMIT: resultado incerto'
                raise ResultadoIncerto(prefix+'. Confira o banco antes de repetir. '+str(exc)) from exc
            relatar_etapa(emit, 'Desfazendo transação (ROLLBACK)')
            try: conn.rollback(); emit('log','ROLLBACK confirmado: alterações de dados desfeitas. Criação da tabela, se houve, não é desfeita.')
            except Exception: emit('log','ROLLBACK não confirmado; confira o banco.')
            raise
        finally:
            expected.close()
            if lock:
                try:
                    with conn.cursor() as c: c.execute('SELECT RELEASE_LOCK(%s)',(lock,))
                    conn.rollback()
                except Exception: pass


def executar_interface():
    import tkinter as tk
    from tkinter import ttk, filedialog, messagebox

    class App(tk.Tk):
        def __init__(self):
            super().__init__()

            self.title('ToolboxWaze - Importador CNES 2.1')
            self.geometry('1150x800')
            self.minsize(850, 620)

            self.eventos = queue.Queue(maxsize=5000)
            self.stop = threading.Event()
            self.executando = False
            self.campos = {}
            self.entradas = {}
            self._fase = None
            self._inicio_fase = time.monotonic()
            self._inicio_trabalho = time.monotonic()
            self.controles = []

            self.montar()
            self.protocol('WM_DELETE_WINDOW', self.fechar)
            self.after(100, self.processar)
            self.after(250, self.lift)

        def montar(self):
            base = ttk.Frame(self, padding=16)
            base.pack(fill='both', expand=True)

            ttk.Label(
                base,
                text='Estabelecimentos de saude - CNES 2.0',
                font=('Segoe UI', 16, 'bold')
            ).pack(anchor='w')

            ttk.Label(
                base,
                text=(
                    'Nome fantasia vazio permitido | '
                    'A senha nao e apagada apos validar o arquivo | '
                    'Colunas de busca preenchidas | Lotes de 500 | Reparo do JSON existente'
                )
            ).pack(anchor='w', pady=(5, 12))

            linha = ttk.Frame(base)
            linha.pack(fill='x')

            self.caminho = tk.StringVar()

            entrada = ttk.Entry(
                linha,
                textvariable=self.caminho
            )
            entrada.pack(side='left', fill='x', expand=True)
            self.controles.append(entrada)

            botao = ttk.Button(
                linha,
                text='Escolher CSV/TSV',
                command=self.escolher
            )
            botao.pack(side='left', padx=8)
            self.controles.append(botao)

            caixa = ttk.LabelFrame(
                base,
                text='Conexao MySQL',
                padding=12
            )
            caixa.pack(fill='x', pady=12)

            padroes = {
                'host': os.getenv('DB_HOST', 'localhost'),
                'port': os.getenv('DB_PORT', '3306'),
                'user': os.getenv(
                    'DB_USER',
                    ''
                ),
                'database': os.getenv(
                    'DB_NAME',
                    ''
                ),
                'password': '',
                'ssl_ca': os.getenv('DB_SSL_CA', ''),
            }

            rotulos = {
                'host': 'Host',
                'port': 'Porta',
                'user': 'Usuario',
                'database': 'Banco',
                'password': 'Senha MySQL (nao salva)',
                'ssl_ca': 'CA (opcional)',
            }

            for posicao, (chave, valor) in enumerate(
                padroes.items()
            ):
                linha_atual = posicao // 2
                coluna = (posicao % 2) * 2

                ttk.Label(
                    caixa,
                    text=rotulos[chave]
                ).grid(
                    row=linha_atual,
                    column=coluna,
                    sticky='w',
                    padx=5,
                    pady=5
                )

                variavel = tk.StringVar(value=valor)

                entrada = ttk.Entry(
                    caixa,
                    textvariable=variavel,
                    show='*' if chave == 'password' else ''
                )

                entrada.grid(
                    row=linha_atual,
                    column=coluna + 1,
                    sticky='ew',
                    padx=5,
                    pady=5
                )

                self.campos[chave] = variavel
                self.entradas[chave] = entrada
                self.controles.append(entrada)

            caixa.columnconfigure(1, weight=1)
            caixa.columnconfigure(3, weight=1)

            self.mostrar_senha = tk.BooleanVar(value=False)

            entrada_senha = self.entradas['password']

            ttk.Checkbutton(
                base,
                text='Mostrar senha na tela',
                variable=self.mostrar_senha,
                command=lambda: entrada_senha.configure(
                    show=(
                        ''
                        if self.mostrar_senha.get()
                        else '*'
                    )
                )
            ).pack(anchor='w')

            ttk.Label(
                base,
                text=(
                    'CA opcional. Sem CA, nao configuramos TLS '
                    'validado; prefira tunel SSH.'
                ),
                wraplength=1050
            ).pack(anchor='w', pady=5)

            self.atualizar = tk.BooleanVar(value=False)

            ttk.Checkbutton(
                base,
                text=(
                    'Atualizar fonte de CNES existentes '
                    '(preserva correções; desmarcado ainda repara colunas)'
                ),
                variable=self.atualizar
            ).pack(anchor='w', pady=8)

            acoes = ttk.Frame(base)
            acoes.pack(fill='x')

            for indice, (titulo, gravar) in enumerate((
                ('Testar conexão MySQL', 'testar'),
                ('Validar arquivo', False),
                ('Importar e conferir salvamento', True),
                ('Reparar colunas do banco (JSON)', 'reparar'),
            )):
                botao_acao = ttk.Button(
                    acoes,
                    text=titulo,
                    command=lambda g=gravar: self.iniciar(g)
                )
                botao_acao.grid(row=indice // 2, column=indice % 2, sticky='ew', padx=4, pady=3)
                self.controles.append(botao_acao)

            self.botao_cancelar = ttk.Button(
                acoes,
                text='Cancelar',
                command=self.cancelar,
                state='disabled'
            )
            self.botao_cancelar.grid(row=0, column=2, rowspan=2, padx=4)
            acoes.columnconfigure(0, weight=1)
            acoes.columnconfigure(1, weight=1)

            self.progresso = ttk.Progressbar(
                base,
                maximum=100
            )
            self.progresso.pack(fill='x', pady=10)
            self.tempo = ttk.Label(base, text='Barra por etapa; 100% dos lotes não significa COMMIT confirmado.')
            self.tempo.pack(anchor='w')

            self.status = ttk.Label(
                base,
                text='Aguardando arquivo.',
                wraplength=1050
            )
            self.status.pack(anchor='w')

            self.log = tk.Text(
                base,
                height=9,
                wrap='word',
                state='disabled'
            )
            self.log.pack(fill='x', pady=10)

            ttk.Label(
                base,
                text=(
                    'Pre-visualizacao: primeiros 2.000 registros. '
                    'A importacao processa todos os registros validos.'
                )
            ).pack(anchor='w')

            quadro = ttk.Frame(base)
            quadro.pack(fill='both', expand=True)

            colunas = (
                'cnes',
                'nome',
                'uf',
                'municipio',
                'acao'
            )

            self.arvore = ttk.Treeview(
                quadro,
                columns=colunas,
                show='headings'
            )

            titulos = {
                'cnes': ('CNES', 85),
                'nome': ('Nome / razao social', 340),
                'uf': ('UF', 45),
                'municipio': ('Municipio', 170),
                'acao': ('Resultado', 220),
            }

            for coluna in colunas:
                titulo, largura = titulos[coluna]
                self.arvore.heading(coluna, text=titulo)
                self.arvore.column(
                    coluna,
                    width=largura
                )

            barra = ttk.Scrollbar(
                quadro,
                orient='vertical',
                command=self.arvore.yview
            )

            self.arvore.configure(
                yscrollcommand=barra.set
            )

            barra.pack(side='right', fill='y')
            self.arvore.pack(fill='both', expand=True)

        def report_callback_exception(self, exc, val, tb):
            texto = ''.join(
                traceback.format_exception(exc, val, tb)
            )

            self.escrever(texto)
            print(texto, file=sys.stderr, flush=True)
            messagebox.showerror(
                'Erro',
                str(val),
                parent=self
            )

        def escolher(self):
            caminho = filedialog.askopenfilename(
                parent=self,
                filetypes=[
                    ('CSV/TSV/TXT', '*.csv *.tsv *.txt'),
                    ('Todos', '*.*'),
                ]
            )

            if caminho:
                self.caminho.set(caminho)

        def escrever(self, texto):
            self.log.configure(state='normal')
            self.log.insert(
                'end',
                f'[{datetime.now():%H:%M:%S}] {texto}\n'
            )
            self.log.see('end')
            self.log.configure(state='disabled')

        def bloquear(self, valor):
            for controle in self.controles:
                controle.configure(
                    state='disabled' if valor else 'normal'
                )

            self.botao_cancelar.configure(
                state='normal' if valor else 'disabled'
            )

        def cancelar(self):
            self.stop.set()
            self.escrever(
                'Cancelamento solicitado. '
                'Se COMMIT ja iniciou, aguarde.'
            )

        def iniciar(self, gravar):
            if self.executando:
                return

            caminho = self.caminho.get().strip()

            if gravar not in ('reparar', 'testar') and not Path(caminho).is_file():
                messagebox.showerror(
                    'Arquivo',
                    'Selecione um arquivo valido.',
                    parent=self
                )
                return

            valores = {
                chave: variavel.get()
                for chave, variavel in self.campos.items()
            }

            configuracao = {}

            if gravar:
                try:
                    configuracao = preparar_config(valores)
                except ValueError as erro:
                    messagebox.showerror(
                        'Conexao',
                        str(erro),
                        parent=self
                    )
                    return

                if gravar != 'testar':
                    confirmado = messagebox.askyesno(
                        'Confirmar',
                        (
                            'Backup realizado?\n\n'
                            f"Host: {configuracao['host']}:"
                            f"{configuracao['port']}\n"
                            f"Banco: {configuracao['database']}\n"
                            f'Tabela: {TABELA}\n'
                            'Senha capturada: SIM (conteudo oculto)\n\n'
                            'Continuar?'
                        ),
                        parent=self
                    )

                    if not confirmado:
                        return

                self.escrever(
                    'Senha capturada: SIM. '
                    'Conteudo nao sera registrado.'
                )

            self.executando = True
            self.stop.clear()
            self.progresso.stop()
            self.progresso.configure(mode='determinate', value=0)
            self._fase = None
            self._inicio_trabalho = time.monotonic()
            self.arvore.delete(
                *self.arvore.get_children()
            )
            self.bloquear(True)

            threading.Thread(
                target=self.worker,
                args=(
                    caminho,
                    dict(configuracao),
                    self.atualizar.get(),
                    gravar
                ),
                daemon=True
            ).start()

        def worker(self, caminho, configuracao, atualizar, gravar):
            conn = None
            emit = lambda evento, valor: self.eventos.put((evento, valor))
            try:
                if gravar:
                    relatar_etapa(emit, 'Testando conexão MySQL', detalhe='Somente autenticação e SELECT de diagnóstico; sem gravar dados.')
                    emit('log', f"Testando host={configuracao['host']}:{configuracao['port']}; usuário={configuracao['user']}; banco={configuracao['database']}. Senha não será registrada.")
                    conn, info = conectar_mysql(configuracao)
                    emit('log', f"Conexão autenticada: banco={info['banco']}; conta={info['conta']}; origem={info['origem']}; servidor={info['servidor']}.")
                    if gravar == 'testar':
                        emit('validado', 'Teste de conexão aprovado. Nenhuma gravação ou criação de tabela foi feita. A senha permanece no campo.')
                        return
                    if gravar != 'reparar':
                        conn.close()
                        conn = None
                        emit('log', 'Acesso confirmado antes da leitura do arquivo. A conexão será reaberta para importar.')
                if gravar != 'reparar':
                    emit('log', 'Validando arquivo, preservando separadores dentro de aspas...')
                    registros, encoding, separador, campos, sem_nome = ler_csv(caminho, self.stop, emit)
                    emit('log', f'{len(registros)} CNES únicos; {campos} campos; encoding={encoding}; separador={separador!r}.')
                    emit('log', f'{sem_nome} sem nome fantasia; será usada razão social ou CNES na coluna de exibição.')
                    relatar_etapa(emit, 'Preparando pré-visualização', detalhe='Até 2.000 registros, sem limitar a importação.')
                    for cnes, dados in itertools.islice(registros.items(), 2000):
                        if self.stop.is_set(): raise Cancelado('Cancelado antes da importação.')
                        p = projetar(dados)
                        emit('linha', (cnes,p['nome_fantasia'],p['uf'],p['municipio'],'VALIDADO'))
                    if not gravar:
                        emit('validado', 'Arquivo validado. Nenhuma conexão ou gravação. A senha foi mantida no campo.')
                        return
                if self.stop.is_set():
                    raise Cancelado('Cancelado antes da gravação.')
                if conn is None:
                    relatar_etapa(emit, 'Reabrindo conexão MySQL')
                    conn, info = conectar_mysql(configuracao)
                if gravar == 'reparar':
                    emit('log', 'Reparo: reconstruindo colunas com dados_originais + correcoes já existentes.')
                    registros = ler_banco(conn, self.stop, emit)
                    atualizar = False
                else:
                    emit('limpar_preview', None)
                resultado = importar(conn, registros, atualizar, self.stop, emit)
                emit('sucesso', f"CONCLUÍDO: {resultado['inseridos']} inseridos; {resultado['atualizados']} atualizados/reparados; {resultado['ignorados']} sem mudança; {resultado['conferidos']} gravações relidas após COMMIT.")
            except Exception as erro:
                texto = str(erro)
                senha = configuracao.get('password', '')
                if senha:
                    texto = texto.replace(senha, '[SENHA OCULTA]')
                emit('erro', texto)
            finally:
                if conn is not None:
                    try:
                        conn.close()
                    except Exception:
                        pass
                configuracao.pop('password', None)
                emit('fim', None)

        def exibir_etapa(self, info):
            fase = info['fase']
            atual, total = info.get('atual'), info.get('total')
            if fase != self._fase:
                self._fase = fase
                self._inicio_fase = time.monotonic()
                self.progresso.stop()
                self.progresso.configure(mode='determinate', value=0)
            elapsed = time.monotonic() - self._inicio_fase
            if total is None or total <= 0 or atual is None:
                if str(self.progresso.cget('mode')) != 'indeterminate':
                    self.progresso.configure(mode='indeterminate', value=0)
                    self.progresso.start(15)
                self.status.configure(text=f"{fase} | {info.get('detalhe', '')}")
                detail = 'Duração restante ainda não estimável.'
            else:
                self.progresso.stop()
                pct = min(100, max(0, atual / total * 100))
                self.progresso.configure(mode='determinate', value=pct)
                speed = atual / elapsed if elapsed > 0 else 0
                unit = info.get('unidade', '')
                eta = max(0, (total-atual)/speed) if speed > 0 else None
                detail = f'{speed:,.0f} {unit}/s'
                if eta is not None:
                    detail += f' | estimativa nesta etapa: {int(eta)//60:02d}:{int(eta)%60:02d}'
                self.status.configure(text=f"{fase}: {pct:.1f}% | {atual:,}/{total:,} {unit} | {info.get('detalhe', '')}")
            self.tempo.configure(text=f'Tempo nesta etapa: {int(elapsed)//60:02d}:{int(elapsed)%60:02d} | {detail}')

        def processar(self):
            for _ in range(300):
                try:
                    evento, valor = self.eventos.get_nowait()
                except queue.Empty:
                    break

                if evento == 'limpar_preview':
                    self.arvore.delete(*self.arvore.get_children())

                elif evento == 'linha':
                    self.arvore.insert(
                        '',
                        'end',
                        values=valor
                    )

                elif evento == 'etapa':
                    self.exibir_etapa(valor)

                elif evento == 'progresso':
                    posicao, total, estatisticas = valor
                    self.exibir_etapa(dict(fase='Gravando lotes (sem COMMIT)', atual=posicao, total=total, unidade='registros',
                        detalhe=f"Novos: {estatisticas['inseridos']} | Atualizados: {estatisticas['atualizados']} | Sem mudança: {estatisticas['ignorados']} | COMMIT e conferência pendentes."))

                elif evento in (
                    'log',
                    'sucesso',
                    'erro',
                    'validado'
                ):
                    self.escrever(valor)

                    if evento != 'log':
                        self.progresso.stop()
                        self.progresso.configure(mode='determinate')
                        if evento in ('sucesso', 'validado'):
                            self.progresso['value'] = 100
                        self.status.configure(text=valor)
                        seconds = int(time.monotonic()-self._inicio_trabalho)
                        self.tempo.configure(text=f'Tempo total: {seconds//60:02d}:{seconds%60:02d}. Resultado: {evento}.')

                    if evento in ('sucesso', 'erro'):
                        for item in self.arvore.get_children():
                            valores = list(
                                self.arvore.item(item, 'values')
                            )

                            if valores and 'PENDENTE' in valores[-1]:
                                valores[-1] = (
                                    'GRAVADO E CONFERIDO'
                                    if evento == 'sucesso'
                                    else (
                                        'NAO CONFIRMADO - VER LOG'
                                    )
                                )

                                self.arvore.item(
                                    item,
                                    values=valores
                                )

                elif evento == 'fim':
                    self.progresso.stop()
                    self.executando = False
                    self.bloquear(False)

            self.after(100, self.processar)

        def fechar(self):
            if self.executando:
                messagebox.showinfo(
                    'Aguarde',
                    'Solicite cancelamento e aguarde antes de fechar.',
                    parent=self
                )
                return

            self.campos['password'].set('')
            self.destroy()

    print('Criando janela...', flush=True)

    aplicativo = App()

    print('Interface em execucao.', flush=True)
    aplicativo.mainloop()

    print('Janela encerrada.', flush=True)





def main():
    import argparse
    parser = argparse.ArgumentParser(description='Importador CNES 2.1: GUI, validação ou reparo de colunas estruturadas.')
    parser.add_argument('--arquivo')
    parser.add_argument('--importar', action='store_true')
    parser.add_argument('--reparar-banco', action='store_true')
    parser.add_argument('--atualizar', action='store_true')
    parser.add_argument('--lote', type=int, default=500)
    parser.add_argument('--confirmar', action='store_true', help='Confirma a escrita sem pergunta interativa. Exige backup prévio.')
    args = parser.parse_args()
    if not any((args.arquivo,args.importar,args.reparar_banco)):
        try:
            executar_interface()
            return 0
        except Exception:
            texto = traceback.format_exc()
            print(texto,file=sys.stderr)
            try:
                Path(__file__).with_name('importador_saude_erro.log').write_text(texto,encoding='utf-8')
            except OSError:
                pass
            return 1
    if args.importar and args.reparar_banco:
        parser.error('Escolha importar arquivo OU reparar banco.')
    if not args.reparar_banco and not args.arquivo:
        parser.error('Informe --arquivo ou --reparar-banco.')
    if not 1 <= args.lote <= 2000:
        parser.error('--lote deve estar entre 1 e 2000.')
    stop = threading.Event()
    conn = None
    cfg = {}
    def emit(event, value):
        if event == 'linha':
            return
        print(f'{event}: {value}', flush=True)
    try:
        if args.arquivo:
            registros, encoding, sep, fields, sem_nome = ler_csv(args.arquivo,stop)
            emit('validado',f'{len(registros)} CNES; {fields} campos; {encoding}; separador={sep!r}; sem fantasia={sem_nome}.')
        if not args.importar and not args.reparar_banco:
            return 0
        import getpass
        cfg = preparar_config({'host':os.getenv('DB_HOST','localhost'),'port':os.getenv('DB_PORT','3306'),
            'user':os.getenv('DB_USER',''),'database':os.getenv('DB_NAME',''),
            'password':os.getenv('DB_PASSWORD') or getpass.getpass('Senha MySQL: '),'ssl_ca':os.getenv('DB_SSL_CA','')})
        if not args.confirmar and input(f"Banco {cfg['database']} em {cfg['host']}; tabela {TABELA}. Backup realizado? Digite IMPORTAR: ") != 'IMPORTAR':
            return 1
        import pymysql
        conn, info = conectar_mysql(cfg)
        emit('conexao', f"Banco={info['banco']}; conta={info['conta']}; origem={info['origem']}.")
        if args.reparar_banco:
            registros = ler_banco(conn,stop,emit)
        stats = importar(conn,registros,args.atualizar and not args.reparar_banco,stop,emit,args.lote)
        emit('sucesso',stats)
        return 0
    except KeyboardInterrupt:
        print('Interrompido. Se o COMMIT já havia iniciado, confira o banco antes de repetir.',file=sys.stderr)
        return 130
    except Exception as exc:
        message=str(exc)
        if cfg.get('password'):
            message=message.replace(cfg['password'],'[SENHA OCULTA]')
        print(message,file=sys.stderr)
        return 1
    finally:
        if conn:
            conn.close()
        cfg.pop('password',None)

if __name__ == '__main__':
    sys.exit(main())
