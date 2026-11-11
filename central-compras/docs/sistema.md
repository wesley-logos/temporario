# Documentação do sistema — Central de Compras

## 1. Propósito

A Central de Compras organiza o processo de suprimentos em um sistema próprio. A análise de requisitos descreve problemas causados pelo fluxo fragmentado entre a Central de Serviços e o OMIE, como retrabalho, aprovações pouco flexíveis e baixa rastreabilidade.

O sistema deve centralizar a solicitação, a cotação, as aprovações, os prazos, a trilha de auditoria e o acompanhamento da integração com o ERP. O OMIE permanece responsável pelos cadastros mestres e pela execução financeira. O escopo não inclui pagamentos, notas fiscais, estoque físico nem comunicação externa com fornecedores.

## 2. Atores e acesso

- **Solicitante:** abre solicitações e acompanha o andamento; atende pedidos de ajuste ou de informação.
- **Comprador:** valida solicitações liberadas, conduz rodadas de cotação, escolhe a oferta vencedora com justificativa quando necessário e acompanha o envio ao ERP.
- **Gestor/aprovador:** aprova, reprova definitivamente, devolve para ajuste ou pede esclarecimentos.
- **Diretor:** participa das alçadas por valor e possui a visibilidade ampla definida pela política de acesso.
- **Administrador de Compras:** configura fluxos, grupos, regras de valor, calendários e SLAs.
- **TI/integração:** acompanha falhas, reenvios e logs técnicos.

A autorização é baseada nos perfis e escopos organizacionais mantidos na base local. A matrícula identifica a conta e as permissões são concedidas administrativamente.

## 3. Fluxo funcional previsto

1. **Entrada:** a pessoa informa matrícula e senha. O sistema valida a credencial e carrega os perfis e escopos locais.
2. **Solicitação:** a pessoa seleciona centro de custo, categoria, produto sincronizado do OMIE, quantidade e prazo, e informa a justificativa. Anexos técnicos são opcionais para qualquer categoria; comprovantes de orçamento são obrigatórios em cada oferta do mapa.
3. **Aprovação da solicitação:** o fluxo aplicável ao centro de custo decide as etapas ativas. Aprovação `E` exige todos os aprovadores configurados; aprovação `OU` encerra o grupo quando um aprovador decide. A reprovação definitiva encerra a solicitação; a devolução libera correção preservando os dados.
4. **Cotação:** depois da liberação, Compras registra ofertas de fornecedores e seus comprovantes. São exigidas três ofertas distintas ou justificativa obrigatória para exceção. O menor preço é destacado; outra oferta pode vencer com justificativa.
5. **Aprovação de valor e pedido:** valores e fornecedor aprovados são congelados. Etapas de mapa e pedido podem variar por centro de custo e por alçada de valor.
6. **Integração OMIE:** antes do envio, o sistema valida cadastros ativos e consistência. Envio idempotente, fila de tentativas e confirmação do ERP evitam duplicidade e encerramento prematuro.
7. **Acompanhamento:** indicador de etapas, timeline, notificações e SLA informam status, responsáveis, pendências e tempos. Diligência pausa o SLA da etapa e a resposta retoma o tempo restante.

Devolução para ajuste e pedido de informação são ações distintas: o ajuste permite editar os dados indicados e reinicia o SLA de análise correspondente; a diligência pausa e retoma a etapa sem descartar o tempo já consumido.

## 4. Componentes e estado da implementação

Os passos operacionais completos, separados por perfil e ação, estão em [Fluxos de ações do sistema](fluxos-do-sistema.md). Esse documento distingue rotas implementadas de ações ainda previstas.

