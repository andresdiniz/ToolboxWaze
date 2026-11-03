#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""ToolboxWaze - Importador CNES 1.4.

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
import unicodedata
from datetime import datetime
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


def ler_csv(caminho, stop):
    bruto = Path(caminho).read_bytes()

    for encoding in ('utf-8-sig', 'cp1252', 'latin-1'):
        try:
            texto = bruto.decode(encoding)
            break
        except UnicodeDecodeError:
            continue
    else:
        raise ValueError('Nao foi possivel identificar o encoding do arquivo.')

    if not texto.strip():
        raise ValueError('Arquivo vazio.')

    separador = max(
        ('\t', ';', ','),
        key=lambda caractere: texto.splitlines()[0].count(caractere)
    )

    if not texto.splitlines()[0].count(separador):
        raise ValueError('Separador nao identificado.')

    reader = csv.reader(
        io.StringIO(texto),
        delimiter=separador,
        strict=True
    )

    cabecalho = [
        unicodedata.normalize('NFC', coluna.strip())
        for coluna in next(reader)
    ]

    indices = [
        indice
        for indice, coluna in enumerate(cabecalho)
        if coluna
    ]

    nomes = [cabecalho[indice] for indice in indices]

    if len(nomes) != len(set(nomes)):
        raise ValueError('Cabecalhos duplicados.')

    obrigatorias = {
        'CNES',
        'UF',
        'NOME FANTASIA',
        'MUNICÍPIO',
        'STATUS DO ESTABELECIMENTO',
    }

    if not obrigatorias.issubset(nomes):
        faltando = sorted(obrigatorias - set(nomes))
        raise ValueError(
            'Colunas ausentes: ' + ', '.join(faltando)
        )

    registros = {}
    erros = []

    for linha in reader:
        if stop.is_set():
            raise Cancelado('Validacao cancelada. Nada gravado.')

        if not any(valor.strip() for valor in linha):
            continue

        try:
            if len(linha) <= max(indices):
                raise ValueError('Linha incompleta.')

            if any(
                valor.strip()
                for indice, valor in enumerate(linha)
                if indice not in indices
            ):
                raise ValueError(
                    'Conteudo em coluna excedente ou sem nome.'
                )

            dados = {
                cabecalho[indice]: linha[indice].strip()
                for indice in indices
            }

            dados = {
                chave: (
                    ''
                    if valor.upper() in VAZIOS
                    else valor
                )
                for chave, valor in dados.items()
            }

            if not re.fullmatch(r'\d{1,7}', dados['CNES']):
                raise ValueError('CNES invalido.')

            dados['CNES'] = dados['CNES'].zfill(7)
            dados['UF'] = dados['UF'].upper()

            if dados['UF'] not in UFS:
                raise ValueError('UF invalida.')

            if dados.get('location'):
                partes = re.split(
                    r'[\s;,]+',
                    dados['location'].strip()
                )

                if len(partes) != 2:
                    raise ValueError(
                        'Location deve conter latitude e longitude.'
                    )

                latitude, longitude = map(float, partes)

                if not (
                    -90 <= latitude <= 90
                    and -180 <= longitude <= 180
                ):
                    raise ValueError('Coordenadas invalidas.')

                dados['location'] = (
                    f'{latitude:.8f} {longitude:.8f}'
                )

            cnes = dados['CNES']

            if cnes in registros and registros[cnes] != dados:
                raise ValueError(
                    f'CNES duplicado com dados divergentes: {cnes}'
                )

            registros[cnes] = dados

        except (ValueError, OverflowError) as erro:
            erros.append(
                f'Linha {reader.line_num}: {erro}'
            )

    if erros:
        raise ValueError(
            '\n'.join(erros[:20])
            + f'\nTotal: {len(erros)} erros. Nada gravado.'
        )

    if not registros:
        raise ValueError('Nenhum registro valido.')

    sem_nome = sum(
        not registro.get('NOME FANTASIA')
        for registro in registros.values()
    )

    return registros, encoding, separador, len(nomes), sem_nome


