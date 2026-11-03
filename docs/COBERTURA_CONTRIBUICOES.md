# Cobertura e diário de contribuições

## Implantação segura

1. Faça backup e valide em homologação com o schema real.
2. Confira `php bin/console doctrine:migrations:status`. Há migrações antigas com modelos distintos de estabelecimento_saude: NÃO execute todas sem auditoria.
3. Execute apenas `php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261103112100' --up`. A migração não altera tabelas de locais.
4. Execute `php bin/console cache:clear --env=prod`, lint de PHP/Twig e os testes.
5. Valide `/cobertura/rankings`, os scripts do WME e as permissões antes de liberar.

## Conceitos

A cobertura acumulada permanece com suas regras anteriores. O diário é separado e inicia quando a migração está instalada e as gravações instrumentadas passam pelo Doctrine ORM. Não se usa updatedAt como data fictícia de contribuição; não há reconstrução automática de autoria desconhecida. Os horários do diário são UTC, e o período máximo é 366 dias, com fim inclusivo.

A conta autenticada e o username declarado são armazenados separadamente. Não é feita associação automática por igualdade de nomes. Metadados `_meta` são adicionados somente a respostas JSON em formato de objeto das rotas instrumentadas, sem remover os campos existentes. Arrays JSON não são convertidos em objetos. Tokens, IPs e cabeçalhos de autorização nunca são gravados no diário.

## Captura e limites

- Escolas e saúde: mudanças ORM de status e links; reservas e timestamps isolados não geram contribuição. Links escolares na interface usam o log próprio para evitar duplicação.
- Radares e postos: criação ORM dos vínculos e persistência ORM dos respectivos logs.
- Escolas: persistência ORM do histórico de links.
- Radares manuais: criação ORM com autor conhecido. O evento é preservado após mesclagem, sem somar novamente o local na cobertura.
- Gravações feitas diretamente por DBAL, SQL ou importadores não disparam listeners ORM. Seus logs e dados anteriores continuam no ranking acumulado, mas não são promovidos silenciosamente ao novo diário. Para instrumentá-las, o writer deve chamar registrar() na mesma transação, usando uma referência estável.
- O diário é append-only pela aplicação e possui referência única. Repetição sem mudança não gera evento, mas isso NÃO implementa um protocolo HTTP Idempotency-Key completo.
- Falha de gravação do diário, exceto referência duplicada, propaga e interrompe a transação ORM. Enquanto a tabela não existir, a gravação do local continua, há aviso no log e a tela informa que o diário não está instalado.
- Contagens de locais distintos não equivalem a pontos ou qualidade do mapa. O painel apresenta até 100 contas e pagina eventos em 25 itens.

## Proteções da API

O subscriber reforça permissão do módulo e UF nas rotas de escolas, saúde, consultas de radares e listagens api_radares/api_postos. Respostas de sucesso que contenham UFs não autorizadas são negadas integralmente. Confirmar escola/saúde sem nenhum vínculo retorna 422; envie o link primeiro. Rotas de importação e autenticação não são interceptadas. Não substitui auditoria completa de todos os endpoints.

## Checklist de regressão

- Matriz de usuário sem permissão de módulo e com UF restrita: leitura, escrita, reserva, renovação e liberação.
- Chamada com vínculo, seguida de confirmação: conta e username separados, metadados preservados.
- Repetição do mesmo link/status: nenhum novo evento.
- Atualização somente de reserva: nenhum evento de contribuição.
- Edição de links pela interface e pela API, com usuário sem nickname.
- Erro de persistência: confirmar rollback da entidade e do diário na versão instalada do Doctrine.
- Banco sem tabela do diário: aviso e cobertura acumulada disponíveis.
- Intervalo UTC cruzando virada do mês, filtros e paginação.
- Collations distintas nas tabelas legadas; manter o hotfix de CONVERT/COLLATE do ranking acumulado.

Os testes incluídos verificam a classificação de ações. Testes de integração PHP/MySQL e navegação precisam ser executados no ambiente da aplicação; não foram executados pelo assistente.
