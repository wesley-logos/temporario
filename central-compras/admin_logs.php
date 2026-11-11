<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/authorization.php';

startApplicationSession();
requireLocalUser();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(array('error' => 'Método não permitido.'));
    exit;
}

$pdo = databaseConnection();
$profiles = loadActiveProfiles($pdo, $_SESSION['user_id']);
if (!userHasProfile($profiles, 'administrador')) {
    http_response_code(403);
    echo json_encode(array('error' => 'Apenas Administradores podem consultar os logs do sistema.'));
    exit;
}

$draw = isset($_GET['draw']) && is_scalar($_GET['draw']) ? max(0, (int) $_GET['draw']) : 0;
$start = isset($_GET['start']) && is_scalar($_GET['start']) ? max(0, (int) $_GET['start']) : 0;
$requestedLength = isset($_GET['length']) && is_scalar($_GET['length']) ? (int) $_GET['length'] : 25;
$length = in_array($requestedLength, array(10, 25, 50, 100), true) ? $requestedLength : 25;
$searchInput = isset($_GET['search']) && is_array($_GET['search']) && isset($_GET['search']['value'])
    ? $_GET['search']['value']
    : '';
$search = is_string($searchInput)
    ? trim(substr($searchInput, 0, 200))
    : '';
$orderColumns = array(
    0 => 'l.id',
    1 => 'l.ocorrido_em',
    2 => 'u.nome',
    3 => 'l.acao',
    4 => 'l.entidade',
    5 => 'l.item_id',
    6 => 'l.status',
    7 => 'l.ip_usuario',
    8 => 'l.detalhes',
    9 => 'l.erro'
);
$orderInput = isset($_GET['order']) && is_array($_GET['order']) && isset($_GET['order'][0]) && is_array($_GET['order'][0])
    ? $_GET['order'][0]
    : array();
$orderIndex = isset($orderInput['column']) && is_scalar($orderInput['column'])
    ? (int) $orderInput['column']
    : 1;
$orderBy = isset($orderColumns[$orderIndex]) ? $orderColumns[$orderIndex] : 'l.ocorrido_em';
$orderDirection = isset($orderInput['dir']) && is_string($orderInput['dir']) && strtolower($orderInput['dir']) === 'asc'
    ? 'ASC'
    : 'DESC';

try {
    $totalRecords = (int) $pdo->query('SELECT COUNT(*) FROM log_sistema')->fetchColumn();
    $whereSql = '';
    $searchParameters = array();
    if ($search !== '') {
        $escapedSearch = str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $search);
        $searchValue = '%' . $escapedSearch . '%';
        $searchColumns = array(
            'COALESCE(u.nome, \'\')',
            'CAST(l.usuario_id AS CHAR)',
            'l.acao',
            'COALESCE(l.entidade, \'\')',
            'CAST(l.item_id AS CHAR)',
            'l.status',
            'COALESCE(l.ip_usuario, \'\')',
            'COALESCE(l.detalhes, \'\')',
            'COALESCE(l.erro, \'\')',
            'DATE_FORMAT(l.ocorrido_em, \'%d/%m/%Y %H:%i:%s\')'
        );
        $conditions = array();
        foreach ($searchColumns as $index => $column) {
            $parameter = ':search_' . $index;
            $conditions[] = $column . ' LIKE ' . $parameter . ' ESCAPE \'!\'';
            $searchParameters[$parameter] = $searchValue;
        }
        $whereSql = ' WHERE (' . implode(' OR ', $conditions) . ')';
    }

    $filteredRecords = $totalRecords;
    if ($search !== '') {
        $filteredCountQuery = $pdo->prepare(
            'SELECT COUNT(*) FROM log_sistema l LEFT JOIN usuarios u ON u.id = l.usuario_id' . $whereSql
        );
        $filteredCountQuery->execute($searchParameters);
        $filteredRecords = (int) $filteredCountQuery->fetchColumn();
    }

    $logsQuery = $pdo->prepare(
        'SELECT l.id, l.usuario_id, l.ocorrido_em, COALESCE(u.nome, \'\') AS usuario, l.acao, l.entidade, l.item_id, l.status, l.ip_usuario, l.detalhes, l.erro
         FROM log_sistema l
         LEFT JOIN usuarios u ON u.id = l.usuario_id' . $whereSql .
            ' ORDER BY ' . $orderBy . ' ' . $orderDirection . ', l.id DESC LIMIT :page_length OFFSET :page_start'
    );
    foreach ($searchParameters as $parameter => $value) {
        $logsQuery->bindValue($parameter, $value, PDO::PARAM_STR);
    }
    $logsQuery->bindValue(':page_length', $length, PDO::PARAM_INT);
    $logsQuery->bindValue(':page_start', $start, PDO::PARAM_INT);
    $logsQuery->execute();

    $data = array();
    foreach ($logsQuery->fetchAll() as $row) {
        $data[] = array(
            'id' => (int) $row['id'],
            'ocorrido_em' => $row['ocorrido_em'],
            'usuario' => $row['usuario'] !== '' ? $row['usuario'] : ($row['usuario_id'] !== null ? 'Usuário #' . $row['usuario_id'] : 'Sistema'),
            'acao' => $row['acao'],
            'entidade' => $row['entidade'],
            'item_id' => $row['item_id'],
            'status' => $row['status'],
            'ip_usuario' => $row['ip_usuario'],
            'detalhes' => $row['detalhes'],
            'erro' => $row['erro']
        );
    }

    echo json_encode(array(
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => $data
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $exception) {
    error_log('Falha ao consultar logs do sistema: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(array(
        'draw' => $draw,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => array(),
        'error' => 'Não foi possível carregar os logs do sistema. Tente novamente.'
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
