# Instruções do repositório — temporario

## Escopo

Este repositório contém a aplicação **Central de Compras** no diretório `central-compras/`.

Quando a tarefa envolver a Central de Compras:
1. Leia e siga `central-compras/AGENTS.md` antes de analisar, planejar ou modificar o sistema.
2. Trate `central-compras/AGENTS.md` como a referência técnica específica do projeto.
3. Preserve a implementação existente; não reconstrua funcionalidades nem duplique serviços.
4. Examine o código atual, a documentação, `central-compras/database/schema_mysql.sql` e as migrações relevantes antes de propor alterações.
5. Não considere uma funcionalidade implementada apenas por estar documentada.
6. Não exponha segredos, credenciais, tokens ou dados pessoais.
7. Não execute migrações, alterações destrutivas ou operações contra ambientes reais sem autorização explícita e verificação do estado do banco.
8. Execute apenas testes e comandos que possam ser confirmados a partir dos arquivos e do ambiente disponíveis; informe o que não foi possível executar.

Responda em português brasileiro. Ao concluir, resuma os arquivos alterados, motivos, impactos, testes executados e riscos.

Se a tarefa não envolver `central-compras/`, examine as instruções e a implementação específicas do diretório afetado; não aplique automaticamente as regras exclusivas da Central de Compras a outros projetos deste repositório.
