<?php
function applicationUserMenuLabel()
{
    $name = isset($_SESSION['user_name']) && is_string($_SESSION['user_name'])
        ? trim($_SESSION['user_name'])
        : '';
    if ($name === '') {
        return 'Minha conta';
    }

    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return 'Minha conta';
    }

    return count($parts) > 1 ? $parts[0] . ' ' . $parts[count($parts) - 1] : $parts[0];
}

function renderApplicationUserMenu()
{
    $label = htmlspecialchars(applicationUserMenuLabel(), ENT_QUOTES, 'UTF-8');
    echo '<details class="account-menu">'
        . '<summary aria-label="Menu da conta de ' . $label . '">' . $label . '</summary>'
        . '<nav class="account-menu-options" aria-label="Opções da conta">'
        . '<a href="meu_perfil.php">Meu perfil</a>'
        . '<a href="ajuda.php">Ajuda</a>'
        . '<a href="logout.php">Sair</a>'
        . '</nav>'
        . '</details>';
}
