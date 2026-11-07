# API WME

Implementacao para a branch feature/userscript-wme, sem alteracao de main. Requer migrar banco MySQL/MariaDB InnoDB. Arquivos novos, sem substituir controllers existentes.

## Instalar

Execute `php bin/console doctrine:migrations:migrate` e `php bin/console cache:clear`. Confira rotas com `php bin/console debug:router | grep api_wme`. Garanta permissao de gravacao em var/wme_uploads; nao exponha essa pasta na web. Limites PHP/webserver devem aceitar multipart de 5 MB mais overhead; payload JSON limitado a 256 KB.

Autenticacao pelo ApiTokenAuthenticator existente: header X-API-TOKEN, token pessoal de 64 hex e conta aprovada. Nao altere o userscript v3 para Bearer: o firewall desta branch aceita X-API-TOKEN. A API confere token mesmo se houver sessao web. Nao usa cookies Waze.

## Habilitar um formulario

Em FormBuilder.configuracoes definir wme_api_enabled=true. Formularios nao habilitados nao aparecem. wme_roles e lista OR de papeis literais; por exemplo ["ROLE_CHAMP"] nao inclui admin automaticamente. Regras expira_em, mostrar_para=visitante e limite_envios sao respeitadas. Nao habilitar um formulario restrito sem configurar wme_roles e suas regras reais. Tipos legados hardcoded do SolicitacaoType NAO sao publicados automaticamente: esta entrega adapta FormBuilder, nao inventa schema legado.

Tipos aceitos: text, textarea, number, email, url, date, select, radio, checkbox, file, image (convertido a file). Outros tipos e validacoes customizadas recusam a publicacao inteira do formulario, em vez de omitir campos obrigatorios.

FormBuilderCampo.opcoes e um objeto de configuracoes. Opcoes de select/radio em opcoes.opcoes, lista de strings ou objetos value/label. Condicional existente com operador = e convertido para mostrarSe; outros operadores exigem adaptador futuro. Tambem aceita mostrarSe diretamente, condicoes AND, valores arrays como OR. Configuracoes min, max, minLength, maxLength, inteiro, valorObrigatorio, preenchimento, zoomMinimo, maxArquivos, maxBytes, mimeTypes sao exportadas. Validacao somente permalink ou wkt (prefixo, nao geometria completa).

Exemplo campo permalink: opcoes={"validacao":"permalink","zoomMinimo":15,"preenchimento":{"origem":"wme.permalink"}}. Checkbox obrigatorio exige true. Uploads somente JPEG/PNG/WebP verificados, ate 1 MB cada e 5 MB total.

## Contrato userscript v3

GET /api/wme/bootstrap -> usuario {id,nome,wazeNickname}, formularios [{slug,nome}].
GET /api/wme/formularios/{slug} -> slug,versao,nome,descricao,campos.
POST /api/wme/solicitacoes -> multipart com payload JSON e anexos[CHAVE][]. Payload: formulario,versaoFormulario,respostas,contexto,clientRequestId (UUID). Identidade/status/responsaveis nunca sao aceitos do cliente. Resposta: estado=concluido,solicitacao={id,url}.
GET /api/wme/envios/{clientRequestId} -> concluido com solicitacao ou nao_criado.
GET /api/wme/solicitacoes/{id}/anexos/{nome} -> download privado com token e voter SOLICITACAO_VER. Metadados dos anexos/contexto em Solicitacao.dados._wme; respostas em dadosDinamicos. Templates atuais nao ganham automaticamente botoes de anexos: usar esta rota ou integrar a visualizacao depois.

## Idempotencia

POST e consulta de resultado usam transacao e lock da linha de usuario, serializando envios da mesma conta. Se consulta chegar antes de POST tardio, cria tombstone nao_criado; POST posterior com a chave e recusado. Chave concluida retorna resultado, conteudo diferente retorna conflito. Hash inclui payload bruto decodificado e hashes dos arquivos: preservar mesmos valores/ordem para replay. Nao repetir POST automaticamente. Dados DB/ledger/notificacoes sao atomicos; imagens sao removidas no rollback. Falha abrupta de processo pode deixar arquivos orfaos privados: prever rotina de limpeza por referencia.

Historico/responsaveis/notificacoes seguem entidades atuais. E-mails sao despachados so depois do commit; falha de despacho e logada sem desfazer solicitacao. Nao ha outbox duravel para e-mail nesta entrega; requer reconciliacao se o processo morrer depois do commit. Limite de dez criacoes por minuto/conta, alem do limite de formulario. Consulta cria tombstones: limitar tambem trafego e retencao de ledger no proxy/operacao, sem remover chaves recentes que ainda possam ser repetidas.

## Validacao antes do deploy

Nao executar em producao sem testes de integracao. PHP nao estava disponivel no ambiente de preparacao: lint PHP e testes Symfony nao foram executados. Rodar php -l nos arquivos e migrations:status. Testar bootstrap 401/200, formularios habilitados/restritos, erro por campo, MIME/tamanho, rascunhos, duas requisicoes simultaneas com mesma chave, POST atrasado apos consulta, falha DB rollback e recuperacao apos timeout. A branch do userscript contem v1.3 antiga: instalar o v3 entregue separadamente; este commit nao atualiza o JavaScript.
