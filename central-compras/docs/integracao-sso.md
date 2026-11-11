# Login SSO pelo Portal de Acesso

## Fluxo

### Endpoint configurado para este ambiente

Cadastre no Portal a URL `https://www.logos-ma.com.br/central-compras/sso.php`. O Portal deve redirecionar para essa URL acrescentando `?token=...`; nao e necessario configurar uma rota `/sso` sem extensao neste projeto.

O receptor localiza a conta ativa nesta ordem: `login_destino`, claim `matricula`, `sub` e, como ultimo fallback, `email`. Os claims `iss`, `aud`, `sub`, `matricula`, `email`, `nome`, `iat`, `exp` e `jti` sao obrigatorios conforme o schema OpenAPI. Tokens invalidos, expirados ou reutilizados retornam HTTP 401 com JSON.

O Portal autenticado envia o navegador por HTTPS para `sso.php?token=...`. O endpoint valida o JWT compacto HS256, `iss`, `aud`, `iat`, `exp` e `jti`, registra o `jti` na tabela anti-replay e localiza somente um usuário local ativo. Não cria conta nem atribui perfil. Em caso de sucesso, grava o evento de auditoria, regenera a sessão local e redireciona para `painel.php`.

O token é recebido pela query string conforme o contrato do Portal. O código da aplicação não o grava em log e envia `Cache-Control: no-store` e `Referrer-Policy: no-referrer`; a configuração do servidor web também precisa excluir a query string dos logs de acesso desse endpoint.

## Valores genéricos a substituir

`app/sso_config.php` aceita o codigo `aud` informado pelo Portal em `PORTAL_SSO_AUD_VALIDOS`, em minusculas. O valor generico `central-compras` nao deve ser usado em homologacao/producao sem confirmacao. O contrato fixa `iss=portal` e HS256; ele descreve TTL aproximado de 60 segundos. O receptor limita a validade a 90 segundos e tolera `iat` ate 300 segundos no futuro; confirme com a equipe do Portal se esses limites sao compativeis.

O segredo HMAC nao esta no codigo. Configure a variavel de ambiente `PORTAL_SSO_SECRET_CENTRAL_COMPRAS` com o segredo recebido do Portal por canal seguro. O receptor exige 64 caracteres hexadecimais e usa esse texto como chave HMAC; confirme que o valor fornecido segue exatamente esse formato. Em producao, mantenha o segredo fora do webroot, em configuracao protegida do Apache/PHP-FPM. Use um segredo proprio por ambiente e nunca o registre no repositorio ou em logs.

Exemplo de nome/valor para a configuração do VirtualHost Apache — substitua o placeholder por segredo real recebido em canal seguro e mantenha o valor fora dos arquivos do projeto:

```apache
# VALOR GENERICO: substituir pelo aud textual cadastrado no Portal para este ambiente.
SetEnv PORTAL_SSO_AUD_VALIDOS "central-compras-hml"
# O segredo real deve vir do Portal por canal seguro; nao copie o placeholder abaixo.
SetEnv PORTAL_SSO_SECRET_CENTRAL_COMPRAS "<SEGREDO_HEXADECIMAL_DE_64_CARACTERES>"
```

A busca da conta segue login_destino, matricula, sub e email, nesta ordem. Claims de identidade sao enviados assinados pelo Portal, e o fallback final compara o email com usuarios.email. Usuarios e perfis devem existir antes do primeiro acesso SSO.

## Instalação

Em banco já existente, aplique uma vez `database/migrations/005_sso_tokens_utilizados.sql`. Em instalação nova, a tabela já está declarada em `database/schema_mysql.sql`. O usuário PDO precisa de `SELECT` na tabela `usuarios`, `INSERT` em `sso_tokens_utilizados` e `auditoria_eventos`, e `UPDATE` em `usuarios.ultimo_acesso_em`. Conceda `DELETE` na tabela anti-replay somente à conta usada pelo job de limpeza, se a infraestrutura separar usuários de banco.

Agende `scripts/limpar_sso_tokens.php` uma vez por dia no Agendador de Tarefas do Windows ou cron. O script só executa em CLI e apaga tokens vencidos há mais de um dia. Exemplo de comando no Windows:

```text
C:\xampp\php\php.exe C:\xampp\htdocs\central-compras\scripts\limpar_sso_tokens.php
```

## Operação e segurança

- Publique o callback em HTTPS com certificado válido. O endpoint recusa HTTP.
- Sincronize o relógio do servidor por NTP; não aumente os limites temporais sem acordo com a equipe do Portal.
- `APP_TRUST_X_FORWARDED_PROTO` fica desativada por padrão. Só defina `1` se TLS terminar em proxy confiável que sobrescreve `X-Forwarded-Proto`, remove qualquer `X-Forwarded-For` recebido do cliente e define esse cabeçalho com o IP de origem; o servidor deve aceitar tráfego somente desse proxy. O IP auditado será o endereço validado recebido.
- Configure Apache/Nginx para não gravar a query string de `/sso.php` no access log. Nunca copie o token para logs, tickets ou mensagens de erro.
- A sessão usa o mesmo nome, cookies e chaves da autenticação local. `session.use_strict_mode` fica habilitado e o ID é regenerado antes de estabelecer a sessão SSO.
- O logout local não encerra a sessão do Portal; o guia não oferece logout único.
- Nunca use dados das claims antes de validar a assinatura. Claims extras são ignoradas e o redirecionamento é fixo, sem `next`/`redirect` vindo do token.

## Homologação

Solicite à equipe do Portal a URL de callback e o `aud` de homologação, o segredo próprio desse ambiente, a origem e o formato das claims de identidade e os usuários de teste. Confirme contas ativas/inativas, matrícula numérica com e sem zeros à esquerda e fallback de e-mail. Execute o roteiro de testes e aceite do guia antes de cadastrar URL e segredo de produção.
