# Modelo de dados MySQL — Central de Compras

## Premissas

O sistema é o registro principal de solicitações, aprovações, cotações, SLA e auditoria.

OMIE é a origem dos cadastros mestres de produtos, categorias, centros de custo, projetos e fornecedores homologados. As contas do sistema são locais e usam matrícula e senha; os perfis e escopos ficam sob controle do próprio sistema.

O DDL inicial está em [`database/schema_mysql.sql`](../database/schema_mysql.sql). Ele usa InnoDB, `utf8mb4`, chaves estrangeiras, `DECIMAL` para valores monetários, `DATETIME` para instantes armazenados como horário local de Brasília (UTC-03:00) e JSON do MySQL para snapshots. O tipo JSON exige MySQL 5.7.8 ou superior (ou MySQL 8.x). Use exclusão lógica em cadastros configuráveis e trate auditoria como somente de inserção.

O arquivo [`app/database.php`](../app/database.php) fornece conexão PDO com prepared statements nativos e credenciais por variáveis de ambiente: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASSWORD`. [`app/environment.php`](../app/environment.php) usa `vlucas/phpdotenv` para carregar o `.env` local sem sobrescrever variáveis definidas pelo servidor. O `.env` real está excluído do Git e bloqueado pelo Apache; em produção, prefira definir segredos fora do webroot no ambiente do Apache/PHP-FPM. Não grave segredos no repositório. A conexão deve ser usada pelos serviços PHP que leem e persistem dados.

## Identidade, organização e autorização

| Tabela | Campos principais | Finalidade |
|---|---|---|
| `usuarios` | `id`, `matricula`, `email`, `nome`, `cpf`, `senha_hash`, `ativo`, `ultimo_acesso_em`, timestamps | Conta local. A matrícula é única; a senha é armazenada apenas como hash seguro. |
| `password_reset_tokens` | `token_hash`, `usuario_id`, `expira_em`, `solicitado_em`, `consumido_em`, `ip_origem` | Hash de tokens de redefinição com validade limitada, uso único e controle de solicitações por conta. |
| `perfis` | `id`, `codigo`, `nome`, `descricao`, `ativo` | Solicitante, comprador, gestor, diretor e administrador. |
| `usuario_perfis` | `usuario_id`, `perfil_id`, `unidade_id`, `centro_custo_id`, `concedido_por`, `valido_ate` | Vínculo de perfil e escopo por unidade/centro de custo. A chave única em `usuario_id` garante exatamente um vínculo por usuário; trocar o perfil ou o escopo substitui o vínculo existente. |
| `unidades` | `id`, `omie_id`, `codigo`, `nome`, `ativa`, `sincronizado_em` | Filiais e matriz; dados sincronizados do ERP quando disponíveis. |
| `grupos_aprovacao` | `id`, `nome`, `descricao`, `ativo` | Grupos reutilizáveis de aprovação. |
| `grupo_aprovadores` | `grupo_id`, `usuario_id`, `ordem_aprovacao`, `ativo`, `inicio_vigencia`, `fim_vigencia` | Membros, vigência e sequência hierárquica para etapas E. |
| `log_sistema` | `id`, `usuario_id`, `item_id`, `entidade`, `acao`, `status`, `ip_usuario`, `ocorrido_em`, `detalhes`, `erro` | Registro cronológico de ações e erros técnicos. O identificador do usuário não possui FK para preservar o histórico; `detalhes` não deve conter credenciais ou tokens. |

O login local consulta contas ativas pela matrícula e valida a senha com `password_verify()`. Ambos os fluxos, local e SSO, atualizam `usuarios.ultimo_acesso_em` e registram a origem do acesso na auditoria, sem guardar credenciais. A recuperação envia link por e-mail sem revelar se a conta existe; o token aleatório só é armazenado como SHA-256, expira em uma hora e não pode ser reutilizado. Permissões são resolvidas em `usuario_perfis`; não são determinadas pelo e-mail. Nunca persistir senhas em texto claro. `log_sistema` mantém IP, horário de Brasília (UTC-03:00), entidade/ID do item e status; falhas técnicas incluem mensagem de erro e contexto mínimo para diagnóstico.

## Cadastros mestres e integração

| Tabela | Campos principais | Finalidade |
|---|---|---|
| `centros_custo` | `id`, `omie_id` (único, opcional), `codigo`, `nome`, `unidade_id`, `ativo_omie`, `sincronizado_em` | Cadastro local de Centro de Custo; pode ser criado sem ID OMIE e associado ao identificador quando sincronizado. |
| `categorias` | `id`, `omie_id` (único), `codigo`, `nome`, `ativo_omie`, `sincronizado_em` | Categorias retornadas pelo OMIE. |
| `produtos` | `id`, `omie_id` (único), `codigo`, `nome`, `categoria_id`, `unidade_medida`, `ativo_omie`, `sincronizado_em` | Cache de produtos para busca e validação pré-envio. |
| `projetos` | `id`, `omie_id` (único), `codigo`, `nome`, `ativo_omie`, `sincronizado_em` | Cache opcional se projeto fizer parte do formulário OMIE. |
| `fornecedores` | `id`, `omie_id` (nulo até homologação), `documento_normalizado`, `razao_social`, `tipo` (homologado/candidato), `ativo_omie`, `dados_fiscais_json`, timestamps | Fornecedor homologado sincronizado ou candidato criado localmente. Documento normalizado único; candidato só é enviado ao ERP quando vencedor aprovado. |
| `sincronizacoes_erp` | `id`, `entidade`, `iniciada_em`, `finalizada_em`, `resultado`, `itens_lidos`, `erro_tecnico`, `correlation_id` | Execuções da sincronização de cadastros e diagnóstico. |
| `integracao_eventos` | `id`, `solicitacao_id`, `direcao`, `tipo_evento`, `chave_idempotencia` (única), `referencia_externa`, `http_status`, `resposta_json`, `status`, `mensagem_usuario`, `erro_tecnico`, `tentativas`, `proxima_tentativa_em`, timestamps | Registro preparado para saída e retorno OMIE; a integração ainda não envia pedidos. |

## Solicitação, itens e arquivos

| Tabela | Campos principais | Finalidade |
|---|---|---|
| `solicitacoes` | `id`, `numero` (único), `solicitante_id`, `comprador_id`, `chave_idempotencia`, `payload_hash`, `centro_custo_id`, `categoria_id`, `status`, `etapa_atual`, `omie_integracao_status`, `omie_pedido_id`, `prazo_estimado`, `justificativa`, timestamps | Cabeçalho e estado atual da SC. Os campos OMIE receberão estado e identificador do pedido retornados numa fase posterior. A chave única/hash mantém idempotência. |
| `solicitacao_itens` | `id`, `solicitacao_id`, `produto_id`, `descricao_snapshot`, `quantidade`, `unidade_snapshot`, `observacao` | Itens; snapshots preservam o que foi pedido mesmo com mudanças futuras no cadastro ERP. |
| `anexos` | `id`, `solicitacao_id`, `cotacao_id` (nulo), `autor_id`, `nome_original`, `mime_type`, `tamanho`, `storage_key`, `checksum`, `criado_em` | Metadados de arquivos; comprovantes do mapa ficam em `storage/private/quotes`, fora da raiz pública, e são baixados pela rota autorizada `anexo_baixar.php`. |
| `comentarios` | `id`, `solicitacao_id`, `autor_id`, `comentario_pai_id` (nulo), `tipo`, `texto`, `criado_em` | Linha do tempo e threads de diligência/retorno. Não substituir eventos de auditoria. |

## Workflow e decisões

| Tabela | Campos principais | Finalidade |
|---|---|---|
| `etapas_workflow` | `id`, `codigo`, `nome`, `ordem_padrao` | Catálogo: solicitação, mapa de cotação e pedido. |
| `fluxos_aprovacao` | `id`, `nome`, `versao`, `ativo`, `vigente_desde`, `vigente_ate`, `limite_alcada_diretor` | Versões publicadas por Centro de Custo; limite opcional em BRL que exige etapa final de Diretor com base na cotação vencedora aprovada/congelada. |
| `fluxo_centro_custo` | `fluxo_id`, `centro_custo_id`, `prioridade` | Fluxo selecionado para cada centro de custo. |
| `regras_etapa` | `id`, `fluxo_id`, `codigo_etapa` (inclui `aprovacao_valor`), `ordem`, `ativa`, `modo` (E/OU), `responsavel_tipo`, `grupo_id`, `usuario_id` | Etapas habilitadas, sequenciamento e grupo/tipo do aprovador. Mapa e Aprovação de Valor são configurados juntos; a última aprova a oferta vencedora do mapa. |
| `regras_etapa_usuarios` | `regra_etapa_id`, `usuario_id`, `ordem` | Lista ordenada de aprovadores individuais associada à regra; pode ser usada sozinha ou junto a um grupo. O snapshot na etapa preserva o conjunto na abertura da solicitação. |
| `etapas_solicitacao` | `id`, `solicitacao_id`, `codigo_etapa`, `ordem_execucao`, `status`, `regra_snapshot`, `iniciada_em`, `concluida_em`, `responsavel_tipo`, `responsavel_id` | Instâncias da trilha selecionada ao criar a SC, incluindo `aprovacao_valor`; também comporta `diretor_alcada`, adicionada dinamicamente com snapshot dos Diretores e do valor/limite comparados. |
| `decisoes_aprovacao` | `id`, `etapa_solicitacao_id`, `aprovador_id`, `decisao`, `justificativa`, `criada_em`, `ip_origem` | Aprovações, reprovações, devoluções e pedido de informação. Em OU, transação/lock impede duas decisões vencedoras. |
| `diligencias` | `id`, `etapa_solicitacao_id`, `pergunta_comentario_id`, `destinatario_id`, `status`, `aberta_em`, `respondida_em`, `respondida_por` | Pausa e retomada da etapa e do SLA preservando o tempo já consumido. |

## Cotação e pedido

| Tabela | Campos principais | Finalidade |
|---|---|---|
| `mapas_cotacao` | `id`, `solicitacao_id`, `versao`, `status`, `justificativa_menos_tres`, `aberto_por`, `aprovado_em`, `congelado_em` | Versões do mapa. Exigir três fornecedores distintos ou justificativa; congelar versão aprovada. |
| `cotacoes` | `id`, `mapa_id`, `fornecedor_id`, `valor_total`, `moeda`, `prazo_entrega_dias`, `condicao_pagamento`, `frete_tipo`, `vencedora`, `justificativa_escolha` | Uma oferta por fornecedor em cada rodada; `UNIQUE (mapa_id, fornecedor_id)` impede duplicidade na mesma rodada e permite repetir fornecedor em outra versão do mapa. |
| `cotacao_itens` | `id`, `cotacao_id`, `solicitacao_item_id`, `valor_unitario`, `quantidade`, `valor_total` | Preços cotados por item, permitindo comparação e histórico. |
| `pedidos` | `id`, `solicitacao_id`, `fornecedor_id`, `omie_id`, `status_integracao`, `valor_total`, `moeda`, `chave_idempotencia`, timestamps | Representação do pedido/requisição criada após workflow e pré-validação. |
| `contratos` | `id`, `solicitacao_id`, `centro_custo_id`, `numero_omie`, `descricao`, `fornecedor_id`, `valor_total`, `status`, vigência | Dados contratuais derivados do processo/ERP, apresentados ao gestor dentro do escopo organizacional. |
| `historico_precos` | `id`, `produto_id`, `fornecedor_id`, `centro_custo_id`, `pedido_id`, `quantidade`, `valor_unitario`, `moeda`, `data_compra` | Projeção local de compras passadas para consulta das últimas ofertas. |

## SLA, calendário e auditoria

| Tabela | Campos principais | Finalidade |
|---|---|---|
| `politicas_sla` | `id`, `fluxo_id`, `codigo_etapa`, `duracao`, `unidade_tempo` (`horas`/`dias`), `regime_contagem` (`corridos`/`uteis`), `calendario_id`, `janela_inicio`, `janela_fim` | Configura o prazo de cada etapa da versão do fluxo. Janela e fuso são obtidos do calendário associado. |
| `calendarios` | `id`, `nome`, `fuso_horario`, `dias_semana_json`, `hora_inicio`, `hora_fim`, `criado_por` | Jornada de trabalho e fuso usados no cálculo de horas e dias úteis. |
| `feriados` | `id`, `calendario_id`, `data`, `descricao` | Datas não úteis por calendário; única data por calendário. |
| `execucoes_sla` | `id`, `etapa_solicitacao_id`, `politica_snapshot_json`, `iniciada_em`, `vence_em`, `segundos_consumidos`, `segundos_pausados`, `pausada_em`, `encerrada_em`, `resultado` | Instância e snapshot do cálculo; separa lead time de tempo em posse de equipe. |
| `auditoria_eventos` | `id`, `ator_id`, `solicitacao_id`, `acao`, `entidade`, `entidade_id`, `valor_anterior_json`, `valor_novo_json`, `ip_origem`, `correlation_id`, `criado_em` | Log somente append para alterações, decisões, status, dados de cotação e autenticação; os acessos são registrados com a origem, por exemplo `Login via Sistema` ou `Login SSO via Portal`. Restringir UPDATE/DELETE no banco e conceder leitura controlada. |
| `notificacoes` | `id`, `usuario_id`, `solicitacao_id`, `tipo`, `canal`, `status`, `conteudo_json`, `tentativas`, `enviada_em`, timestamps | Alertas de atribuição, diligência, ajuste, SLA e escalonamento. |

## Relações e restrições importantes

- Uma solicitação tem um solicitante, centro de custo e uma ou mais linhas de item; categoria da solicitação limita as categorias de produto permitidas.
- Toda SC guarda a versão do fluxo, etapas executadas e snapshots de regras/SLA vigentes na abertura da etapa.
- Valores aprovados e ofertas congeladas não são editáveis; correções criam nova versão de mapa, preservando a anterior.
- Uma aprovação OU deve eleger uma decisão vencedora atomicamente. Em aprovação E, só abrir a próxima regra após satisfazer a atual.
- Antes do envio ao OMIE, validar novamente produto/centro de custo ativos e idempotência. Reenvios usam a mesma chave idempotente.
- A trilha da SC, as decisões, falhas de integração e auditoria devem ser consultáveis por `solicitacao_id`; indexar as FKs e campos de busca/status.
- `documento_normalizado` do fornecedor candidato deve ter unicidade e ser comparado com a base sincronizada antes de inserir. Candidatos requerem os dados fiscais necessários antes de homologação.
- Armazenar os identificadores externos do OMIE separados das chaves internas do sistema.

## Sequência sugerida de implementação

1. Identidade, unidades, perfis, centros de custo e catálogos sincronizados.
2. Solicitações, itens, anexos, comentários e auditoria.
3. Fluxos versionados, grupos, decisões e instâncias de SLA/calendário.
4. Mapa, rodadas/cotações, fornecedor candidato e histórico de preços.
5. Pedidos, fila de integração idempotente, webhooks, retries e notificações.

O schema inclui calendários, feriados, políticas de SLA, execuções de SLA, mapas, cotações, itens cotados, anexos e `log_sistema`. `admin.php` permite ao Administrador publicar uma trilha versionada por Centro de Custo, com etapas independentes, aprovadores por grupo ou usuário e limite opcional de alçada do Diretor. Quando o último estágio padrão termina, o sistema compara esse limite à cotação vencedora aprovada/congelada em BRL; se excedido, cria a etapa final `diretor_alcada` para qualquer usuário ativo com perfil Diretor. Solicitações antigas preservam snapshots. `app/sla.php` congela a política no início e calcula vencimento conforme calendário; `solicitacao_ver.php` mostra o progresso percentual. `mapa_cotacao.php` registra mapas versionados, ofertas por fornecedor e preços por item em `cotacao_itens`; cada rodada exige três fornecedores distintos ou justificativa, comprovante para cada oferta e seleção justificada da vencedora. O mesmo fornecedor pode aparecer em rodadas diferentes. `anexo_baixar.php` entrega comprovantes privados com autorização. Permanecem pendentes projetos, pedidos, notificações, diligências, retenção/LGPD e integração com o OMIE.
