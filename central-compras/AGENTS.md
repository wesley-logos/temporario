# AGENTS.md — Central de Compras

## 1. Objetivo e princípio de continuidade

Este documento orienta agentes e desenvolvedores que trabalham no sistema existente **Central de Compras**. O objetivo é evoluir a implementação atual, preservando comportamentos válidos, dados, contratos internos, arquitetura e compatibilidade.

Antes de alterar qualquer arquivo:
1. Leia a implementação atual do arquivo e os serviços que ele utiliza.
2. Consulte os documentos de negócio relacionados.
3. Confira o schema SQL e as migrações relevantes.
4. Identifique consumidores, permissões, efeitos colaterais e impactos nos fluxos existentes.
5. Descreva divergências e riscos antes de propor mudanças estruturais.

Não reconstrua o sistema do zero, não duplique funções/serviços já existentes e não trate requisitos documentados como prova de que uma funcionalidade está implementada. Faça alterações incrementais e restritas ao escopo.

## 2. Documentação e precedência das evidências

Documentos disponíveis em `docs/`:
- `docs/epic.txt`: requisitos, regras de negócio e critérios de aceite.
- `docs/fluxos-do-sistema.md`: fluxos operacionais e estado declarado das ações.
- `docs/integracao-sso.md`: contrato, configuração e operação do SSO.
- `docs/modelagem-banco.md`: modelo de dados documentado.
- `docs/sistema.md`: visão geral, módulos, estado e pendências.

Consulte também `README.md`, `composer.json`, `database/schema_mysql.sql`, migrações e os arquivos PHP envolvidos.

Quando houver divergência:
- Para a estrutura inicial do banco, confira `database/schema_mysql.sql`; para um ambiente existente, confira também as migrações já aplicadas e o schema real daquele ambiente.
- Para o comportamento atual, examine o código executado e seus caminhos de chamada.
- Use a documentação como referência de intenção e regras de negócio, não como prova isolada de implementação.
- Registre divergências entre documentação, schema, migrações e código antes de propor alterações que afetem dados ou comportamento.
- Atualize a documentação afetada quando a implementação mudar.

Não presuma que todos os ambientes têm o mesmo estado de migração.

## 3. Stack e configuração verificadas

A implementação consultada utiliza:
- PHP, MySQL/InnoDB e PDO.
- HTML, CSS e JavaScript no front-end.
- Composer, com `vlucas/phpdotenv` e `phpmailer/phpmailer` declarados em `composer.json`.
- PHPMailer para envio SMTP, centralizado em `app/email.php`.
- Sessões PHP para autenticação local e SSO.

A conexão PDO está em `app/database.php`; o carregamento de variáveis de ambiente e fuso PHP estão em `app/environment.php`. O código usa prepared statements nativos (`PDO::ATTR_EMULATE_PREPARES = false`) e define o fuso da conexão MySQL como UTC-03:00.

**Versão PHP:** há uma divergência no `README.md`, que menciona PHP 7.3 na stack e PHP 8.4 no procedimento local. Não assuma uma versão suportada sem conferir a sintaxe usada, as dependências e o ambiente de execução. Não faça downgrade/upgrade implícito.

**Docker:** os caminhos convencionais `Dockerfile`, `.dockerignore`, `docker-compose.yml`, `docker-compose.yaml`, `compose.yml` e `compose.yaml` não foram encontrados na inspeção atual. Não invente comandos, serviços, nomes de containers ou configurações Docker. Se arquivos de containerização forem adicionados ou encontrados em outro caminho, examine-os e preserve sua configuração existente.

**Provedor de e-mail:** o código verificado implementa SMTP genérico com PHPMailer e variáveis `MAIL_*`. Não presuma que Resend esteja efetivamente configurado nem introduza API ou transporte alternativo sem verificar a configuração real do ambiente.

## 4. Estrutura e pontos de entrada conhecidos

