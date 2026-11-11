# Central de Compras

Sistema próprio para solicitações, aprovações, cotações possuindo integração com o OMIE.

## Stack definida

- PHP 7.3 no backend.
- MySQL com tabelas InnoDB e charset `utf8mb4`.
- PDO para acesso ao banco, prepared statements nativos.
- HTML, CSS e JavaScript no protótipo de interface.

## Começar no ambiente local

1. Disponibilize o projeto em um servidor web com PHP 8.4, PDO MySQL, ZipArchive e SimpleXML habilitados (por exemplo, Apache/XAMPP).
2. Importe `database/schema_mysql.sql` em uma instância MySQL. O script cria o banco `central_compras` e o schema inicial.
3. Copie `.env.example` para `.env` e ajuste os valores. `vlucas/phpdotenv` carrega essas variáveis sem substituir valores já definidos pelo servidor; o acesso é centralizado em `app/environment.php`. Não versione `.env`: ele está no `.gitignore` e o `.htaccess` bloqueia acesso HTTP.
4. Abra `index.php` pelo servidor web. Após autenticar, o usuário é direcionado a `painel.php`; acessos ao painel sem sessão são enviados ao login.

O login consulta usuários ativos no MySQL. Cadastros de usuário e atribuição de perfis são administrativos(comunicação com o OMIE): não há cadastro público.

## Documentação

- [Visão geral do sistema, escopo e estado da implementação](docs/sistema.md)
- [Fluxos das ações do sistema por perfil](docs/fluxos-do-sistema.md)
- [Modelagem de dados e regras de persistência](docs/modelagem-banco.md)
- [Schema MySQL inicial](database/schema_mysql.sql)
- [Configuração e operação do login SSO](docs/integracao-sso.md)

## Páginas principais

- `painel.php`: visão por perfil e escopo, acessível após login.
- `recuperar_senha.php`: solicita link de redefinição por e-mail e processa tokens de uso único.
- `solicitacao_nova.php`: formulário protegido por perfil Solicitante.
- `solicitacao_criar.php`: gravação transacional e idempotente da solicitação e item.
- `solicitacao_ver.php`: detalhe visível ao solicitante, comprador, diretor e aprovadores designados; mostra o indicador conforme as etapas ativas.
- `mapa_cotacao.php`: painel do Comprador para cadastrar ofertas por rodada, guardar rascunhos, escolher vencedora e enviar mapa com comprovantes.
- `anexo_baixar.php`: entrega comprovantes de cotação após validar o acesso do usuário.
- `contrato_ver.php`: detalhe de contrato para gestor dentro do seu escopo ou diretor.
- `admin.php`: administração restrita ao perfil Administrador de usuários (incluindo importação em lote CSV/XLSX), produtos, categorias, grupos aprovadores, prazos SLA, calendários, feriados e consulta de logs.
- `admin_logs.php`: endpoint de consulta paginada e pesquisável dos logs, restrito ao perfil Administrador.
- `app/sla.php`: snapshots de política por etapa, cálculo de vencimento e tempo consumido dentro do calendário configurado.
- `aprovar_etapa.php`: registra decisões de aprovação e avança o workflow sequencial por solicitação.
- `app/system_log.php`: registra ações do sistema e erros técnicos em `log_sistema`.
- `sso.php`: callback HTTPS do Portal de Acesso Integrado; valida token de uso único e abre a sessão local.

## Limites atuais

A administração ampla de perfis, pausas de SLA por diligência, alertas/escalonamentos, notificações e integração com o OMIE ainda não estão implementadas. A cotação permite rascunhos e múltiplas rodadas, repetir fornecedores entre rodadas, comparar preços por item, registrar frete/entrega/pagamento, exigir evidência e justificar menos de três fornecedores. O armazenamento dos comprovantes é privado em `storage/private/quotes`; configure permissões de escrita para o usuário que executa o Apache/PHP e confirme que há espaço disponível. Em Linux/XAMPP, o usuário costuma ser `daemon`, mas confirme o usuário dos processos Apache antes de ajustar a propriedade. Por exemplo, se esse for o usuário correto: `sudo chown -R daemon:daemon /opt/lampp/htdocs/central-compras/storage/private/quotes && sudo chmod 700 /opt/lampp/htdocs/central-compras/storage/private/quotes`. O SLA ativo calcula vencimento e progresso usando a janela semanal e feriados do calendário. O receptor SSO requer configuração real de `aud`/segredo e implantação HTTPS antes de aceitar acessos do Portal. Consulte a [visão geral](docs/sistema.md) e a [documentação SSO](docs/integracao-sso.md) para detalhes.

Em bancos existentes, aplique as migrações ainda pendentes em ordem. Aplique `database/migrations/015_password_reset_tokens.sql` e `database/migrations/016_senha_temporaria_obrigatoria.sql` quando ainda estiverem pendentes. Antes de aplicar `database/migrations/017_perfil_unico_por_usuario.sql`, resolva os usuários com mais de um vínculo: a consulta inicial da migração os lista; em Administração > Gerenciamento de usuários, edite e salve cada cadastro para manter um único perfil/escopo. A migração não apaga vínculos automaticamente e a chave única falhará enquanto houver duplicidades. A migração `database/migrations/018_centros_custo_omie_id_opcional.sql` permite cadastrar Centros de Custo locais sem identificador OMIE. Aplique `database/migrations/019_cpf_usuarios.sql` para habilitar o armazenamento de CPF usado na importação de usuários. Configure `APP_BASE_URL` com a URL pública HTTPS e as variáveis `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_NAME` e `MAIL_FROM_ADDRESS` para o SMTP autenticado. `MAIL_ENCRYPTION=ssl` usa SSL implícito e `MAIL_ENCRYPTION=tls` usa STARTTLS. Tokens de redefinição expiram em uma hora, são armazenados somente como hash e o link pode ser usado uma única vez. Contas criadas pela administração recebem uma senha temporária por e-mail e são obrigadas a escolher uma senha pessoal antes de acessar as páginas autenticadas.
