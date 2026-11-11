# GitHub Copilot — instruções do repositório

Este repositório contém a aplicação Central de Compras em `central-compras/`.

Para qualquer tarefa que envolva essa aplicação, leia e siga `central-compras/AGENTS.md` antes de sugerir ou editar código. Esse arquivo contém as regras técnicas detalhadas e é a fonte principal das convenções específicas do projeto.

Regras essenciais:
- Evolua o sistema existente; não o reconstrua do zero nem duplique funções, serviços ou configurações.
- Antes de alterar arquivos, examine sua implementação atual, os chamadores, os documentos relevantes, `central-compras/database/schema_mysql.sql` e as migrações aplicáveis.
- Use PDO com prepared statements, preserve relacionamentos e integridade dos dados, e respeite autenticação local, SSO, autorização por perfil/escopo e CSRF.
- Para e-mails, reutilize `sendApplicationEmail()`; nunca introduza credenciais ou segredos no código ou nos logs.
- Não assuma que recursos descritos na documentação estão implementados; confirme no código.
- Não execute migrações ou operações destrutivas em bancos reais sem autorização explícita e verificação do estado do ambiente.
- Não invente comandos de build/teste. Execute verificações pertinentes quando disponíveis e declare claramente as limitações.
- Responda em português brasileiro e informe arquivos alterados, justificativas, impactos, testes e riscos.

Se a tarefa não estiver relacionada à Central de Compras, examine as instruções do diretório relevante e não imponha automaticamente as convenções desse sistema a outros componentes.