def coluna_existe(cursor, tabela, coluna):
    cursor.execute(
        '''
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = %s
          AND COLUMN_NAME = %s
        ''',
        (tabela, coluna)
    )

    return int(cursor.fetchone()[0]) > 0


def indice_existe(cursor, tabela, indice):
    cursor.execute(
        '''
        SELECT COUNT(DISTINCT INDEX_NAME)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = %s
          AND INDEX_NAME = %s
        ''',
        (tabela, indice)
    )

    return int(cursor.fetchone()[0]) > 0


def garantir_estrutura(conn):
    """Cria somente estrutura ausente. Nao remove nem altera colunas existentes."""

    with conn.cursor() as cursor:
        cursor.execute(
            '''
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = %s
            ''',
            (TABELA,)
        )

        if int(cursor.fetchone()[0]) == 0:
            cursor.execute(
                f'''
                CREATE TABLE {TABELA} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    cnes VARCHAR(7) NOT NULL,
                    dados_originais JSON NOT NULL,
                    correcoes JSON NOT NULL,
                    importado_em DATETIME NOT NULL,
                    editado_em DATETIME DEFAULT NULL,
                    editado_por VARCHAR(180) DEFAULT NULL,
                    versao INT UNSIGNED NOT NULL DEFAULT 1,
                    PRIMARY KEY (id),
                    UNIQUE KEY UNIQ_ESTABELECIMENTO_CNES (cnes)
                )
                ENGINE=InnoDB
                DEFAULT CHARSET=utf8mb4
                COLLATE=utf8mb4_unicode_ci
                '''
            )
            return

        colunas = {
            'cnes': 'VARCHAR(7) NOT NULL',
            'dados_originais': 'JSON NOT NULL',
            'correcoes': "JSON NOT NULL DEFAULT (JSON_OBJECT())",
            'importado_em': 'DATETIME NOT NULL',
            'editado_em': 'DATETIME DEFAULT NULL',
            'editado_por': 'VARCHAR(180) DEFAULT NULL',
            'versao': 'INT UNSIGNED NOT NULL DEFAULT 1',
        }

        for coluna, definicao in colunas.items():
            if not coluna_existe(cursor, TABELA, coluna):
                cursor.execute(
                    f'ALTER TABLE {TABELA} '
                    f'ADD COLUMN {coluna} {definicao}'
                )

        if not indice_existe(
            cursor,
            TABELA,
            'UNIQ_ESTABELECIMENTO_CNES'
        ):
            cursor.execute(
                f'ALTER TABLE {TABELA} '
                f'ADD UNIQUE KEY UNIQ_ESTABELECIMENTO_CNES (cnes)'
            )


def conferir_estrutura(conn):
    garantir_estrutura(conn)

    with conn.cursor() as cursor:
        cursor.execute(
            f'''
            SELECT id, cnes, dados_originais, correcoes,
                   importado_em, editado_em, editado_por, versao
            FROM {TABELA}
            LIMIT 0
            '''
        )
        cursor.fetchall()

        cursor.execute(
            '''
            SELECT ENGINE
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = %s
            ''',
            (TABELA,)
        )

        resultado = cursor.fetchone()

        if not resultado or str(resultado[0]).upper() != 'INNODB':
            raise ValueError('Tabela precisa usar InnoDB.')

        cursor.execute(
            f'''
            SHOW INDEX FROM {TABELA}
            WHERE Key_name = %s
            ''',
            ('UNIQ_ESTABELECIMENTO_CNES',)
        )

        if not cursor.fetchone():
            raise ValueError('Indice UNIQUE em cnes nao encontrado.')

    conn.rollback()


