<?php

function loadActiveProfiles(PDO $pdo, $userId)
{
    $statement = $pdo->prepare(
        'SELECT p.codigo, p.nome, up.unidade_id, up.centro_custo_id FROM usuario_perfis up INNER JOIN perfis p ON p.id = up.perfil_id WHERE up.usuario_id = :user_id AND p.ativo = 1 AND (up.valido_ate IS NULL OR up.valido_ate >= CURDATE())'
    );
    $statement->execute(array('user_id' => (int) $userId));
    return $statement->fetchAll();
}

function userHasProfile(array $profiles, $code)
{
    foreach ($profiles as $profile) {
        if ($profile['codigo'] === $code) {
            return true;
        }
    }
    return false;
}

function requireLocalUser($allowForcedPasswordChange = false)
{
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
    if (!$allowForcedPasswordChange && !empty($_SESSION['must_change_password'])) {
        header('Location: alterar_senha.php');
        exit;
    }
}