Páginas e endpoints verificados:
- `index.php`: login local.
- `sso.php`: callback SSO.
- `painel.php`: painel e filas de trabalho por perfil.
- `solicitacao_nova.php`, `solicitacao_criar.php`, `solicitacao_ver.php`: criação e acompanhamento de solicitações.
- `aprovar_etapa.php`: decisão de aprovação/reprovação.
- `mapa_cotacao.php`: cotações e rodadas do Comprador.
- `anexo_baixar.php`: entrega de comprovantes após autorização.
- `admin.php`, `admin_logs.php`: administração e consulta paginada de logs.
- `contrato_ver.php`: consulta autorizada de contratos.
- `recuperar_senha.php`, `alterar_senha.php`, `logout.php`, `meu_perfil.php`, `ajuda.php`: conta e autenticação.

Serviços auxiliares conhecidos em `app/`:
- `database.php`: conexão PDO.
- `environment.php`: ambiente e fuso.
- `auth.php`: sessão, HTTPS e CSRF.
- `authorization.php`: carregamento de perfis ativos e exigência de usuário local.
- `sso.php` e `sso_config.php`: validação e configuração do SSO.
- `email.php`: função `sendApplicationEmail()`.
- `sla.php`: políticas, snapshots e cálculos de SLA.
- `request_integrity.php`: validações de integridade antes de aprovar.
- `system_log.php`: registro operacional em `log_sistema`.
- `quote_files.php`: armazenamento e validação de comprovantes de cotação.
- `user_menu.php`: menu da conta.

Antes de criar uma função ou serviço, procure se já existe implementação equivalente. Reutilize as funções existentes e evite concentrar novas regras de negócio em páginas sem necessidade.

## 5. Banco de dados e migrações

O schema inicial consultado declara as seguintes tabelas:
`usuarios`, `perfis`, `unidades`, `centros_custo`, `usuario_perfis`, `categorias`, `produtos`, `fornecedores`, `grupos_aprovacao`, `grupo_aprovadores`, `fluxos_aprovacao`, `fluxo_centro_custo`, `regras_etapa`, `regras_etapa_usuarios`, `calendarios`, `feriados`, `politicas_sla`, `solicitacoes`, `contratos`, `solicitacao_itens`, `etapas_solicitacao`, `decisoes_aprovacao`, `comentarios`, `mapas_cotacao`, `cotacoes`, `cotacao_itens`, `anexos`, `execucoes_sla`, `integracao_eventos`, `auditoria_eventos`, `log_sistema`, `sso_tokens_utilizados` e `password_reset_tokens`.

Regras obrigatórias:
- Use PDO e prepared statements para valores de consultas. Identificadores SQL dinâmicos devem vir de listas permitidas, nunca diretamente da entrada do usuário.
- Preserve chaves estrangeiras, índices únicos, cardinalidade, tipos, nulabilidade e regras de integridade existentes.
- Use transações para operações que alteram entidades relacionadas ou estados de workflow.
- Não exclua/renomeie colunas, tabelas, índices ou relacionamentos sem mapear todos os consumidores e planejar a migração.
- Não edite retroativamente snapshots de fluxo/SLA já instanciados sem requisito explícito e análise de impacto.
- Use `DECIMAL` para valores monetários; não use ponto flutuante para persistência de dinheiro.
- Preserve os campos de idempotência e suas restrições únicas.
- Mantenha a documentação do modelo alinhada ao DDL real.

### CPF de usuários

O schema inicial consultado contém `usuarios.cpf CHAR(11) NULL` e `UNIQUE KEY uq_usuario_cpf (cpf)`. O CPF não é obrigatório pelo DDL e, quando preenchido, deve ser único. Não remova nem torne obrigatório sem análise de negócio, dados e consumidores. Validação de formato e dígitos verificadores deve ocorrer na aplicação quando aplicável.

A migração `database/migrations/019_cpf_usuarios.sql` adiciona a coluna e o índice que já aparecem no schema inicial. Antes de aplicá-la, confirme se ambos já existem no banco de destino. Não aplique cegamente migrações a instalações novas ou a bancos que já foram atualizados.

### Divergências conhecidas entre modelo documental e schema inicial

A documentação de modelagem menciona estruturas como `diligencias`, `pedidos`, `historico_precos`, `projetos` e `sincronizacoes_erp`, mas elas não constam entre as 33 tabelas declaradas no schema inicial consultado. Não escreva código assumindo que essas tabelas existem sem confirmar o banco real e as migrações aplicadas.

O schema contém `integracao_eventos`, mas sua presença não prova que o envio ao OMIE, webhooks, sincronização ou retentativas estejam implementados.