def importar(conn, registros, atualizar, stop, emit):
    conferir_estrutura(conn)
    emit('log', 'Estrutura e indice UNIQUE conferidos.')

    estatisticas = {
        'inseridos': 0,
        'atualizados': 0,
        'ignorados': 0,
    }

    esperados = {}
    commit_iniciado = False

    try:
        conn.begin()

        with conn.cursor() as cursor:
            total = len(registros)

            for posicao, (cnes, dados) in enumerate(
                registros.items(),
                start=1
            ):
                if stop.is_set():
                    raise Cancelado('Cancelado antes do COMMIT.')

                cursor.execute(
                    f'''
                    SELECT id, dados_originais, correcoes
                    FROM {TABELA}
                    WHERE cnes = %s
                    FOR UPDATE
                    ''',
                    (cnes,)
                )

                anterior = cursor.fetchone()
                conteudo = json.dumps(
                    dados,
                    ensure_ascii=False,
                    allow_nan=False
                )

                if anterior is None:
                    cursor.execute(
                        f'''
                        INSERT INTO {TABELA}
                        (
                            cnes,
                            dados_originais,
                            correcoes,
                            importado_em,
                            versao
                        )
                        VALUES
                        (%s, %s, %s, UTC_TIMESTAMP(), 1)
                        ''',
                        (cnes, conteudo, '{}')
                    )

                    esperados[cnes] = (dados, {})
                    estatisticas['inseridos'] += 1
                    acao = 'INSERCAO PENDENTE'

                elif not atualizar:
                    estatisticas['ignorados'] += 1
                    acao = 'IGNORADO'

                else:
                    original = objeto(anterior[1])
                    correcoes = objeto(anterior[2], True)

                    if original == dados:
                        estatisticas['ignorados'] += 1
                        acao = 'SEM MUDANCA'

                    else:
                        competencia_antiga = str(
                            original.get('COMPETÊNCIA', '')
                        )
                        competencia_nova = str(
                            dados.get('COMPETÊNCIA', '')
                        )

                        if not (
                            COMP.fullmatch(competencia_antiga)
                            and COMP.fullmatch(competencia_nova)
                        ):
                            raise ValueError(
                                f'CNES {cnes}: competencia invalida; '
                                'esperado AAAA/MM.'
                            )

                        if competencia_nova < competencia_antiga:
                            raise ValueError(
                                f'CNES {cnes}: competencia '
                                f'{competencia_nova} anterior a '
                                f'{competencia_antiga}.'
                            )

                        cursor.execute(
                            f'''
                            UPDATE {TABELA}
                            SET dados_originais = %s,
                                importado_em = UTC_TIMESTAMP(),
                                versao = versao + 1
                            WHERE id = %s
                            ''',
                            (conteudo, anterior[0])
                        )

                        esperados[cnes] = (dados, correcoes)
                        estatisticas['atualizados'] += 1
                        acao = 'ATUALIZACAO PENDENTE'

                if posicao <= 2000:
                    emit(
                        'linha',
                        (
                            cnes,
                            nome(dados),
                            dados['UF'],
                            dados['MUNICÍPIO'],
                            acao
                        )
                    )

                if posicao % 100 == 0 or posicao == total:
                    emit(
                        'progresso',
                        (
                            posicao,
                            total,
                            dict(estatisticas)
                        )
                    )

        if stop.is_set():
            raise Cancelado('Cancelado antes do COMMIT.')

        emit(
            'log',
            'Enviando COMMIT. Nao feche o programa.'
        )

        commit_iniciado = True
        conn.commit()

    except BaseException as erro:
        if commit_iniciado:
            raise ResultadoIncerto(
                'Erro durante COMMIT: resultado incerto. '
                'Confira o banco antes de repetir.'
            ) from erro

        try:
            conn.rollback()
            emit(
                'log',
                'ROLLBACK concluido: gravacoes desta execucao desfeitas.'
            )
        except Exception:
            emit(
                'log',
                'ROLLBACK nao confirmado; confira o banco.'
            )

        raise

    emit(
        'log',
        'COMMIT aceito. Conferindo registros gravados...'
    )

    try:
        codigos = list(esperados)

        with conn.cursor() as cursor:
            for inicio in range(0, len(codigos), 200):
                lote = codigos[inicio:inicio + 200]
                placeholders = ','.join(['%s'] * len(lote))

                cursor.execute(
                    f'''
                    SELECT cnes, dados_originais, correcoes
                    FROM {TABELA}
                    WHERE cnes IN ({placeholders})
                    ''',
                    lote
                )

                encontrados = {
                    str(cnes): (
                        objeto(dados),
                        objeto(correcoes, True)
                    )
                    for cnes, dados, correcoes in cursor.fetchall()
                }

                for cnes in lote:
                    if encontrados.get(cnes) != esperados[cnes]:
                        raise ValueError(
                            'Releitura divergente/ausente no CNES '
                            + cnes
                        )

                if inicio % 10000 == 0 or inicio + 200 >= len(codigos):
                    emit(
                        'log',
                        'Conferencia: '
                        f'{min(inicio + 200, len(codigos))}'
                        f'/{len(codigos)}.'
                    )

        conn.rollback()

    except Exception as erro:
        raise ResultadoIncerto(
            'COMMIT aceito, mas conferencia incompleta: '
            + str(erro)
            + '. Nao presuma rollback.'
        ) from erro

    estatisticas['conferidos'] = len(esperados)

    return estatisticas


