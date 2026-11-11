
# AGENTS.md - Central de Compras

## 1. Contexto do projeto

- Sistema desenvolvido em PHP.
- Banco de dados MySQL utilizando PDO.
- Ambiente executado em Docker.
- PHPMailer para envio de e-mails.
- Resend como provedor SMTP.
- Composer para gerenciamento de dependências.
- Front-end com HTML, CSS, JavaScript e Bootstrap.
- Sistema em desenvolvimento, com funcionalidades já implementadas.

## 2. Documentação oficial

A pasta /docs contém a documentação funcional e técnica
oficial da Central de Compras.

Documentos existentes:

- docs/epic.txt: requisitos, regras de negócio e critérios de aceite.
- docs/fluxos-do-sistema.md: fluxos e processos do sistema.
- docs/integracao-sso.md: especificações de integração e autenticação SSO.
- docs/modelagem-banco.md: modelagem e estrutura do banco de dados.
- docs/sistema.md: documentação geral do sistema.

Antes de implementar qualquer funcionalidade:

1. Consultar os documentos relacionados ao assunto.
2. Identificar as regras de negócio aplicáveis.
3. Verificar os critérios de aceite da EPIC.
4. Analisar como a funcionalidade está implementada atualmente.
5. Identificar possíveis impactos em outros módulos.

Não duplicar informações da documentação sem necessidade.

Em caso de divergência entre documentos, código e banco
de dados, apresentar o conflito antes de realizar alterações
estruturais ou funcionais.

Não inventar regras de negócio.

## 3. Preservação do sistema existente

O projeto já possui funcionalidades implementadas.

Portanto:

- Não tratar o projeto como uma aplicação nova.
- Não reconstruir funcionalidades existentes sem necessidade.
- Não substituir módulos funcionais por novas implementações
  sem justificativa técnica.
- Não remover funcionalidades existentes.
- Não alterar contratos de funções ou interfaces sem avaliar
  os impactos.
- Priorizar alterações incrementais.
- Reutilizar componentes, funções e serviços existentes.
- Preservar a compatibilidade entre os módulos.

Antes de desenvolver algo novo, verificar se já existe
uma implementação parcial ou completa.

## 4. Arquitetura

- Manter a estrutura atual do projeto.
- Reutilizar funções existentes antes de criar novas.
- Evitar duplicação de código.
- Separar regras de negócio, acesso ao banco e apresentação.
- Respeitar os padrões já utilizados no sistema.
- Evitar introduzir dependências sem necessidade.
- Não realizar refatorações amplas fora do escopo solicitado.

## 5. Banco de dados

- Utilizar PDO e consultas preparadas.
- Nunca concatenar dados do usuário diretamente em SQL.
- Preservar integridade e relacionamentos existentes.
- Utilizar transações quando necessário.
- Avaliar impactos de alterações nas tabelas existentes.
- Não excluir ou renomear colunas sem avaliar dependências.
- Manter docs/modelagem-banco.md atualizado quando
  houver alterações estruturais.

## 6. Segurança

- Validar entradas recebidas do usuário.
- Implementar proteção CSRF nos formulários.
- Não expor informações sensíveis.
- Nunca armazenar senhas em texto puro.
- Utilizar password_hash() e password_verify().
- Não expor erros internos ao usuário.
- Respeitar os controles de autenticação e autorização.
- Verificar permissões no backend, não apenas na interface.

## 7. E-mails

- Utilizar a função sendApplicationEmail().
- Não duplicar configurações SMTP.
- Carregar credenciais pelo environment.php.
- Nunca inserir credenciais diretamente no código.
- Utilizar PHPMailer para envio.

## 8. Docker e ambiente

- Considerar que a aplicação roda dentro de containers.
- Não presumir que comandos executados no host funcionam
  dentro do container.
- Preservar as configurações existentes do Docker Compose.
- Verificar dependências e caminhos dentro do container.

## 9. Regras de negócio

- A EPIC documentada em docs/epic.txt é a referência
  dos requisitos do produto.
- Respeitar os fluxos documentados em
  docs/fluxos-do-sistema.md.
- Respeitar as regras de integração descritas em
  docs/integracao-sso.md.
- Não implementar comportamentos que contrariem as
  regras de negócio documentadas.
- Não considerar uma funcionalidade concluída apenas
  porque sua interface foi desenvolvida.
- Validar regras tanto no frontend quanto no backend
  quando aplicável.

## 10. Processo de desenvolvimento

Para funcionalidades pequenas:

1. Analisar a implementação existente.
2. Consultar a documentação relacionada.
3. Implementar a alteração pontualmente.
4. Verificar impactos e executar testes pertinentes.

Para funcionalidades complexas:

1. Analisar os requisitos.
2. Mapear os módulos envolvidos.
3. Identificar dependências.
4. Verificar funcionalidades existentes.
5. Apresentar um plano técnico antes de implementar.
6. Implementar incrementalmente.
7. Validar os critérios de aceite.
8. Atualizar a documentação quando necessário.

Não iniciar refatorações ou alterações estruturais
amplas sem aprovação.

## 11. Comunicação

- Responder em português brasileiro.
- Explicar alterações técnicas de maneira objetiva.
- Apresentar os arquivos modificados e seus motivos.
- Informar riscos, dependências e impactos.
- Diferenciar funcionalidades implementadas,
  parcialmente implementadas e pendentes.
- Não afirmar que testes foram executados quando
  não tiverem sido executados.

