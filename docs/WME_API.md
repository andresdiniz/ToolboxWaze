# API WME (userscript de solicitações)

Controller único: `src/Controller/Api/WmeApiController.php` (prefixo `/api/wme`). Autenticação: `src/Security/WmeDeviceAuthenticator.php`.
Userscript: `public/userscripts/toolboxwaze-solicitacoes.user.js` (v4.0.0).

## Instalar / atualizar

1. `php bin/console doctrine:migrations:migrate` (cria `wme_dispositivo`; `wme_envio` já vem da migration anterior).
2. `php bin/console cache:clear` e confira `php bin/console debug:router | grep api_wme`.
3. `var/wme_uploads` com permissão de escrita e **fora** da web. Limites PHP/webserver: multipart de 5 MB + overhead; JSON do envio limitado a 256 KB.
4. Se o site estiver atrás de proxy/CDN, configure `framework.trusted_proxies`; o limite de cadastros por IP (30/hora) usa `Request::getClientIp()`.
5. Reinstale o userscript v4 no Tampermonkey.

## Conexão sem conta prévia

O script **não carrega token do site**. Ele gera 32 bytes aleatórios (64 hex) no primeiro cadastro e os guarda no storage do Tampermonkey. O servidor guarda só `sha256(token)` em `wme_dispositivo`.

1. `POST /api/wme/registrar` (header `X-API-TOKEN`, JSON `{email, wazeNickname, nome?, scriptVersion}`) → `202 {estado:"pendente", emailMascarado, emailEnviado, expiraEm, reenvioEm}`.
   - E-mail novo: cria `User` com `status=pending`, sem senha, sem papéis (aparece em /admin/users).
   - E-mail existente: nada na conta é alterado; resposta idêntica (não revela se a conta existe).
2. O dono do e-mail recebe um link (`/api/wme/confirmar/{id}/{verificador}`, 60 min, uso único). O GET mostra um botão (scanners de e-mail não consomem o link); o POST ativa o dispositivo.
3. O script faz polling de `GET /bootstrap` a cada 6 s enquanto o modal está aberto (máx. 20 min) e libera os formulários quando `estado=ativo`.

Dispositivo `pendente` só acessa `bootstrap`, `verificacao/reenviar` e `dispositivo/revogar`; o resto responde `403 email_nao_confirmado`. O apelido do WME é **declarado** pelo script (SDK `getUserInfo`), não verificado.

Compatibilidade: token pessoal gerado no site (`User.apiToken`, conta aprovada) continua aceito em `/api/wme/*` como dispositivo ativo.

## Rotas

| Método e rota | Auth | Resposta |
|---|---|---|
| `POST /registrar` | pública | 202 `pendente`; 409 `token_ja_registrado`; 422 `validacao`; 429 `muitas_tentativas` |
| `GET/POST /confirmar/{id}/{verificador}` | pública (HTML) | página de confirmação |
| `POST /verificacao/reenviar` | pendente | cooldown de 5 min (429 `aguarde` + `aguardarSegundos`) |
| `POST /dispositivo/revogar` | qualquer | `estado=revogado`; o script gera token novo |
| `GET /bootstrap` | pendente/ativo | `estado`, `usuario`, `formularios` |
| `GET /formularios/{slug}` | ativo | `slug,versao,nome,descricao,campos` |
| `POST /solicitacoes` | ativo | multipart: `payload` JSON + `anexos[CHAVE][]` → `concluido` + `{id,url}` |
| `GET /solicitacoes` | ativo | últimas 20 do próprio usuário (`id, formulario, status, statusLabel, criadaEm`) |
| `GET /envios/{clientRequestId}` | ativo | `concluido` ou `nao_criado` |
| `GET /solicitacoes/{id}/anexos/{nome}` | ativo | download privado: dono, responsáveis ou admin |

Erros trazem `codigo` estável + `message`. Códigos de autenticação (401): `token_invalido`, `token_desconhecido`, `dispositivo_revogado`. Outros: `email_nao_confirmado`, `conta_bloqueada` (403), `formulario_desatualizado`, `limite_formulario` (422), `limite_minuto`, `chave_reutilizada` (409). **Erros de validação agora retornam HTTP 422 com `errors` por campo** (antes voltavam 200).

Usuário `rejected` é bloqueado em todas as rotas (inclusive a confirmação do e-mail): rejeitar a conta no admin corta o acesso do script.

## Habilitar um formulário

Em `FormBuilder.configuracoes`: `wme_api_enabled=true`. `wme_roles` é lista OR de papéis literais (`["ROLE_CHAMP"]` não inclui admin); contas criadas pelo script não têm papéis, então só enxergam formulários **sem** `wme_roles`. Regras `expira_em`, `mostrar_para=visitante` e `limite_envios` (padrão 1 por e-mail; `0` = ilimitado) são respeitadas.