| Componente | Estado atual | Próximo trabalho |
|---|---|---|
| Página inicial e painéis | `index.php` é a entrada para usuários sem sessão e direciona contas autenticadas a `painel.php`. O painel exige sessão e oferece visões de solicitante, comprador, gestor, diretor e administrador. O Administrador dispõe de dois menus laterais suspensos, Administração e Sistema, para acessar sem duplicação os recursos administrativos e as visões de solicitações da organização e próprias, contratos, aprovações pendentes e histórico. Também pode consultar detalhes de solicitações/contratos e aprovar somente etapas para as quais esteja designado; as ações dos demais perfis continuam restritas. No dashboard, apenas o Administrador vê que a integração OMIE ainda não está disponível e a data mais recente de sincronização registrada nos cadastros. O Gestor acessa visão geral, contratos, aprovações pendentes e histórico de decisões; o Solicitante acessa sua visão geral e pode abrir nova solicitação. Após o envio, o Solicitante é direcionado aos detalhes da solicitação criada. Nas áreas autenticadas, o menu com nome e último sobrenome oferece acesso a `meu_perfil.php`, `ajuda.php` e `logout.php`. | Persistir contratos importados/geridos e acrescentar paginação. |
| Perfil e ajuda | `meu_perfil.php` mostra matrícula, nome, e-mail e o perfil ativo. A própria pessoa pode editar o nome, mas matrícula, e-mail e perfil são somente para consulta; a troca de senha exige a senha atual, confirmação e ao menos 12 caracteres. `ajuda.php` apresenta instruções passo a passo para conta, painel, solicitações, aprovações, cotações e administração. | Permitir a atualização de outros dados pessoais caso novos campos editáveis sejam incluídos no cadastro. |
| Login e recuperação de senha | `index.php` consulta matrícula e senha de usuário ativo; `sso.php` valida token do Portal, impede replay, exige conta local ativa e abre a mesma sessão de aplicação. Ambos atualizam `usuarios.ultimo_acesso_em` e registram a origem do login na auditoria (`Login via Sistema` ou `Login SSO via Portal`). `alterar_senha.php` força contas criadas com senha temporária a escolherem uma senha própria antes de acessar outras páginas autenticadas; `app/authorization.php` aplica o bloqueio em todas as rotas protegidas. `recuperar_senha.php` envia por SMTP autenticado um link de uso único com validade de uma hora, atualiza o hash e encerra a exigência de troca. `logout.php` encerra a sessão local. | Parametrizar credenciais/URLs por ambiente com o Portal, configurar as variáveis SMTP protegidas, implantar em HTTPS, agendar limpeza anti-replay e concluir homologação. |
| Conexão com MySQL | Fábrica PDO em `app/database.php`; cada conexão fixa o fuso MySQL em `-03:00`. `app/environment.php` define `America/Sao_Paulo` para datas PHP e apresentação. | Injetar a conexão em serviços/repositórios, tratar falhas sem expor credenciais ou erros técnicos ao usuário. |
| Persistência | `database/schema_mysql.sql` e as migrações `001` a `018` cobrem o modelo atual, incluindo login, redefinição e troca obrigatória de senha temporária, vínculo único de perfil por usuário, Centro de Custo sem ID OMIE obrigatório, workflow, SLA, logs, mapas e itens de cotação. | Seeds de desenvolvimento e política de retenção dos logs. |
| Solicitações e workflow | `solicitacao_nova.php` e `solicitacao_criar.php` exigem Centro de Custo, Categoria, Produto ativo associado à categoria, quantidade positiva, prazo e justificativa; a validação servidor impede produto fora da categoria. Não há rota de edição após o envio nem exigência de anexo técnico por categoria. `app/request_integrity.php` valida os campos e a integridade do mapa, sua oferta vencedora e evidências antes de cada aprovação; `aprovar_etapa.php` bloqueia avanços inconsistentes e congela o mapa após a aprovação do valor. | Pausas por diligência, notificações e paginação/histórico completo. |
| Cotações e fornecedores candidatos | `mapa_cotacao.php` permite ao Comprador salvar rascunhos e criar rodadas sequenciais sem limite de ofertas. Cada rodada exige fornecedores distintos internamente, aceita o mesmo fornecedor em rodadas diferentes, calcula preços por item, registra prazo, pagamento, frete CIF/FOB e exige comprovante PDF/JPG/PNG/EML por oferta. Ao haver menos de três fornecedores distintos, a tela exibe a justificativa de exceção e exige seu preenchimento para finalizar (rascunhos continuam permitidos). O menor total é realçado e atualizado conforme os preços são alterados; uma oferta mais cara pode ser escolhida com justificativa técnica obrigatória. A tela de detalhe mostra o menor preço, a vencedora, justificativas e documentos aos usuários autorizados. `app/request_integrity.php` repete as verificações antes da aprovação. `anexo_baixar.php` serve os arquivos privados com autorização. | Validação fiscal e fluxo de homologação no OMIE. |
| Administração | `admin.php` oferece, no menu lateral recolhível, gerenciamento de usuários (`?secao=usuarios`), grupos de aprovadores (`?secao=grupos`), calendários/feriados (`?secao=calendarios`), trilhas versionadas por Centro de Custo (`?secao=trilhas`) e consulta dos logs do sistema (`?secao=logs`) com DataTables, busca e paginação server-side. A tela de usuários lista matrícula, nome, e-mail, status e perfil; permite cadastrar contas com perfil único, enviando uma senha temporária por e-mail que deve ser trocada antes do primeiro acesso às páginas protegidas, além de editar dados, ativar/inativar e remover contas sem registros vinculados. Cada usuário tem um único vínculo de perfil e escopo; ao salvar usuários legados, mantém-se o primeiro vínculo do perfil selecionado e os demais perfis/escopos são removidos. O usuário autenticado não pode inativar ou remover a própria conta nem alterar seu próprio perfil Administrador. E-mails usam o transporte SMTP comum de `app/email.php`, configurado pelas variáveis `MAIL_*`. Na seção de trilhas, uma tabela lista os Centros de Custo com unidade e vínculo OMIE; edição permite alterar somente o nome, configuração abre em modal o mesmo formulário de etapas/aprovadores/SLAs e remoção é bloqueada quando há perfis, trilhas, solicitações ou contratos vinculados. As trilhas configuram etapas, aprovadores individuais/grupos, modos E/OU, SLAs e alçada de Diretor. | Interface para administrar o escopo único de unidade/centro de custo do perfil do usuário. |
| SLA e calendário | `admin.php` configura prazo por etapa e Centro de Custo (via versão do fluxo), em horas/dias, regime corrido/útil, calendário, dias da semana, janela horária e feriados. `app/sla.php` congela a política no início de cada etapa, calcula vencimento e tempo consumido, e `solicitacao_ver.php` exibe barra de 0% a 100% (verde antes do limite e vermelha ao atingi-lo). Horas sempre contam apenas dentro da janela operacional; dias úteis acumulam a duração diária configurada, excluindo dias não úteis e feriados; dias corridos contam continuamente. | Pausa por diligência, alertas e escalonamentos ainda não implementados. |
| Auditoria e integração | Login pelo Sistema e login SSO escrevem eventos de auditoria identificados pela origem; o SSO também usa `jti` único para bloquear replay. | Aplicar escrita append-only, fila/outbox, webhooks, retries, correlação e idempotência ponta a ponta nas integrações restantes. |
| Arquivos e notificações | Comprovantes de cotação são armazenados fora da raiz pública em `storage/private/quotes`, com validação de tipo/tamanho, hash e download autorizado. | Canais de notificação. |

