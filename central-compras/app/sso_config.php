<?php
require_once __DIR__ . '/environment.php';

// VALOR GENERICO: nome da variavel por ambiente; seu conteudo e a lista de valores aud separados por virgula.
$audiencesFromEnvironment = applicationEnv('PORTAL_SSO_AUD_VALIDOS');
$emailSuffixFromEnvironment = applicationEnv('PORTAL_SSO_DOMINIO_EMAIL');
$matriculaDigitsFromEnvironment = applicationEnv('PORTAL_SSO_MATRICULA_DIGITOS');
$matriculaDigits = filter_var($matriculaDigitsFromEnvironment, FILTER_VALIDATE_INT);

/*
 * Configuracao do receptor SSO do Portal de Acesso Integrado.
 * Nunca armazene o segredo HMAC neste arquivo ou no repositorio.
 */
return array(
    // VALOR GENERICO: nome de variavel de ambiente; alinhar com a infraestrutura antes de homologar.
    // Armazena o NOME da variável. sso.php lê o valor do segredo usando esta chave.
    'secret_env' => 'PORTAL_SSO_SECRET_CENTRAL_COMPRAS',

    // Conforme o contrato do guia: o issuer aceito e exatamente "portal".
    'issuer' => 'portal',

    // VALOR GENERICO: fallback para desenvolvimento. Em cada ambiente, prefira configurar PORTAL_SSO_AUD_VALIDOS.
    'audiences' => is_string($audiencesFromEnvironment) && trim($audiencesFromEnvironment) !== ''
        ? array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $audiencesFromEnvironment))), 'strlen'))
        : array('central-compras'),

    // VALOR GENERICO: dominio corporativo ilustrativo para o fallback de e-mail; ajustar ou deixar vazio para desativar.
    'corporate_email_suffix' => is_string($emailSuffixFromEnvironment) ? strtolower(trim($emailSuffixFromEnvironment)) : '@logos-ma.com.br',

    // VALOR GENERICO: o guia sugere 6 para matriculas numericas; confirme e ajuste se o cadastro usar outro padrao.
    'matricula_digits' => $matriculaDigits !== false && $matriculaDigits >= 0 ? $matriculaDigits : 6,

    // Limites definidos no guia. Nao aumentar sem acordo com a equipe do Portal.
    'max_token_age_seconds' => 90,
    'max_iat_future_seconds' => 300,
    'max_token_length' => 4096
);
