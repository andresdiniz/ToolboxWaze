# Módulo Saúde (CNES) — onde cada arquivo fica

Os caminhos abaixo são relativos à **raiz do projeto ToolboxWaze** (a pasta com `composer.json`).
A árvore deste pacote já espelha o projeto: basta copiar por cima, ou rodar `./install.sh /caminho/do/ToolboxWaze`
(faz backup dos arquivos substituídos em `.saude-backup/`; use `--dry-run` para só listar).

## Arquivos NOVOS (não existem hoje)

| Arquivo | Para quê |
|---|---|
| `src/Entity/EstabelecimentoSaude.php` | Entidade / tabela `estabelecimento_saude` |
| `src/Repository/EstabelecimentoSaudeRepository.php` | Repositório Doctrine |
| `migrations/Version20261103000000.php` | Cria a tabela |
| `src/Message/ImportSaudeMessage.php` | Mensagem da importação |
| `src/MessageHandler/ImportSaudeHandler.php` | Lê o CSV e grava (diff incremental) |
| `src/Command/ImportSaudeCommand.php` | `app:import-saude` |
| `src/Command/LiberarSaudeExpiradasCommand.php` | `app:saude:liberar-expiradas` (cron) |
| `src/Service/SaudeReservaService.php` | Reservar / renovar / liberar |
| `src/Controller/SaudeApiController.php` | API `/api/saude/...` |
| `src/Controller/SaudeController.php` | Telas `/saude` e `/saude/{id}` |
| `templates/saude/index.html.twig` | Lista |
| `templates/saude/show.html.twig` | Detalhe |
| `docs/API_SAUDE.md` | Documentação da API |

## Arquivos que SUBSTITUEM os existentes (versão completa, com as linhas de saúde já incluídas)

| Arquivo | O que mudou |
|---|---|
| `src/Entity/User.php` | Permissão `PERM_SAUDE` ("Saúde") — aparece sozinha no admin de usuários |
| `templates/base.html.twig` | Item "Saúde" no menu (só para quem tem a permissão) |
| `src/Controller/BuscaController.php` | Busca e autocomplete incluem saúde |
| `templates/busca/index.html.twig` | Opção "Saúde" no filtro + tabela de resultados |
| `src/Controller/DashboardController.php` | Carrega KPIs de saúde |
| `src/Service/DashboardService.php` | Query dos KPIs de saúde |
| `src/Service/DashboardDataProvider.php` | KPIs de saúde com cache |
| `templates/dashboard/index.html.twig` | Botão, KPI e card de saúde |
| `assets/js/app.js` | Selo "Saúde" no autocomplete do navbar |
| `assets/styles/app.css` | Cor do selo "Saúde" |

> Se você alterou algum desses 10 arquivos depois de hoje (03/11/2026), não copie por cima: compare
> com o backup/`diff` e traga só as linhas de saúde. Se mexeu em `app.js`/`app.css`, rode também o build de assets.

## Depois de copiar

```bash
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
# Admin > Usuários: marcar "Saúde" para quem vai usar (admins já têm)
php bin/console app:import-saude --file=/caminho/cnes.csv     # ou SAUDE_CNES_CSV_URL no .env
# opcional (cron a cada 5 min):
# */5 * * * * cd /var/www/app && php bin/console app:saude:liberar-expiradas --ttl=30 --env=prod --no-debug
```