def executar_interface():
    import tkinter as tk
    from tkinter import ttk, filedialog, messagebox

    class App(tk.Tk):
        def __init__(self):
            super().__init__()

            self.title('ToolboxWaze - Importador CNES 1.4')
            self.geometry('1150x800')
            self.minsize(850, 620)

            self.eventos = queue.Queue()
            self.stop = threading.Event()
            self.executando = False
            self.campos = {}
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
                text='Estabelecimentos de saude - CNES 1.4',
                font=('Segoe UI', 16, 'bold')
            ).pack(anchor='w')

            ttk.Label(
                base,
                text=(
                    'Nome fantasia vazio permitido | '
                    'A senha nao e apagada apos validar o arquivo | '
                    'Estrutura ausente e criada automaticamente'
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
                'host': os.getenv('DB_HOST', '212.85.3.243'),
                'port': os.getenv('DB_PORT', '3306'),
                'user': os.getenv(
                    'DB_USER',
                    'u629736858_wazetoolbox'
                ),
                'database': os.getenv(
                    'DB_NAME',
                    'u629736858_wazetoolbox'
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
                self.controles.append(entrada)

            caixa.columnconfigure(1, weight=1)
            caixa.columnconfigure(3, weight=1)

            self.mostrar_senha = tk.BooleanVar(value=False)

            entrada_senha = self.controles[
                2 + list(padroes).index('password')
            ]

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
                    '(preserva correcoes manuais)'
                ),
                variable=self.atualizar
            ).pack(anchor='w', pady=8)

            acoes = ttk.Frame(base)
            acoes.pack(fill='x')

            for titulo, gravar in (
                ('Validar arquivo', False),
                ('Importar e conferir salvamento', True),
            ):
                ttk.Button(
                    acoes,
                    text=titulo,
                    command=lambda g=gravar: self.iniciar(g)
                ).pack(side='left', padx=4)

            self.botao_cancelar = ttk.Button(
                acoes,
                text='Cancelar',
                command=self.cancelar,
                state='disabled'
            )
            self.botao_cancelar.pack(side='left', padx=4)

            self.progresso = ttk.Progressbar(
                base,
                maximum=100
            )
            self.progresso.pack(fill='x', pady=10)

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

            if not Path(caminho).is_file():
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
            self.progresso['value'] = 0
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

            emit = lambda evento, valor: self.eventos.put(
                (evento, valor)
            )

            try:
                emit('log', 'Validando arquivo...')

                (
                    registros,
                    encoding,
                    separador,
                    campos,
                    sem_nome
                ) = ler_csv(caminho, self.stop)

                emit(
                    'log',
                    f'{len(registros)} CNES unicos; '
                    f'{campos} campos; '
                    f'encoding={encoding}; '
                    f'separador={separador!r}.'
                )

                emit(
                    'log',
                    f'{sem_nome} registros sem nome fantasia: '
                    'aceitos e preservados.'
                )

                if self.stop.is_set():
                    raise Cancelado(
                        'Cancelado antes da conexao.'
                    )

                if not gravar:
                    emit(
                        'validado',
                        'Arquivo validado. Nenhuma conexao ou gravacao. '
                        'A senha foi mantida no campo.'
                    )
                    return

                if not configuracao.get('password'):
                    raise ValueError(
                        'Senha nao capturada. '
                        'Digite novamente e reinicie a importacao.'
                    )

                try:
                    import pymysql
                except ImportError:
                    raise ValueError(
                        'Instale: python -m pip install pymysql'
                    )

                ca = configuracao.pop('ssl_ca', '')

                configuracao.update(
                    charset='utf8mb4',
                    autocommit=False,
                    connect_timeout=20,
                    read_timeout=120,
                    write_timeout=120
                )

                if ca:
                    configuracao.update(
                        ssl_ca=ca,
                        ssl_verify_cert=True,
                        ssl_verify_identity=True
                    )

                emit(
                    'log',
                    'Conectando ao MySQL com senha preenchida...'
                )

                conn = pymysql.connect(**configuracao)

                with conn.cursor() as cursor:
                    cursor.execute(
                        'SELECT DATABASE(), @@hostname'
                    )
                    banco, servidor = cursor.fetchone()

                conn.rollback()

                if banco != configuracao['database']:
                    raise ValueError(
                        'Banco conectado difere do informado.'
                    )

                emit(
                    'log',
                    f'Conectado: banco={banco}; servidor={servidor}.'
                )

                resultado = importar(
                    conn,
                    registros,
                    atualizar,
                    self.stop,
                    emit
                )

                emit(
                    'sucesso',
                    (
                        'CONCLUIDO: '
                        f"{resultado['inseridos']} inseridos; "
                        f"{resultado['atualizados']} atualizados; "
                        f"{resultado['ignorados']} ignorados; "
                        f"{resultado['conferidos']} gravacoes "
                        'relidas apos COMMIT.'
                    )
                )

            except Exception as erro:
                texto = str(erro)
                senha = configuracao.get('password', '')

                if senha:
                    texto = texto.replace(
                        senha,
                        '[SENHA OCULTA]'
                    )

                emit('erro', texto)

            finally:
                if conn is not None:
                    try:
                        conn.close()
                    except Exception:
                        pass

                configuracao.pop('password', None)
                emit('fim', None)

        def processar(self):
            for _ in range(300):
                try:
                    evento, valor = self.eventos.get_nowait()
                except queue.Empty:
                    break

                if evento == 'linha':
                    self.arvore.insert(
                        '',
                        'end',
                        values=valor
                    )

                elif evento == 'progresso':
                    posicao, total, estatisticas = valor

                    self.progresso['value'] = (
                        posicao / total * 100
                    )

                    self.status.configure(
                        text=(
                            f'{posicao}/{total} | '
                            f"Novos: {estatisticas['inseridos']} | "
                            'Atualizados: '
                            f"{estatisticas['atualizados']} | "
                            'Aguardando COMMIT e conferencia.'
                        )
                    )

                elif evento in (
                    'log',
                    'sucesso',
                    'erro',
                    'validado'
                ):
                    self.escrever(valor)

                    if evento != 'log':
                        self.status.configure(text=valor)

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
    print(
        f'Arquivo: {Path(__file__).resolve()}',
        flush=True
    )

    print(
        f'Python: {sys.executable}',
        flush=True
    )

    try:
        executar_interface()
        return 0

    except Exception:
        texto = traceback.format_exc()
        print(texto, file=sys.stderr, flush=True)

        try:
            Path(__file__).with_name(
                'importador_saude_erro.log'
            ).write_text(
                texto,
                encoding='utf-8'
            )
        except OSError:
            pass

        return 1


if __name__ == '__main__':
    sys.exit(main())