### Registro de ações e erros

`app/system_log.php` centraliza a gravação na tabela `log_sistema`. Os eventos registram usuário quando autenticado, entidade e ID do item relacionado, nome da ação, resultado (`sucesso`, `falha` ou `negado`), endereço IP, horário de Brasília (UTC-03:00), detalhes e mensagem técnica de erro quando houver. A tabela não usa chave estrangeira para `usuarios`, preservando o histórico mesmo se uma conta for removida.

Já são registrados logins locais/SSO e logout, tentativas negadas, criação idempotente de solicitações, aprovações e reprovações, configuração de fluxos, gestão de grupos e membros, calendários e feriados, além de erros técnicos tratados e erros fatais do PHP. Mensagens de credencial, senhas e tokens não devem ser incluídos em `detalhes` ou `erro`. O acesso e a retenção desses registros devem seguir a política interna de segurança e privacidade; em bancos existentes, aplique `database/migrations/008_log_sistema.sql` antes de usar a nova gravação.

### Configuração e execução do workflow (RF01, RN12 e RF09)

O perfil **Administrador de Compras** é o único autorizado a configurar a trilha de aprovação por Centro de Custo. Em `admin.php`, o administrador pode cadastrar um Centro de Custo local informando nome e, opcionalmente, código e unidade; o identificador OMIE pode ser associado depois. Em seguida, seleciona um Centro de Custo ativo e configura as etapas **Solicitação**, **Mapa de cotação**, **Aprovação de valor** e **Pedido de compra**. Mapa e Aprovação de Valor precisam ser ativados juntos; a aprovação de valor sucede o mapa e requer uma oferta vencedora. Cada etapa ativa exige responsável, modo de aprovação e política de prazo.

O responsável pode ser um usuário ativo, um grupo ativo com membros vigentes ou ambos. Para grupo, o modo **E** exige uma ordem hierárquica exclusiva por membro; quando há usuários individuais junto ao grupo, eles seguem a ordem configurada após os membros do grupo. A aprovação só passa ao próximo nível depois da decisão do aprovador atual. No modo **OU**, qualquer aprovador listado pode aprovar e a primeira aprovação conclui a etapa. A análise exige três cotações de fornecedores distintos ou justificativa para exceção, mas não estabelece um número mínimo de aprovadores por etapa ou por fluxo. A reprovação encerra a solicitação como reprovada. O painel e a página de detalhe identificam tarefas pendentes e autorizam decisão somente ao responsável previsto na instância da etapa.