A documentação e o código podem usar nomes diferentes para alguns campos. Confira sempre o DDL antes de escrever SQL; exemplos incluem `dados_fiscais` e `politica_snapshot` no schema inicial.

### Perfil e escopo

`usuario_perfis` possui `UNIQUE KEY uq_up_usuario (usuario_id)`, restringindo cada usuário a um vínculo de perfil/escopo. A migração `017_perfil_unico_por_usuario.sql` exige resolver duplicidades antes do `ALTER TABLE`. Não presuma múltiplos perfis simultâneos por usuário nem altere essa cardinalidade sem decisão explícita.

## 6. Autenticação, autorização e SSO

- Preserve login local por matrícula e senha com `password_verify()` e hashes de senha gerados com `password_hash()`.
- Não armazene senhas em texto puro. Não enfraqueça a política de troca obrigatória para contas com senha temporária.
- Preserve `startApplicationSession()`, regeneração do identificador de sessão no login, modo estrito, cookies `HttpOnly`, `SameSite` e atributo `Secure` quando HTTPS estiver corretamente detectado.
- Preserve a validação CSRF em formulários que alteram estado.
- Faça a autorização no servidor em cada endpoint, inclusive endpoints de download e ações recebidas por POST. Ocultar botões não é controle de acesso.
- Respeite perfil ativo, validade e escopo de unidade/Centro de Custo. Não amplie visibilidade por conveniência.
- O SSO exige uma conta local ativa; não cria usuários nem concede perfis.
- Não confie em claims JWT antes da validação da assinatura. Preserve a validação de algoritmo HS256, emissor, audiência, datas e `jti` de uso único.
- Nunca grave token SSO, segredo HMAC, senha ou credencial SMTP em logs, mensagens de erro, código ou commits.
- O endpoint SSO usa token na query string conforme o contrato atual. Preserve `Cache-Control: no-store` e `Referrer-Policy: no-referrer`; a infraestrutura web também deve evitar registrar a query string.
- Não habilite confiança em `X-Forwarded-Proto` sem confirmar proxy confiável e configuração de rede.
- O logout local não representa logout único no Portal; não afirme que encerra a sessão externa.

## 7. E-mail e segredos

- Use a função existente `sendApplicationEmail($recipient, $subject, $body)` de `app/email.php` para envio de mensagens.
- Não duplique configurações SMTP nem crie um segundo cliente de e-mail sem justificativa aprovada.
- Leia configurações por `applicationEnv()` e pelas variáveis `MAIL_*`; mantenha segredos fora do repositório.
- A implementação utiliza PHPMailer com autenticação SMTP e criptografia `ssl` ou `tls` validada pela configuração.
- Trate falhas de envio sem expor credenciais, detalhes SMTP ou dados privados ao usuário.
- Não afirme que Resend está operacional sem conferir as variáveis e a configuração do ambiente de implantação.
- Nunca inclua o conteúdo real de `.env` em respostas, logs, exemplos ou commits.

## 8. Solicitações, workflow e aprovações

- A criação de solicitação valida os dados no servidor e grava cabeçalho, item e etapas em transação.
- Preserve a chave de idempotência e o hash do conteúdo: repetição com a mesma chave e mesmo conteúdo não deve duplicar a solicitação; chave reutilizada com conteúdo diferente deve continuar sendo rejeitada.
- Preserve a validação de Centro de Custo, categoria, produto, correspondência produto/categoria, quantidade, prazo e justificativa.
- Fluxos são versionados por Centro de Custo; as solicitações existentes mantêm snapshots das regras e dos responsáveis.
- Preserve modos E/OU, ordem dos aprovadores, verificações de autorização, travas transacionais e prevenção de decisões concorrentes.
- A reprovação definitiva exige justificativa e encerra o fluxo.
- Antes de liberar uma etapa de cotação/valor/pedido/alçada, preserve as verificações em `app/request_integrity.php`.
- Não declare implementadas devolução para ajuste, diligência ou retomada de etapa sem confirmar que a operação completa existe no backend e na interface.
- Não permita alteração retroativa de uma solicitação enviada sem fluxo de edição explicitamente projetado e aprovado.

## 9. Cotações, arquivos e contratos