Tipos: text, textarea, number, email, url, date, select, radio, checkbox, file, image (vira file). Outros tipos ou validações customizadas recusam a publicação do formulário inteiro. Opções de select/radio em `opcoes.opcoes`. Propriedades exportadas: min, max, minLength, maxLength, inteiro, valorObrigatorio, preenchimento, zoomMinimo, maxArquivos, maxBytes, mimeTypes, mostrarSe. Validação `permalink` ou `wkt`. Exemplo permalink: `opcoes={"validacao":"permalink","zoomMinimo":15,"preenchimento":{"origem":"wme.permalink"}}`. Uploads: JPEG/PNG/WebP verificados, 1 MB cada, 5 MB total. Tipos legados hardcoded do `SolicitacaoType` não são publicados.

## Idempotência

Envio e consulta usam transação e lock da linha do usuário. Consulta antes de um POST tardio cria tombstone `nao_criado`; POST posterior com a mesma chave é recusado (409). Mesma chave + mesmo conteúdo devolve o resultado; conteúdo diferente, 409. O hash inclui o payload e o SHA-256 dos arquivos. Não repetir POST automaticamente. Limite de 10 criações/minuto por conta. E-mails saem só depois do commit (sem outbox durável).

## Abuso e limites do cadastro

- 30 cadastros/hora por IP (HMAC do IP, nunca o IP) e 3 dispositivos pendentes/hora por e-mail.
- Reenvio de confirmação: 1 a cada 5 min por dispositivo.
- E-mail de confirmação sai por transporte síncrono; o verificador não passa pela fila do banco.
- Contas novas ficam `pending` no admin; aprovar/rejeitar continua sendo o controle de acesso ao site.

## Validação antes do deploy

`php -l` nos arquivos novos e `doctrine:migrations:status`. Testar: registro novo e com e-mail existente (mesma resposta), link de e-mail (GET não ativa, POST ativa, reuso e expiração), `bootstrap` pendente/ativo/revogado, formulário restrito, erro de validação 422, MIME/tamanho, duas requisições simultâneas com a mesma chave, POST atrasado após consulta, rollback por falha de DB.

## Tratativa pelo userscript (somente quem tem permissão)

A aba "Tratativa" do userscript só aparece quando `GET /bootstrap` (estado `ativo`) traz `capacidades.tratar = true`. Isso é apenas interface: **cada endpoint abaixo revalida a permissão no servidor** (`TratativaAcessoService`), então alterar o script não concede acesso.

`capacidades`: `{ tratar, admin, champ, tipos }` (`tipos = null` para admin = todos). Quem decide `tratar`: conta **aprovada** e (admin **ou** responsável do tipo em `SolicitacaoTipoResponsavel` **ou** responsável da própria solicitação). Os três critérios existentes são combinados por união.

Exige dispositivo WME ativo: o token legado de leitura do site **não** acessa estas rotas (401). Sem permissão: 403 `sem_permissao`. Solicitação inexistente ou fora do seu escopo: 404 `solicitacao_nao_encontrada` (mesma resposta, não revela registros).

| Rota | Descrição |
|---|---|
| `GET /api/wme/tratativa/resumo` | Contagem por status e `abertas`/`pendentes` (usada no contador da aba). |
| `GET /api/wme/tratativa/fila?status=abertas\|todas\|<status>&tipo=&q=&pagina=&limite=&ordem=antigas\|recentes` | Fila restrita ao escopo do usuário. `q` busca por número ou solicitante. Máx. 50 por página. |
| `GET /api/wme/tratativa/{id}` | Detalhe: respostas rotuladas, contexto do WME, anexos, comentários (incluindo internos), histórico e `acoes` (status permitidos e bloqueios). |
| `POST /api/wme/tratativa/{id}/status` | `{status, nota?}`. Usa `SolicitacaoService::mudarStatus` (histórico, notificações e e-mail ao solicitante). Downgrade de nível exige `ROLE_CHAMP` (403 `somente_champ`). |
| `POST /api/wme/tratativa/{id}/comentarios` | `{mensagem, interno?}` (até 2000 caracteres). Comentário público envia e-mail ao solicitante; interno não. |

Anexos continuam em `GET /api/wme/solicitacoes/{id}/anexos/{nome}` (já autorizado por `SOLICITACAO_VER` para não donos).

Pendências conhecidas: não há limite de taxa próprio nas ações de escrita; o site usa dois `SolicitacaoVoter` com critérios diferentes (convém delegá-los ao `TratativaAcessoService`).