Como extensão da trilha, o Administrador pode configurar um limite de alçada em reais por Centro de Custo. Ao terminar a última etapa padrão, o sistema verifica o valor da cotação vencedora aprovada/congelada. Se o total em BRL for maior que o limite, é criada a etapa final **Aprovação do Diretor**, atribuída a todos os usuários ativos com perfil Diretor; qualquer um deles pode aprovar. O limite e a lista de aprovadores são registrados na instância, preservando a decisão mesmo que a configuração mude depois. Sem limite, ou quando o total não ultrapassa o limite, o fluxo segue sem essa etapa adicional.

Ao criar a solicitação, o sistema lê a versão vigente do fluxo daquele Centro de Custo e instancia apenas as etapas ativas, na ordem definida. A primeira etapa começa pendente e as seguintes aguardam. A página `solicitacao_ver.php` mostra etapas concluídas, atual e futuras; etapas desativadas são omitidas. A etapa atual exibe “Etapa N de total — Aguardando [fase]” e o responsável aparece junto à etapa e no tooltip. A aprovação de valor exibe total e fornecedor da cotação vencedora mais recente; ao completar a aprovação, o mapa é marcado aprovado/congelado. O sistema grava snapshots das regras e dos aprovadores. A etapa de alçada é acrescentada ao final quando o limite excedido é verificado. Integração OMIE aparece como marco final preparado: os campos `omie_integracao_status` e `omie_pedido_id` aguardam o futuro envio/retorno, sem chamada à API nesta fase.

O administrador também configura o prazo de cada etapa em horas ou dias e define contagem corrida ou útil. Calendários especificam fuso horário, dias da semana (incluindo sábados úteis quando configurados), janela de trabalho e feriados. Horas contam somente dentro da janela; quando uma etapa inicia fora dela ou em dia não útil, a contagem começa no próximo período aberto. Dias úteis acumulam a jornada de cada dia útil, descontando finais de semana/dias não selecionados e feriados. Dias corridos contam continuamente. A política e o calendário são copiados para um snapshot por execução, de modo que mudanças administrativas futuras não alterem prazos já iniciados. A barra de SLA da etapa atual mostra percentual consumido, vencimento e muda de verde para vermelho ao chegar a 100%. Pausa por diligência, alertas e escalonamentos ainda não estão implementados. As aprovações implementadas cobrem aprovar e reprovar; devolução para correção e pedido de informação ainda são pendências do workflow. `mapa_cotacao.php` implementa a tela de mapa/cotação. Com alçada ativa, o fluxo compara o valor da oferta vencedora aprovada/congelada em BRL.

## 5. Login e sessão

`index.php` apresenta o formulário quando não existe sessão e direciona para `painel.php` quando o usuário já está autenticado. O Portal envia usuários autenticados diretamente a `sso.php` com um JWT HS256 de uso único; o endpoint valida a assinatura e as claims antes de consultar usuário ou tomar decisões, exige conta local ativa, registra auditoria e regenera o ID da sessão. O SSO não provisiona contas nem perfis e não oferece logout único. O login local valida matrícula e senha em `usuarios`; a senha é verificada com `password_verify()` e armazenada como hash de `password_hash()`. O atalho `APP_DEV_LOGIN_PASSWORD` só é aceito quando `APP_ENV=development`. O uso do atalho é registrado na auditoria e não altera o hash de senha cadastrado.

Após validar as credenciais, a sessão é regenerada e o usuário é encaminhado ao painel. O logout invalida os dados e o cookie da sessão. As permissões são carregadas do banco e conferidas em cada visualização protegida.

## 6. Banco de dados

A stack usa MySQL e InnoDB. A modelagem completa por domínio está em [modelagem-banco.md](modelagem-banco.md); o [schema_mysql.sql](../database/schema_mysql.sql) materializa somente o núcleo inicial. A documentação identifica explicitamente as entidades que ainda faltam no DDL, portanto o script não representa a implementação completa dos requisitos.

Princípios aplicados:

- Chaves internas numéricas e colunas separadas para IDs externos do OMIE.
- Chaves estrangeiras para relacionamentos e índices de consulta por status, pessoa e solicitação.
- `DECIMAL` para preços, quantidades decimais quando aplicáveis e código de moeda explícito.
- Snapshot de regras/etapas para preservar o histórico mesmo após mudanças de configuração.
- Chaves únicas para documento do fornecedor e chave de idempotência da integração.
- Datas e horários do sistema são persistidos e exibidos em Brasília (UTC-03:00); calendários de SLA podem usar outro fuso exclusivamente para calcular a jornada.
- Anexos em armazenamento privado; o banco guarda metadados e referência ao objeto.
- Auditoria protegida contra alterações pela aplicação e por privilégios do banco.

O DDL usa tipo JSON nativo e precisa de MySQL 5.7.8 ou superior. Validar a versão efetivamente instalada antes de importar.

## 7. Segurança e operação

- Usar HTTPS no ambiente integrado; a sessão PHP aplica cookies `HttpOnly` e `SameSite=Lax`, e marca `Secure` quando a requisição está em HTTPS.
- Manter segredos em variáveis de ambiente ou cofre de segredos, nunca no repositório.
- Aplicar autorização no servidor em todas as rotas e consultas, inclusive por unidade/centro de custo.
- Usar prepared statements PDO, validação no servidor e escape contextual de saída HTML.
- Limitar tipo, tamanho e conteúdo de anexos; servir arquivos apenas com autorização.
- Restringir os privilégios do usuário MySQL da aplicação. O usuário de runtime não deve poder editar/excluir auditoria.
- Não colocar credenciais, payloads fiscais ou mensagens técnicas sensíveis nos logs acessíveis ao usuário.
- Configurar cópias de segurança, retenção de dados e processo de restauração antes de produção.

## 8. Requisitos convertidos em módulos

| Módulo | Requisitos cobertos |
|---|---|
| Identidade e autorização | Login por matrícula e senha, perfis, escopo por unidade/centro de custo, visibilidade operacional/gerencial/diretoria. |
| Solicitação | Campos estruturados, dados mestres OMIE, bloqueio após envio, categoria consistente e validação antes de avançar. |
| Workflow | Etapas configuráveis por centro de custo, lógica E/OU, alçadas por valor, retorno, diligência e indicador dinâmico. |
| Compras/cotação | Mínimo de três fornecedores, exceção justificada, evidências, comparação, fornecedor candidato e congelamento da oferta aprovada. |
| OMIE | Sincronização de mestres, pré-validação, criação de pedido, homologação de candidato, webhook, retry, log e idempotência. |
| SLA e indicadores | Calendários, feriados, janelas, pausa por diligência, SLA de ajuste, escalonamento, lead time e processamento. |
| Governança | Auditoria imutável, histórico de comentários, KPIs de gargalo/retorno e mensagens de erro compreensíveis. |

### Visibilidade aplicada no painel

- **Solicitante:** consulta apenas solicitações com `solicitante_id` igual ao próprio usuário e pode abrir nova SC quando seu perfil permitir.
- **Comprador:** consulta SCs atribuídas a si e solicitações sem comprador atribuídas que estejam na etapa de cotação dentro do seu escopo. Ao abrir o mapa pela primeira vez, assume a solicitação em transação; a atribuição fica registrada em `comprador_id`.
- **Gestor:** consulta contratos associados a centros de custo ou unidades do seu escopo em `usuario_perfis`.
- **Diretor:** consulta a visão organizacional de solicitações e contratos.

Cada conta possui um único perfil atribuído. Quando aparecem abas no painel, elas alternam entre a visão do perfil e a fila de aprovações pendentes atribuídas à pessoa; a função de aprovador não representa um segundo perfil. O servidor valida a atribuição antes de executar cada consulta. A tela de detalhe também verifica autorização independentemente do painel.

### Idempotência na criação da SC

O formulário gera uma chave aleatória por tentativa. A tabela `solicitacoes` guarda a chave com unicidade por solicitante e o hash do payload. Cabeçalho e item são gravados na mesma transação InnoDB. Um reenvio com a mesma chave e mesmo conteúdo retorna a SC existente; a mesma chave com conteúdo diferente recebe conflito HTTP 409. O bloqueio do botão no navegador é apenas apoio visual; a garantia é aplicada pelo banco e servidor.

## 9. Decisões ainda necessárias

Antes de fechar o desenho de produção, confirmar: versão/forma de hospedagem do MySQL; formato e limites da API OMIE; política de retenção e privacidade; regra de numeração da SC; calendários de SLA por unidade; limites e storage de arquivos; perfis e matriz real de escopo; canais de notificação; comportamento de cancelamento e reabertura.