- Preserve rodadas de cotação, comparação por item, fornecedores distintos dentro da mesma rodada, possibilidade de repetir fornecedor em rodadas diferentes, justificativa para menos de três fornecedores e justificativa para escolha de oferta mais cara.
- A finalização exige vencedora e evidências conforme as regras existentes. Rascunhos podem ter validações diferentes da finalização.
- Preserve o congelamento do mapa depois da aprovação de valor.
- Comprovantes são armazenados fora da raiz pública em `storage/private/quotes`, com validação de extensão/MIME/tamanho e download por `anexo_baixar.php` após autorização.
- Não passe a servir arquivos privados diretamente por URL pública nem confie apenas no nome enviado pelo usuário.
- A tela de contrato é de consulta; não presuma que criar, editar ou sincronizar contratos esteja implementado.

## 10. SLA, logs e auditoria

- Reutilize `app/sla.php`; não crie um segundo motor de SLA.
- Preserve snapshots da política por etapa, fuso, dias de trabalho, janela horária e feriados.
- Não afirme que pausa por diligência, notificações ou escalonamento estão implementados: a documentação consultada os classifica como pendentes.
- `app/system_log.php` registra eventos operacionais em `log_sistema`. Reutilize-o em vez de criar um mecanismo de log paralelo.
- Não grave senhas, tokens ou conteúdo livre sensível nos logs.
- Diferencie logs técnicos/operacionais de `auditoria_eventos`, que registra ações de negócio e segurança.
- Não trate a presença de tabelas de integração ou auditoria como prova de que todos os eventos já são produzidos.

## 11. Estado funcional conhecido — não confundir com o escopo futuro

Implementações evidenciadas no código consultado incluem:
- Login local, SSO com controle anti-replay, recuperação de senha e troca obrigatória de senha temporária.
- Painel e autorização por perfil/escopo.
- Criação idempotente de solicitações, workflow versionado e aprovações E/OU.
- Alçada de Diretor.
- Mapa de cotação com rodadas, preços por item, comprovantes e justificativas.
- Cálculo de SLA com calendário e feriados.
- Logs, auditoria e download autorizado de anexos.
- Administração de usuários, grupos, calendários/feriados e trilhas de aprovação.

Parciais ou pendentes conforme os arquivos consultados:
- Acompanhamento completo do processo e todas as operações de gestão.
- Integração efetiva com OMIE, sincronização de cadastros mestres, envio de pedidos, webhooks e retentativas.
- Devolução para ajuste e diligência com pausa/retomada de SLA.
- Notificações e escalonamentos automáticos.
- Gestão completa de contratos e consulta de histórico de preços.

Antes de alterar essa classificação, verifique o código atual, endpoints, chamadas, persistência e testes. A documentação pode ficar desatualizada; corrija-a quando houver evidência de mudança.

## 12. Processo de alteração e testes

Para cada tarefa:
1. Delimite o comportamento solicitado e os critérios de aceite.
2. Leia os arquivos existentes e os serviços chamados por eles.
3. Confira schema, migrações, autenticação, autorização e transações afetadas.
4. Apresente conflitos e riscos antes de alterações estruturais.
5. Implemente a menor mudança coerente com os padrões existentes.
6. Execute os testes disponíveis e pertinentes; se não houver ambiente ou dependências, declare a limitação.
7. Revise o diff, procurando regressões, duplicação, segredos acidentais e mudanças não solicitadas.
8. Atualize a documentação quando o comportamento ou o schema mudar.

Não afirme que testes, integração real, execução Docker ou homologação foram concluídos se não foram executados. Não aplique migrações destrutivas ou de alteração de cardinalidade sem verificar o estado do banco e planejar recuperação.

## 13. Comunicação e relatório final

Responda sempre em português brasileiro. Ao concluir uma tarefa, informe:
- Arquivos alterados e motivo de cada alteração.
- Comportamento anterior e novo comportamento.
- Impactos em banco, permissões, integração e compatibilidade.
- Testes executados e seus resultados reais.
- Riscos, pendências e limitações do ambiente.
- Funcionalidades confirmadas como implementadas, parciais ou pendentes.

Não exponha credenciais, tokens, dados pessoais ou informações sensíveis. Não invente comandos de execução, caminhos, tabelas, serviços, endpoints ou resultados de teste.
