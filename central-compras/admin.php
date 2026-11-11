<?php
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/email.php';
require_once __DIR__ . '/app/authorization.php';
require_once __DIR__ . '/app/system_log.php';
require_once __DIR__ . '/app/user_menu.php';
startApplicationSession();
requireLocalUser();

$pdo = databaseConnection();
$profiles = loadActiveProfiles($pdo, $_SESSION['user_id']);
if (!userHasProfile($profiles, 'administrador')) {
    systemLogEvent('acesso_admin_negado', 'usuario', (int) $_SESSION['user_id'], 'negado', array('motivo' => 'perfil_administrador_ausente'));
    http_response_code(403);
    exit('Apenas o perfil Administrador do Setor de Compras pode alterar essas configurações.');
}

$stageNames = array('solicitacao' => 'Solicitação', 'cotacao' => 'Mapa de cotação', 'aprovacao_valor' => 'Aprovação de valor', 'pedido' => 'Pedido de compra');
$message = isset($_SESSION['admin_message']) ? $_SESSION['admin_message'] : '';
$messageType = isset($_SESSION['admin_message_type']) ? $_SESSION['admin_message_type'] : 'success';
$messageInSweetAlert = !empty($_SESSION['admin_message_sweetalert']);
unset($_SESSION['admin_message'], $_SESSION['admin_message_type'], $_SESSION['admin_message_sweetalert']);

function adminSectionForAction($action)
{
    $sectionsByAction = array(
        'create_user' => 'usuarios',
        'preview_user_import' => 'usuarios',
        'confirm_user_import' => 'usuarios',
        'update_user' => 'usuarios',
        'set_user_status' => 'usuarios',
        'delete_user' => 'usuarios',
        'create_category' => 'categorias',
        'update_category' => 'categorias',
        'set_category_status' => 'categorias',
        'delete_category' => 'categorias',
        'create_product' => 'produtos',
        'update_product' => 'produtos',
        'set_product_status' => 'produtos',
        'delete_product' => 'produtos',
        'save_group' => 'grupos',
        'update_group' => 'grupos',
        'delete_group' => 'grupos',
        'add_group_member' => 'grupos',
        'set_group_member_order' => 'grupos',
        'reorder_group_members' => 'grupos',
        'remove_group_member' => 'grupos',
        'save_calendar' => 'calendarios',
        'add_holiday' => 'calendarios',
        'delete_holiday' => 'calendarios',
        'create_center' => 'trilhas',
        'update_center' => 'trilhas',
        'remove_center' => 'trilhas',
        'save_workflow' => 'trilhas'
    );

    return isset($sectionsByAction[$action]) ? $sectionsByAction[$action] : null;
}

function adminRedirect($query = '')
{
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $section = adminSectionForAction($action);

    if ($section === null) {
        $section = isset($_GET['secao']) && in_array($_GET['secao'], array('usuarios', 'categorias', 'produtos', 'grupos', 'calendarios', 'trilhas', 'logs'), true)
            ? $_GET['secao']
            : 'trilhas';
    }

    $location = 'admin.php?secao=' . rawurlencode($section);

    if ($query !== '') {
        $location .= '&' . ltrim($query, '?&');
    }

    header('Location: ' . $location, true, 303);
    exit;
}

function adminFail($message)
{
    systemLogEvent('acao_administrativa_falhou', 'administracao', null, 'negado', array('acao' => isset($_POST['action']) ? $_POST['action'] : null, 'motivo' => $message));
    $_SESSION['admin_message'] = $message;
    $_SESSION['admin_message_type'] = 'error';
    if (isset($_POST['action']) && $_POST['action'] === 'save_workflow') {
        $_SESSION['admin_message_sweetalert'] = true;
    }

    if (isset($_POST['usuario_id']) && filter_var($_POST['usuario_id'], FILTER_VALIDATE_INT)) {
        adminRedirect('?usuario_modal=' . (int) $_POST['usuario_id']);
    }
    if (isset($_POST['grupo_id']) && filter_var($_POST['grupo_id'], FILTER_VALIDATE_INT)) {
        adminRedirect('?grupo_modal=' . (int) $_POST['grupo_id']);
    }
    if (isset($_POST['action']) && $_POST['action'] === 'create_center') {
        adminRedirect('?novo_centro_custo=1');
    }
    if (isset($_POST['action']) && $_POST['action'] === 'create_category') {
        adminRedirect('?nova_categoria=1');
    }
    if (isset($_POST['action']) && $_POST['action'] === 'create_product') {
        adminRedirect('?novo_produto=1');
    }
    if (
        isset($_POST['action']) && $_POST['action'] === 'update_category'
        && isset($_POST['categoria_id']) && filter_var($_POST['categoria_id'], FILTER_VALIDATE_INT)
    ) {
        adminRedirect('?editar_categoria=' . (int) $_POST['categoria_id']);
    }
    if (
        isset($_POST['action']) && $_POST['action'] === 'update_product'
        && isset($_POST['produto_id']) && filter_var($_POST['produto_id'], FILTER_VALIDATE_INT)
    ) {
        adminRedirect('?editar_produto=' . (int) $_POST['produto_id']);
    }
    if (
        isset($_POST['action']) && $_POST['action'] === 'update_center'
        && isset($_POST['centro_custo_id']) && filter_var($_POST['centro_custo_id'], FILTER_VALIDATE_INT)
    ) {
        adminRedirect('?editar_centro_custo=' . (int) $_POST['centro_custo_id']);
    }
    if (isset($_POST['action']) && $_POST['action'] === 'remove_center') {
        adminRedirect();
    }
    if (
        isset($_POST['action']) && $_POST['action'] === 'save_workflow'
        && isset($_POST['centro_custo_id']) && filter_var($_POST['centro_custo_id'], FILTER_VALIDATE_INT)
    ) {
        adminRedirect('?centro_custo_id=' . (int) $_POST['centro_custo_id'] . '&configurar=1');
    }
    if (isset($_POST['action']) && in_array($_POST['action'], array('preview_user_import', 'confirm_user_import'), true)) {
        adminRedirect('?importar_usuarios=1');
    }

    adminRedirect(isset($_POST['centro_custo_id']) && $_POST['centro_custo_id'] !== '' ? '?centro_custo_id=' . (int) $_POST['centro_custo_id'] : '');
}

function validDateValue($date)
{
    $parsed = DateTime::createFromFormat('!Y-m-d', (string) $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function adminImportNormalizeValue($value)
{
    $value = trim((string) $value);
    $value = strtr($value, array(
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'ç' => 'c', 'Ç' => 'C'
    ));
    if (function_exists('iconv')) {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($normalized !== false) {
            $value = $normalized;
        }
    }

    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $value));
}

function adminImportCpfIsValid($cpf)
{
    $cpf = trim((string) $cpf);
    if (!preg_match('/^(?:\d{11}|\d{3}\.\d{3}\.\d{3}-\d{2})$/', $cpf)) {
        return false;
    }
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    for ($position = 9; $position < 11; $position++) {
        $sum = 0;
        for ($index = 0; $index < $position; $index++) {
            $sum += (int) $cpf[$index] * (($position + 1) - $index);
        }
        $digit = ($sum * 10) % 11;
        if ($digit === 10) {
            $digit = 0;
        }
        if ($digit !== (int) $cpf[$position]) {
            return false;
        }
    }

    return true;
}

function adminImportColumnIndex($reference)
{
    preg_match('/^[A-Z]+/', strtoupper((string) $reference), $matches);
    if (!$matches) {
        return 0;
    }

    $index = 0;
    foreach (str_split($matches[0]) as $letter) {
        $index = ($index * 26) + ord($letter) - 64;
    }

    return $index - 1;
}

function adminImportParseXml($contents, $errorMessage)
{
    $previousErrorSetting = libxml_use_internal_errors(true);
    try {
        $xml = simplexml_load_string($contents, 'SimpleXMLElement', LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorSetting);
    }
    if (!$xml) {
        throw new RuntimeException($errorMessage);
    }

    return $xml;
}

function adminImportReadXlsx($path)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('O servidor não possui suporte a arquivos XLSX.');
    }

    $archive = new ZipArchive();
    if ($archive->open($path) !== true) {
        throw new RuntimeException('O arquivo XLSX não é válido ou está corrompido.');
    }

    try {
        $workbookContents = $archive->getFromName('xl/workbook.xml');
        $relationshipContents = $archive->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookContents === false || $relationshipContents === false) {
            throw new RuntimeException('O arquivo XLSX não contém uma estrutura de planilha válida.');
        }
        $workbook = adminImportParseXml($workbookContents, 'Não foi possível ler a estrutura da planilha XLSX.');
        $workbookSheets = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
        if (!$workbookSheets) {
            throw new RuntimeException('A planilha XLSX não possui abas.');
        }
        $relationshipAttributes = $workbookSheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $firstSheetRelationship = isset($relationshipAttributes['id']) ? (string) $relationshipAttributes['id'] : '';
        $relationships = adminImportParseXml($relationshipContents, 'As referências da planilha XLSX são inválidas.');
        $worksheetTarget = '';
        foreach ($relationships->xpath('//*[local-name()="Relationship"]') as $relationship) {
            if ((string) $relationship['Id'] === $firstSheetRelationship) {
                $worksheetTarget = (string) $relationship['Target'];
                break;
            }
        }
        if ($worksheetTarget === '' || in_array('..', explode('/', $worksheetTarget), true)) {
            throw new RuntimeException('Não foi possível localizar a primeira aba da planilha XLSX.');
        }
        if (strpos($worksheetTarget, '/xl/') === 0) {
            $worksheetPath = substr($worksheetTarget, 1);
        } elseif (strpos($worksheetTarget, 'xl/') === 0) {
            $worksheetPath = $worksheetTarget;
        } elseif (strpos($worksheetTarget, 'worksheets/') === 0) {
            $worksheetPath = 'xl/' . $worksheetTarget;
        } else {
            throw new RuntimeException('A primeira aba da planilha XLSX possui um caminho inválido.');
        }

        $sheetInfo = $archive->statName($worksheetPath);
        $sharedInfo = $archive->statName('xl/sharedStrings.xml');
        if ($sheetInfo === false || $sheetInfo['size'] > 10485760 || ($sharedInfo !== false && $sharedInfo['size'] > 10485760)) {
            throw new RuntimeException('A planilha XLSX não contém uma aba válida ou excede o limite de leitura.');
        }
        $sheetContents = $archive->getFromName($worksheetPath);
        if ($sheetContents === false) {
            throw new RuntimeException('Não foi possível ler os dados da planilha XLSX.');
        }
        $sheet = adminImportParseXml($sheetContents, 'Não foi possível ler os dados da planilha XLSX.');

        $sharedStrings = array();
        $sharedContents = $archive->getFromName('xl/sharedStrings.xml');
        if ($sharedContents !== false) {
            $sharedXml = adminImportParseXml($sharedContents, 'A lista de textos da planilha XLSX é inválida.');
            foreach ($sharedXml->si as $sharedItem) {
                $text = '';
                foreach ($sharedItem->xpath('.//*[local-name()="t"]') as $textNode) {
                    $text .= (string) $textNode;
                }
                $sharedStrings[] = $text;
            }
        }

        $rows = array();
        foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowNode) {
            $row = array();
            foreach ($rowNode->xpath('./*[local-name()="c"]') as $cell) {
                $columnIndex = adminImportColumnIndex((string) $cell['r']);
                $type = (string) $cell['t'];
                if ($type === 'inlineStr') {
                    $value = '';
                    foreach ($cell->xpath('.//*[local-name()="t"]') as $textNode) {
                        $value .= (string) $textNode;
                    }
                } else {
                    $value = isset($cell->v) ? (string) $cell->v : '';
                    if ($type === 's') {
                        $sharedIndex = (int) $value;
                        $value = isset($sharedStrings[$sharedIndex]) ? $sharedStrings[$sharedIndex] : '';
                    }
                }
                $row[$columnIndex] = $value;
            }
            if ($row) {
                ksort($row);
                $row['_line'] = (int) $rowNode['r'];
                $rows[] = $row;
            }
        }

        return $rows;
    } finally {
        $archive->close();
    }
}

function adminImportReadCsv($path)
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível ler o arquivo CSV enviado.');
    }

    try {
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            throw new RuntimeException('O arquivo CSV está vazio.');
        }
        $delimiter = ',';
        $delimiterCounts = array(
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t")
        );
        arsort($delimiterCounts);
        $delimiter = key($delimiterCounts);
        rewind($handle);

        $rows = array();
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
            }
            $rows[] = $row;
            if (count($rows) > 501) {
                throw new RuntimeException('A planilha pode conter no máximo 500 usuários por importação.');
            }
        }

        return $rows;
    } finally {
        fclose($handle);
    }
}

function adminImportValidateRows($pdo, $rows)
{
    $expectedHeaders = array('matricula', 'nomecompleto', 'email', 'cpf', 'centrodecusto', 'perfil');
    if (!$rows) {
        throw new RuntimeException('A planilha não contém linhas para importar.');
    }
    if (count($rows) > 501) {
        throw new RuntimeException('A planilha pode conter no máximo 500 usuários por importação.');
    }

    $header = array();
    $headerRow = $rows[0];
    unset($headerRow['_line']);
    foreach ($headerRow as $value) {
        $header[] = adminImportNormalizeValue($value);
    }
    if ($header !== $expectedHeaders) {
        throw new RuntimeException('Cabeçalho inválido. Use as colunas: Matrícula, Nome Completo, E-mail, CPF, Centro de Custo e Perfil.');
    }

    $profiles = array();
    foreach ($pdo->query('SELECT id, codigo, nome FROM perfis WHERE ativo = 1')->fetchAll() as $profile) {
        foreach (array($profile['codigo'], $profile['nome']) as $label) {
            $key = adminImportNormalizeValue($label);
            if (!isset($profiles[$key])) {
                $profiles[$key] = array();
            }
            $profiles[$key][(int) $profile['id']] = (int) $profile['id'];
        }
    }

    $centers = array();
    foreach ($pdo->query('SELECT id, codigo, nome FROM centros_custo WHERE ativo_omie = 1')->fetchAll() as $center) {
        foreach (array($center['codigo'], $center['nome']) as $label) {
            if ($label === null || trim((string) $label) === '') {
                continue;
            }
            $key = adminImportNormalizeValue($label);
            if (!isset($centers[$key])) {
                $centers[$key] = array();
            }
            $centers[$key][(int) $center['id']] = (int) $center['id'];
        }
    }

    $existing = array('matricula' => array(), 'email' => array(), 'cpf' => array());
    foreach ($pdo->query('SELECT matricula, email, cpf FROM usuarios')->fetchAll() as $user) {
        $existing['matricula'][strtolower(trim((string) $user['matricula']))] = true;
        $existing['email'][strtolower(trim((string) $user['email']))] = true;
        if ($user['cpf'] !== null && $user['cpf'] !== '') {
            $existing['cpf'][preg_replace('/\D/', '', (string) $user['cpf'])] = true;
        }
    }

    $inputCounts = array('matricula' => array(), 'email' => array(), 'cpf' => array());
    foreach (array_slice($rows, 1, 500, true) as $row) {
        $matricula = isset($row[0]) ? strtolower(trim((string) $row[0])) : '';
        $email = isset($row[2]) ? strtolower(trim((string) $row[2])) : '';
        $cpf = isset($row[3]) ? preg_replace('/\D/', '', (string) $row[3]) : '';
        foreach (array('matricula' => $matricula, 'email' => $email, 'cpf' => $cpf) as $field => $value) {
            if ($value !== '') {
                $inputCounts[$field][$value] = isset($inputCounts[$field][$value]) ? $inputCounts[$field][$value] + 1 : 1;
            }
        }
    }

    $preview = array();
    foreach (array_slice($rows, 1, 500, true) as $rowIndex => $row) {
        $values = array();
        for ($column = 0; $column < 6; $column++) {
            $values[$column] = isset($row[$column]) ? trim((string) $row[$column]) : '';
        }
        $matricula = $values[0];
        $name = $values[1];
        $email = $values[2];
        $cpf = preg_replace('/\D/', '', $values[3]);
        $centerKey = adminImportNormalizeValue($values[4]);
        $profileKey = adminImportNormalizeValue($values[5]);
        $errors = array();
        $profileId = null;
        $centerId = null;

        $columnIndexes = array_filter(array_keys($row), 'is_int');
        if ($columnIndexes && max($columnIndexes) > 5) {
            $errors[] = 'A linha deve conter somente as seis colunas do modelo.';
        }
        if ($matricula === '' || strlen($matricula) > 40) {
            $errors[] = 'Matrícula obrigatória (máximo de 40 caracteres).';
        } elseif (isset($existing['matricula'][strtolower($matricula)]) || $inputCounts['matricula'][strtolower($matricula)] > 1) {
            $errors[] = 'Matrícula duplicada.';
        }
        if ($name === '' || strlen($name) > 180) {
            $errors[] = 'Nome completo obrigatório (máximo de 180 caracteres).';
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'E-mail inválido.';
        } elseif (isset($existing['email'][strtolower($email)]) || $inputCounts['email'][strtolower($email)] > 1) {
            $errors[] = 'E-mail duplicado.';
        }
        if (!adminImportCpfIsValid($cpf)) {
            $errors[] = 'CPF inválido.';
        } elseif (isset($existing['cpf'][$cpf]) || $inputCounts['cpf'][$cpf] > 1) {
            $errors[] = 'CPF duplicado.';
        }
        if ($centerKey === '' || !isset($centers[$centerKey])) {
            $errors[] = 'Centro de Custo inexistente ou inativo.';
        } elseif (count($centers[$centerKey]) !== 1) {
            $errors[] = 'Centro de Custo ambíguo; informe um código único.';
        } else {
            $centerId = reset($centers[$centerKey]);
        }
        if ($profileKey === '' || !isset($profiles[$profileKey])) {
            $errors[] = 'Perfil inexistente ou inativo.';
        } elseif (count($profiles[$profileKey]) !== 1) {
            $errors[] = 'Perfil ambíguo; informe o código do perfil.';
        } else {
            $profileId = reset($profiles[$profileKey]);
        }

        $preview[] = array(
            'line' => isset($row['_line']) ? $row['_line'] : $rowIndex + 1,
            'matricula' => $matricula,
            'nome' => $name,
            'email' => $email,
            'cpf' => $values[3],
            'centro_custo' => $values[4],
            'perfil' => $values[5],
            'profile_id' => $profileId,
            'center_id' => $centerId,
            'errors' => $errors
        );
    }

    if (!$preview) {
        throw new RuntimeException('A planilha não contém usuários após a linha de cabeçalho.');
    }

    return $preview;
}

function adminSendUserImportTemplate($format)
{
    $headers = array('Matrícula', 'Nome Completo', 'E-mail', 'CPF', 'Centro de Custo', 'Perfil');
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="modelo_importacao_usuarios.csv"');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers, ';');
        fclose($output);
        exit;
    }

    if ($format !== 'xlsx' || !class_exists('ZipArchive')) {
        http_response_code(400);
        exit('Formato de modelo inválido ou suporte XLSX indisponível.');
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'usuarios_xlsx_');
    if ($temporaryPath === false) {
        throw new RuntimeException('Não foi possível gerar o modelo XLSX.');
    }
    $archive = new ZipArchive();
    if ($archive->open($temporaryPath, ZipArchive::OVERWRITE) !== true) {
        unlink($temporaryPath);
        throw new RuntimeException('Não foi possível preparar o modelo XLSX.');
    }

    $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Usuários" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $cells = '';
    foreach ($headers as $index => $header) {
        $column = chr(65 + $index);
        $escapedHeader = htmlspecialchars($header, ENT_XML1, 'UTF-8');
        $cells .= '<c r="' . $column . '1" t="inlineStr"><is><t>' . $escapedHeader . '</t></is></c>';
    }
    $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">' . $cells . '</row></sheetData></worksheet>');
    $archive->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="modelo_importacao_usuarios.xlsx"');
    header('Content-Length: ' . filesize($temporaryPath));
    readfile($temporaryPath);
    unlink($temporaryPath);
    exit;
}

if (isset($_GET['modelo_importacao']) && is_string($_GET['modelo_importacao'])) {
    adminSendUserImportTemplate($_GET['modelo_importacao']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validApplicationCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
        systemLogEvent('acao_administrativa_falhou', 'administracao', null, 'negado', array('motivo' => 'csrf_invalido'));
        http_response_code(400);
        exit('O formulário expirou. Atualize a página e tente novamente.');
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'preview_user_import') {
        unset($_SESSION['user_import_preview']);
        if (!isset($_FILES['planilha']) || !is_array($_FILES['planilha'])) {
            adminFail('Selecione um arquivo CSV ou XLSX para importar.');
        }

        $uploadedFile = $_FILES['planilha'];
        if (!isset($uploadedFile['error']) || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            adminFail('Não foi possível receber o arquivo. Verifique o tamanho e tente novamente.');
        }
        if (!isset($uploadedFile['size']) || (int) $uploadedFile['size'] < 1 || (int) $uploadedFile['size'] > 5242880) {
            adminFail('O arquivo deve ter no máximo 5 MB.');
        }
        $extension = strtolower(pathinfo(isset($uploadedFile['name']) ? $uploadedFile['name'] : '', PATHINFO_EXTENSION));
        if (!in_array($extension, array('csv', 'xlsx'), true)) {
            adminFail('Envie somente arquivos CSV ou XLSX.');
        }

        try {
            $rows = $extension === 'csv'
                ? adminImportReadCsv($uploadedFile['tmp_name'])
                : adminImportReadXlsx($uploadedFile['tmp_name']);
            $_SESSION['user_import_preview'] = array(
                'token' => bin2hex(random_bytes(16)),
                'rows' => adminImportValidateRows($pdo, $rows)
            );
            adminRedirect('?importar_usuarios=1');
        } catch (PDOException $exception) {
            error_log('Falha ao consultar dados para validar planilha de usuários: ' . $exception->getMessage());
            adminFail('Não foi possível consultar os cadastros para validar a planilha.');
        } catch (RuntimeException $exception) {
            adminFail($exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Falha ao validar planilha de usuários: ' . $exception->getMessage());
            adminFail('Não foi possível validar a planilha. Verifique o arquivo e tente novamente.');
        }
    }

    if ($action === 'confirm_user_import') {
        $preview = isset($_SESSION['user_import_preview']) && is_array($_SESSION['user_import_preview'])
            ? $_SESSION['user_import_preview']
            : null;
        $submittedToken = isset($_POST['preview_token']) && is_string($_POST['preview_token']) ? $_POST['preview_token'] : '';
        if (!$preview || !isset($preview['token'], $preview['rows']) || !hash_equals($preview['token'], $submittedToken)) {
            adminFail('A pré-validação expirou. Envie a planilha novamente.');
        }

        try {
            $currentUsers = $pdo->query('SELECT matricula, email, cpf FROM usuarios')->fetchAll();
            $activeProfileIds = array_fill_keys(array_map('intval', $pdo->query('SELECT id FROM perfis WHERE ativo = 1')->fetchAll(PDO::FETCH_COLUMN)), true);
            $activeCenterIds = array_fill_keys(array_map('intval', $pdo->query('SELECT id FROM centros_custo WHERE ativo_omie = 1')->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (PDOException $exception) {
            error_log('Falha ao revalidar planilha de usuários: ' . $exception->getMessage());
            adminFail('Não foi possível revalidar os cadastros. Tente novamente.');
        }
        $existing = array('matricula' => array(), 'email' => array(), 'cpf' => array());
        foreach ($currentUsers as $currentUser) {
            $existing['matricula'][strtolower(trim((string) $currentUser['matricula']))] = true;
            $existing['email'][strtolower(trim((string) $currentUser['email']))] = true;
            if ($currentUser['cpf'] !== null && $currentUser['cpf'] !== '') {
                $existing['cpf'][preg_replace('/\D/', '', (string) $currentUser['cpf'])] = true;
            }
        }

        $seen = array('matricula' => array(), 'email' => array(), 'cpf' => array());
        foreach ($preview['rows'] as &$row) {
            if ($row['errors']) {
                continue;
            }
            if (!isset($activeProfileIds[(int) $row['profile_id']])) {
                $row['errors'][] = 'O Perfil não está mais ativo.';
                $row['profile_id'] = null;
            }
            if (!isset($activeCenterIds[(int) $row['center_id']])) {
                $row['errors'][] = 'O Centro de Custo não está mais ativo.';
                $row['center_id'] = null;
            }
            if ($row['errors']) {
                continue;
            }
            $duplicate = false;
            foreach (array('matricula' => strtolower($row['matricula']), 'email' => strtolower($row['email']), 'cpf' => preg_replace('/\D/', '', $row['cpf'])) as $field => $value) {
                if (isset($existing[$field][$value]) || isset($seen[$field][$value])) {
                    $row['errors'][] = ucfirst($field) . ' duplicado após a pré-validação.';
                    $duplicate = true;
                }
                $seen[$field][$value] = true;
            }
            if ($duplicate) {
                $row['profile_id'] = null;
                $row['center_id'] = null;
            }
        }
        unset($row);
        $_SESSION['user_import_preview'] = $preview;

        $validRows = array();
        foreach ($preview['rows'] as $row) {
            if (!$row['errors']) {
                $validRows[] = $row;
            }
        }
        if (!$validRows) {
            adminFail('Não há usuários válidos para importar. Corrija a planilha e envie novamente.');
        }

        $createdUsers = array();
        try {
            $pdo->beginTransaction();
            $insertUser = $pdo->prepare('INSERT INTO usuarios (matricula, email, nome, cpf, senha_hash, senha_temporaria, ativo) VALUES (:matricula, :email, :name, :cpf, :password_hash, 1, 1)');
            $insertProfile = $pdo->prepare('INSERT INTO usuario_perfis (usuario_id, perfil_id, centro_custo_id, concedido_por) VALUES (:user_id, :profile_id, :center_id, :granted_by)');
            foreach ($validRows as $row) {
                if (!$row['profile_id'] || !$row['center_id']) {
                    throw new RuntimeException('Um perfil ou Centro de Custo selecionado não está mais disponível.');
                }
                $temporaryPassword = bin2hex(random_bytes(16));
                $insertUser->execute(array(
                    'matricula' => $row['matricula'],
                    'email' => $row['email'],
                    'name' => $row['nome'],
                    'cpf' => preg_replace('/\D/', '', $row['cpf']),
                    'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT)
                ));
                $userId = (int) $pdo->lastInsertId();
                $insertProfile->execute(array(
                    'user_id' => $userId,
                    'profile_id' => (int) $row['profile_id'],
                    'center_id' => (int) $row['center_id'],
                    'granted_by' => (int) $_SESSION['user_id']
                ));
                $createdUsers[] = array(
                    'id' => $userId,
                    'matricula' => $row['matricula'],
                    'nome' => $row['nome'],
                    'email' => $row['email'],
                    'password' => $temporaryPassword,
                    'profile_id' => (int) $row['profile_id']
                );
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao importar usuários: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'usuario', null, 'falha', array('operacao' => 'importar_usuarios', 'quantidade' => count($validRows)), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível concluir a importação. Nenhum usuário foi cadastrado; verifique os dados e tente novamente.');
        }

        $sentEmails = 0;
        foreach ($createdUsers as $createdUser) {
            try {
                $subject = 'Acesso à Central de Compras';
                $body = "Olá, " . $createdUser['nome'] . ".\r\n\r\n"
                    . "Sua conta na Central de Compras foi criada.\r\n"
                    . "Matrícula: " . $createdUser['matricula'] . "\r\n"
                    . "Senha temporária: " . $createdUser['password'] . "\r\n\r\n"
                    . "Acesse a Central de Compras com a senha temporária. Para definir uma senha pessoal, use a opção \"Esqueci minha senha\" na tela de login.\r\n";
                sendApplicationEmail($createdUser['email'], $subject, $body);
                $sentEmails++;
                systemLogEvent('criou_usuario', 'usuario', $createdUser['id'], 'sucesso', array('perfil_id' => $createdUser['profile_id'], 'email_enviado' => true, 'origem' => 'importacao'), null, null, $pdo);
            } catch (Throwable $mailException) {
                error_log('Falha ao enviar senha temporária de usuário importado: ' . get_class($mailException));
                systemLogEvent('erro_sistema', 'usuario', $createdUser['id'], 'falha', array('operacao' => 'enviar_senha_temporaria', 'origem' => 'importacao'), 'Falha no envio da senha temporária por e-mail.', null, $pdo);
                systemLogEvent('criou_usuario', 'usuario', $createdUser['id'], 'sucesso', array('perfil_id' => $createdUser['profile_id'], 'email_enviado' => false, 'origem' => 'importacao'), null, null, $pdo);
            }
        }

        unset($_SESSION['user_import_preview']);
        $_SESSION['admin_message'] = count($createdUsers) . ' usuário(s) importado(s). ' . $sentEmails . ' e-mail(s) de acesso enviado(s).';
        $_SESSION['admin_message_type'] = $sentEmails === count($createdUsers) ? 'success' : 'error';
        adminRedirect();
    }

    if ($action === 'create_user') {
        $matricula = isset($_POST['matricula']) && is_string($_POST['matricula']) ? trim($_POST['matricula']) : '';
        $name = isset($_POST['nome']) && is_string($_POST['nome']) ? trim($_POST['nome']) : '';
        $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
        $profileId = isset($_POST['perfil_id']) && is_string($_POST['perfil_id'])
            ? filter_var($_POST['perfil_id'], FILTER_VALIDATE_INT)
            : false;
        if (
            $matricula === '' || strlen($matricula) > 40 || $name === '' || strlen($name) > 180
            || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$profileId
        ) {
            adminFail('Informe matrícula, nome, e-mail e um perfil válidos.');
        }

        try {
            $profileQuery = $pdo->prepare('SELECT id FROM perfis WHERE id = :profile_id AND ativo = 1');
            $profileQuery->execute(array('profile_id' => $profileId));

            if (!$profileQuery->fetchColumn()) {
                adminFail('O perfil selecionado não está ativo. Atualize a página e tente novamente.');
            }

            $temporaryPassword = bin2hex(random_bytes(16));
            $pdo->beginTransaction();
            $insertUser = $pdo->prepare('INSERT INTO usuarios (matricula, email, nome, senha_hash, senha_temporaria, ativo) VALUES (:matricula, :email, :name, :password_hash, 1, 1)');
            $insertUser->execute(array(
                'matricula' => $matricula,
                'email' => $email,
                'name' => $name,
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT)
            ));

            $userId = (int) $pdo->lastInsertId();
            $insertProfile = $pdo->prepare('INSERT INTO usuario_perfis (usuario_id, perfil_id, concedido_por) VALUES (:user_id, :profile_id, :granted_by)');
            $insertProfile->execute(array(
                'user_id' => $userId,
                'profile_id' => $profileId,
                'granted_by' => (int) $_SESSION['user_id']
            ));

            $pdo->commit();

            try {
                $subject = 'Acesso à Central de Compras';
                $body = "Olá, " . $name . ".\r\n\r\n"
                    . "Sua conta na Central de Compras foi criada.\r\n"
                    . "Matrícula: " . $matricula . "\r\n"
                    . "Senha temporária: " . $temporaryPassword . "\r\n\r\n"
                    . "Acesse a Central de Compras com a senha temporária. Para definir uma senha pessoal, use a opção \"Esqueci minha senha\" na tela de login.\r\n";
                sendApplicationEmail($email, $subject, $body);
                systemLogEvent('criou_usuario', 'usuario', $userId, 'sucesso', array('perfil_id' => $profileId, 'email_enviado' => true), null, null, $pdo);
                $_SESSION['admin_message'] = 'Usuário cadastrado e senha temporária enviada por e-mail.';
            } catch (Throwable $mailException) {
                error_log('Falha ao enviar senha temporária do novo usuário: ' . get_class($mailException));
                systemLogEvent('erro_sistema', 'usuario', $userId, 'falha', array('operacao' => 'enviar_senha_temporaria'), 'Falha no envio da senha temporária por e-mail.', null, $pdo);
                systemLogEvent('criou_usuario', 'usuario', $userId, 'sucesso', array('perfil_id' => $profileId, 'email_enviado' => false), null, null, $pdo);
                $_SESSION['admin_message'] = 'Usuário cadastrado, mas não foi possível enviar o e-mail com a senha temporária. A pessoa deverá usar “Esqueci minha senha” para acessar.';
                $_SESSION['admin_message_type'] = 'error';
            }

            adminRedirect();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Falha ao cadastrar usuário: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'usuario', isset($userId) ? $userId : null, 'falha', array('operacao' => 'criou_usuario'), $exception->getMessage(), null, $pdo);
            adminFail($exception->getCode() === '23000' ? 'A matrícula ou o e-mail informado já está cadastrado.' : 'Não foi possível cadastrar o usuário.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Falha ao cadastrar usuário: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'usuario', isset($userId) ? $userId : null, 'falha', array('operacao' => 'criou_usuario'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível cadastrar o usuário.');
        }
    }

    if ($action === 'update_user') {
        $userId = filter_var(isset($_POST['usuario_id']) ? $_POST['usuario_id'] : null, FILTER_VALIDATE_INT);
        $matricula = isset($_POST['matricula']) && is_string($_POST['matricula']) ? trim($_POST['matricula']) : '';
        $name = isset($_POST['nome']) && is_string($_POST['nome']) ? trim($_POST['nome']) : '';
        $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
        $selectedProfileId = isset($_POST['perfil_id']) && is_string($_POST['perfil_id'])
            ? filter_var($_POST['perfil_id'], FILTER_VALIDATE_INT)
            : false;
        if (!$selectedProfileId) {
            adminFail('Selecione exatamente um perfil para o usuário.');
        }
        if (!$userId || $matricula === '' || strlen($matricula) > 40 || $name === '' || strlen($name) > 180 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            adminFail('Informe matrícula, nome e e-mail válidos dentro dos limites permitidos.');
        }

        try {
            $pdo->beginTransaction();
            $userStatement = $pdo->prepare('SELECT id FROM usuarios WHERE id = :user_id FOR UPDATE');
            $userStatement->execute(array('user_id' => $userId));
            if (!$userStatement->fetchColumn()) {
                $pdo->rollBack();
                adminFail('Usuário não encontrado.');
            }

            $currentProfilesStatement = $pdo->prepare('SELECT up.id, up.perfil_id, p.codigo, p.ativo FROM usuario_perfis up INNER JOIN perfis p ON p.id = up.perfil_id WHERE up.usuario_id = :user_id ORDER BY up.id FOR UPDATE');
            $currentProfilesStatement->execute(array('user_id' => $userId));
            $currentProfiles = $currentProfilesStatement->fetchAll();
            $activeProfiles = $pdo->query('SELECT id FROM perfis WHERE ativo = 1 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $activeProfileIds = array_map('intval', $activeProfiles);
            $selectedProfileLinkId = null;
            foreach ($currentProfiles as $currentProfile) {
                if ((int) $currentProfile['perfil_id'] === $selectedProfileId) {
                    $selectedProfileLinkId = (int) $currentProfile['id'];
                    break;
                }
            }
            $hasSelectedProfile = $selectedProfileLinkId !== null;
            if (!in_array($selectedProfileId, $activeProfileIds, true) && !$hasSelectedProfile) {
                $pdo->rollBack();
                adminFail('O perfil selecionado não está ativo. Atualize a página e tente novamente.');
            }

            if ((int) $_SESSION['user_id'] === $userId) {
                foreach ($currentProfiles as $currentProfile) {
                    if ($currentProfile['codigo'] === 'administrador' && (int) $currentProfile['ativo'] === 1 && (int) $currentProfile['perfil_id'] !== $selectedProfileId) {
                        $pdo->rollBack();
                        adminFail('Não é permitido alterar seu próprio perfil Administrador.');
                    }
                }
            }

            $updateUser = $pdo->prepare('UPDATE usuarios SET matricula = :matricula, nome = :name, email = :email WHERE id = :user_id');
            $updateUser->execute(array('matricula' => $matricula, 'name' => $name, 'email' => $email, 'user_id' => $userId));

            if ($hasSelectedProfile) {
                $deleteOtherProfiles = $pdo->prepare('DELETE FROM usuario_perfis WHERE usuario_id = :user_id AND id <> :profile_link_id');
                $deleteOtherProfiles->execute(array('user_id' => $userId, 'profile_link_id' => $selectedProfileLinkId));
            } else {
                $deleteOtherProfiles = $pdo->prepare('DELETE FROM usuario_perfis WHERE usuario_id = :user_id');
                $deleteOtherProfiles->execute(array('user_id' => $userId));
                $insertProfile = $pdo->prepare('INSERT INTO usuario_perfis (usuario_id, perfil_id, concedido_por) VALUES (:user_id, :profile_id, :granted_by)');
                $insertProfile->execute(array(
                    'user_id' => $userId,
                    'profile_id' => $selectedProfileId,
                    'granted_by' => (int) $_SESSION['user_id']
                ));
            }

            $pdo->commit();
            if ((int) $_SESSION['user_id'] === $userId) {
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
            }
            systemLogEvent('editou_usuario', 'usuario', $userId, 'sucesso', array('perfil_selecionado' => $selectedProfileId), null, null, $pdo);
            $_SESSION['admin_message'] = 'Dados do usuário atualizados. Foi mantido um único perfil e escopo.';
            adminRedirect();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao atualizar usuário: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'usuario', $userId, 'falha', array('operacao' => 'editou_usuario'), $exception->getMessage(), null, $pdo);
            adminFail($exception->getCode() === '23000' ? 'A matrícula ou o e-mail informado já está cadastrado.' : 'Não foi possível atualizar o usuário.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao atualizar usuário: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'usuario', $userId, 'falha', array('operacao' => 'editou_usuario'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível atualizar o usuário.');
        }
    }

    if ($action === 'set_user_status') {
        $userId = filter_var(isset($_POST['usuario_id']) ? $_POST['usuario_id'] : null, FILTER_VALIDATE_INT);
        $active = isset($_POST['ativo']) && is_string($_POST['ativo']) ? filter_var($_POST['ativo'], FILTER_VALIDATE_INT) : false;
        if (!$userId || !in_array($active, array(0, 1), true)) {
            adminFail('Usuário ou status inválido.');
        }
        if ((int) $_SESSION['user_id'] === $userId && $active === 0) {
            adminFail('Não é permitido inativar seu próprio usuário.');
        }
        try {
            $statement = $pdo->prepare('UPDATE usuarios SET ativo = :active WHERE id = :user_id');
            $statement->execute(array('active' => $active, 'user_id' => $userId));
            if ($statement->rowCount() !== 1) {
                $exists = $pdo->prepare('SELECT 1 FROM usuarios WHERE id = :user_id');
                $exists->execute(array('user_id' => $userId));
                if (!$exists->fetchColumn()) {
                    adminFail('Usuário não encontrado.');
                }
            }
            systemLogEvent($active === 1 ? 'ativou_usuario' : 'inativou_usuario', 'usuario', $userId, 'sucesso', array(), null, null, $pdo);
            $_SESSION['admin_message'] = $active === 1 ? 'Usuário ativado.' : 'Usuário inativado.';
            adminRedirect();
        } catch (PDOException $exception) {
            error_log('Falha ao alterar status do usuário: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'usuario', $userId, 'falha', array('operacao' => 'alterou_status_usuario'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível alterar o status do usuário.');
        }
    }

    if ($action === 'delete_user') {
        $userId = filter_var(isset($_POST['usuario_id']) ? $_POST['usuario_id'] : null, FILTER_VALIDATE_INT);
        if (!$userId) {
            adminFail('Usuário inválido.');
        }
        if ((int) $_SESSION['user_id'] === $userId) {
            adminFail('Não é permitido remover seu próprio usuário.');
        }
        try {
            $pdo->beginTransaction();
            $deleteProfiles = $pdo->prepare('DELETE FROM usuario_perfis WHERE usuario_id = :user_id');
            $deleteProfiles->execute(array('user_id' => $userId));
            $deleteUser = $pdo->prepare('DELETE FROM usuarios WHERE id = :user_id');
            $deleteUser->execute(array('user_id' => $userId));
            if ($deleteUser->rowCount() !== 1) {
                throw new RuntimeException('Usuário não encontrado.');
            }
            $pdo->commit();
            systemLogEvent('removeu_usuario', 'usuario', $userId, 'sucesso', array(), null, null, $pdo);
            $_SESSION['admin_message'] = 'Usuário removido.';
            adminRedirect();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao remover usuário: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'usuario', $userId, 'falha', array('operacao' => 'removeu_usuario'), $exception->getMessage(), null, $pdo);
            adminFail($exception->getCode() === '23000' ? 'Este usuário possui registros vinculados e não pode ser removido. Inative a conta para preservar o histórico.' : 'Não foi possível remover o usuário.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao remover usuário: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'usuario', $userId, 'falha', array('operacao' => 'removeu_usuario'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível remover o usuário.');
        }
    }

    if ($action === 'create_category' || $action === 'update_category') {
        $categoryId = $action === 'update_category' && isset($_POST['categoria_id'])
            ? filter_var($_POST['categoria_id'], FILTER_VALIDATE_INT)
            : null;
        $omieId = isset($_POST['omie_id']) && is_string($_POST['omie_id']) ? trim($_POST['omie_id']) : '';
        $code = isset($_POST['codigo']) && is_string($_POST['codigo']) ? trim($_POST['codigo']) : '';
        $name = isset($_POST['nome']) && is_string($_POST['nome']) ? trim($_POST['nome']) : '';
        if (($action === 'update_category' && !$categoryId) || $omieId === '' || strlen($omieId) > 80 || strlen($code) > 50 || $name === '' || strlen($name) > 180) {
            adminFail('Informe um ID OMIE válido, código de até 50 caracteres e nome de até 180 caracteres para a categoria.');
        }

        try {
            if ($action === 'create_category') {
                $statement = $pdo->prepare('INSERT INTO categorias (omie_id, codigo, nome, ativo_omie) VALUES (:omie_id, :code, :name, 1)');
                $statement->execute(array('omie_id' => $omieId, 'code' => $code !== '' ? $code : null, 'name' => $name));
                $categoryId = (int) $pdo->lastInsertId();
                systemLogEvent('criou_categoria', 'categoria', $categoryId, 'sucesso', array(), null, null, $pdo);
                $_SESSION['admin_message'] = 'Categoria cadastrada.';
            } else {
                $statement = $pdo->prepare('UPDATE categorias SET omie_id = :omie_id, codigo = :code, nome = :name WHERE id = :category_id');
                $statement->execute(array('omie_id' => $omieId, 'code' => $code !== '' ? $code : null, 'name' => $name, 'category_id' => $categoryId));
                if ($statement->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM categorias WHERE id = :category_id');
                    $exists->execute(array('category_id' => $categoryId));
                    if (!$exists->fetchColumn()) {
                        adminFail('Categoria não encontrada.');
                    }
                }
                systemLogEvent('editou_categoria', 'categoria', $categoryId, 'sucesso', array(), null, null, $pdo);
                $_SESSION['admin_message'] = 'Categoria atualizada.';
            }
            adminRedirect();
        } catch (PDOException $exception) {
            error_log('Falha ao salvar categoria: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'categoria', $categoryId, 'falha', array('operacao' => $action), $exception->getMessage(), null, $pdo);
            adminFail($exception->getCode() === '23000' ? 'Já existe uma categoria cadastrada com esse ID OMIE.' : 'Não foi possível salvar a categoria.');
        }
    }

    if ($action === 'set_category_status') {
        $categoryId = isset($_POST['categoria_id']) ? filter_var($_POST['categoria_id'], FILTER_VALIDATE_INT) : false;
        $active = isset($_POST['ativo']) && is_string($_POST['ativo']) ? filter_var($_POST['ativo'], FILTER_VALIDATE_INT) : false;
        if (!$categoryId || !in_array($active, array(0, 1), true)) {
            adminFail('Categoria ou status inválido.');
        }
        try {
            $statement = $pdo->prepare('UPDATE categorias SET ativo_omie = :active WHERE id = :category_id');
            $statement->execute(array('active' => $active, 'category_id' => $categoryId));
            if ($statement->rowCount() !== 1) {
                $exists = $pdo->prepare('SELECT 1 FROM categorias WHERE id = :category_id');
                $exists->execute(array('category_id' => $categoryId));
                if (!$exists->fetchColumn()) {
                    adminFail('Categoria não encontrada.');
                }
            }
            systemLogEvent($active === 1 ? 'ativou_categoria' : 'inativou_categoria', 'categoria', $categoryId, 'sucesso', array(), null, null, $pdo);
            $_SESSION['admin_message'] = $active === 1 ? 'Categoria ativada.' : 'Categoria inativada.';
            adminRedirect();
        } catch (PDOException $exception) {
            error_log('Falha ao alterar status da categoria: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'categoria', $categoryId, 'falha', array('operacao' => 'alterou_status_categoria'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível alterar o status da categoria.');
        }
    }

    if ($action === 'delete_category') {
        $categoryId = isset($_POST['categoria_id']) ? filter_var($_POST['categoria_id'], FILTER_VALIDATE_INT) : false;
        if (!$categoryId) {
            adminFail('Categoria inválida.');
        }
        try {
            $references = $pdo->prepare('SELECT (SELECT COUNT(*) FROM produtos WHERE categoria_id = :product_category_id) + (SELECT COUNT(*) FROM solicitacoes WHERE categoria_id = :request_category_id)');
            $references->execute(array('product_category_id' => $categoryId, 'request_category_id' => $categoryId));
            if ((int) $references->fetchColumn() > 0) {
                adminFail('Esta categoria possui produtos ou solicitações vinculados. Inative-a para preservar o histórico.');
            }
            $statement = $pdo->prepare('DELETE FROM categorias WHERE id = :category_id');
            $statement->execute(array('category_id' => $categoryId));
            if ($statement->rowCount() !== 1) {
                adminFail('Categoria não encontrada.');
            }
            systemLogEvent('removeu_categoria', 'categoria', $categoryId, 'sucesso', array(), null, null, $pdo);
            $_SESSION['admin_message'] = 'Categoria removida.';
            adminRedirect();
        } catch (PDOException $exception) {
            error_log('Falha ao remover categoria: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'categoria', $categoryId, 'falha', array('operacao' => 'removeu_categoria'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível remover a categoria. Inative-a se houver registros vinculados.');
        }
    }

    if ($action === 'create_product' || $action === 'update_product') {
        $productId = $action === 'update_product' && isset($_POST['produto_id'])
            ? filter_var($_POST['produto_id'], FILTER_VALIDATE_INT)
            : null;
        $omieId = isset($_POST['omie_id']) && is_string($_POST['omie_id']) ? trim($_POST['omie_id']) : '';
        $categoryRaw = isset($_POST['categoria_id']) && is_string($_POST['categoria_id']) ? trim($_POST['categoria_id']) : '';
        $categoryId = $categoryRaw === '' ? null : filter_var($categoryRaw, FILTER_VALIDATE_INT);
        $code = isset($_POST['codigo']) && is_string($_POST['codigo']) ? trim($_POST['codigo']) : '';
        $name = isset($_POST['nome']) && is_string($_POST['nome']) ? trim($_POST['nome']) : '';
        $unit = isset($_POST['unidade_medida']) && is_string($_POST['unidade_medida']) ? trim($_POST['unidade_medida']) : '';
        if (($action === 'update_product' && !$productId) || $omieId === '' || strlen($omieId) > 80 || ($categoryRaw !== '' && !$categoryId) || strlen($code) > 80 || $name === '' || strlen($name) > 240 || strlen($unit) > 30) {
            adminFail('Informe um ID OMIE válido, nome de até 240 caracteres e respeite os limites de código e unidade.');
        }

        try {
            if ($categoryId) {
                $categoryCheck = $pdo->prepare('SELECT id FROM categorias WHERE id = :category_id');
                $categoryCheck->execute(array('category_id' => $categoryId));
                if (!$categoryCheck->fetchColumn()) {
                    adminFail('Selecione uma categoria existente.');
                }
            }
            if ($action === 'create_product') {
                $statement = $pdo->prepare('INSERT INTO produtos (omie_id, categoria_id, codigo, nome, unidade_medida, ativo_omie) VALUES (:omie_id, :category_id, :code, :name, :unit, 1)');
                $statement->execute(array('omie_id' => $omieId, 'category_id' => $categoryId, 'code' => $code !== '' ? $code : null, 'name' => $name, 'unit' => $unit !== '' ? $unit : null));
                $productId = (int) $pdo->lastInsertId();
                systemLogEvent('criou_produto', 'produto', $productId, 'sucesso', array('categoria_id' => $categoryId), null, null, $pdo);
                $_SESSION['admin_message'] = 'Produto cadastrado.';
            } else {
                $statement = $pdo->prepare('UPDATE produtos SET omie_id = :omie_id, categoria_id = :category_id, codigo = :code, nome = :name, unidade_medida = :unit WHERE id = :product_id');
                $statement->execute(array('omie_id' => $omieId, 'category_id' => $categoryId, 'code' => $code !== '' ? $code : null, 'name' => $name, 'unit' => $unit !== '' ? $unit : null, 'product_id' => $productId));
                if ($statement->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM produtos WHERE id = :product_id');
                    $exists->execute(array('product_id' => $productId));
                    if (!$exists->fetchColumn()) {
                        adminFail('Produto não encontrado.');
                    }
                }
                systemLogEvent('editou_produto', 'produto', $productId, 'sucesso', array('categoria_id' => $categoryId), null, null, $pdo);
                $_SESSION['admin_message'] = 'Produto atualizado.';
            }
            adminRedirect();
        } catch (PDOException $exception) {
            error_log('Falha ao salvar produto: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'produto', $productId, 'falha', array('operacao' => $action), $exception->getMessage(), null, $pdo);
            adminFail($exception->getCode() === '23000' ? 'Já existe um produto cadastrado com esse ID OMIE.' : 'Não foi possível salvar o produto.');
        }
    }

    if ($action === 'set_product_status') {
        $productId = isset($_POST['produto_id']) ? filter_var($_POST['produto_id'], FILTER_VALIDATE_INT) : false;
        $active = isset($_POST['ativo']) && is_string($_POST['ativo']) ? filter_var($_POST['ativo'], FILTER_VALIDATE_INT) : false;
        if (!$productId || !in_array($active, array(0, 1), true)) {
            adminFail('Produto ou status inválido.');
        }
        try {
            $statement = $pdo->prepare('UPDATE produtos SET ativo_omie = :active WHERE id = :product_id');
            $statement->execute(array('active' => $active, 'product_id' => $productId));
            if ($statement->rowCount() !== 1) {
                $exists = $pdo->prepare('SELECT 1 FROM produtos WHERE id = :product_id');
                $exists->execute(array('product_id' => $productId));
                if (!$exists->fetchColumn()) {
                    adminFail('Produto não encontrado.');
                }
            }
            systemLogEvent($active === 1 ? 'ativou_produto' : 'inativou_produto', 'produto', $productId, 'sucesso', array(), null, null, $pdo);
            $_SESSION['admin_message'] = $active === 1 ? 'Produto ativado.' : 'Produto inativado.';
            adminRedirect();
        } catch (PDOException $exception) {
            error_log('Falha ao alterar status do produto: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'produto', $productId, 'falha', array('operacao' => 'alterou_status_produto'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível alterar o status do produto.');
        }
    }

    if ($action === 'delete_product') {
        $productId = isset($_POST['produto_id']) ? filter_var($_POST['produto_id'], FILTER_VALIDATE_INT) : false;
        if (!$productId) {
            adminFail('Produto inválido.');
        }
        try {
            $references = $pdo->prepare('SELECT COUNT(*) FROM solicitacao_itens WHERE produto_id = :product_id');
            $references->execute(array('product_id' => $productId));
            if ((int) $references->fetchColumn() > 0) {
                adminFail('Este produto possui solicitações vinculadas. Inative-o para preservar o histórico.');
            }
            $statement = $pdo->prepare('DELETE FROM produtos WHERE id = :product_id');
            $statement->execute(array('product_id' => $productId));
            if ($statement->rowCount() !== 1) {
                adminFail('Produto não encontrado.');
            }
            systemLogEvent('removeu_produto', 'produto', $productId, 'sucesso', array(), null, null, $pdo);
            $_SESSION['admin_message'] = 'Produto removido.';
            adminRedirect();
        } catch (PDOException $exception) {
            error_log('Falha ao remover produto: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'produto', $productId, 'falha', array('operacao' => 'removeu_produto'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível remover o produto. Inative-o se houver registros vinculados.');
        }
    }

    if ($action === 'save_group') {
        $name = trim(isset($_POST['nome']) ? $_POST['nome'] : '');
        $description = trim(isset($_POST['descricao']) ? $_POST['descricao'] : '');
        if ($name === '' || strlen($name) > 160) {
            adminFail('Informe um nome de grupo com até 160 caracteres.');
        }
        try {
            $statement = $pdo->prepare('INSERT INTO grupos_aprovacao (nome, descricao, ativo) VALUES (:name, :description, 1)');
            $statement->execute(array('name' => $name, 'description' => $description !== '' ? $description : null));
            $groupId = (int) $pdo->lastInsertId();
            systemLogEvent('criou_grupo', 'grupo', $groupId, 'sucesso', array(), null, null, $pdo);
            $_SESSION['admin_message'] = 'Grupo de aprovadores criado.';
            adminRedirect();
        } catch (PDOException $exception) {
            systemLogEvent('erro_sistema', 'grupo', isset($groupId) ? $groupId : null, 'falha', array('operacao' => 'criou_grupo'), $exception->getMessage(), null, $pdo);
            adminFail($exception->getCode() === '23000' ? 'Já existe um grupo com esse nome.' : 'Não foi possível criar o grupo.');
        }
    }

    if ($action === 'update_group') {
        $groupId = filter_var(isset($_POST['grupo_id']) ? $_POST['grupo_id'] : null, FILTER_VALIDATE_INT);
        $name = trim(isset($_POST['nome']) ? $_POST['nome'] : '');
        $description = trim(isset($_POST['descricao']) ? $_POST['descricao'] : '');
        if (!$groupId || $name === '' || strlen($name) > 160 || strlen($description) > 255) {
            adminFail('Informe um nome de grupo válido e uma descrição com até 255 caracteres.');
        }
        try {
            $statement = $pdo->prepare('UPDATE grupos_aprovacao SET nome = :name, descricao = :description WHERE id = :id AND ativo = 1');
            $statement->execute(array('name' => $name, 'description' => $description !== '' ? $description : null, 'id' => $groupId));
            systemLogEvent('editou_grupo', 'grupo', $groupId, 'sucesso', array(), null, null, $pdo);
            $_SESSION['admin_message'] = 'Dados do grupo atualizados.';
            adminRedirect('?grupo_modal=' . $groupId);
        } catch (PDOException $exception) {
            systemLogEvent('erro_sistema', 'grupo', $groupId, 'falha', array('operacao' => 'editou_grupo'), $exception->getMessage(), null, $pdo);
            adminFail($exception->getCode() === '23000' ? 'Já existe um grupo com esse nome.' : 'Não foi possível atualizar o grupo.');
        }
    }

    if ($action === 'delete_group') {
        $groupId = filter_var(isset($_POST['grupo_id']) ? $_POST['grupo_id'] : null, FILTER_VALIDATE_INT);
        if (!$groupId) {
            adminFail('Grupo inválido.');
        }
        try {
            $pdo->beginTransaction();
            $usage = $pdo->prepare('SELECT COUNT(*) FROM regras_etapa WHERE grupo_id = :group_id');
            $usage->execute(array('group_id' => $groupId));
            if ((int) $usage->fetchColumn() > 0) {
                $pdo->rollBack();
                systemLogEvent('dissolveu_grupo', 'grupo', $groupId, 'negado', array('motivo' => 'grupo_associado_a_fluxo'), null, null, $pdo);
                $_SESSION['admin_message'] = 'Este grupo está associado a uma ou mais trilhas. Substitua o grupo nessas configurações antes de excluí-lo.';
                $_SESSION['admin_message_type'] = 'error';
                adminRedirect();
            }
            $members = $pdo->prepare('DELETE FROM grupo_aprovadores WHERE grupo_id = :group_id');
            $members->execute(array('group_id' => $groupId));
            $group = $pdo->prepare('DELETE FROM grupos_aprovacao WHERE id = :group_id AND ativo = 1');
            $group->execute(array('group_id' => $groupId));
            if ($group->rowCount() !== 1) {
                throw new RuntimeException('Grupo não encontrado ou já excluído.');
            }
            systemLogEvent('dissolveu_grupo', 'grupo', $groupId, 'sucesso', array('membros_removidos' => $members->rowCount()), null, null, $pdo);
            $pdo->commit();
            $_SESSION['admin_message'] = 'Grupo excluído permanentemente. Os usuários continuam cadastrados no sistema.';
            adminRedirect();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao excluir grupo de aprovadores: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'grupo', $groupId, 'falha', array('operacao' => 'dissolveu_grupo'), $exception->getMessage(), null, $pdo);
            $_SESSION['admin_message'] = 'Não foi possível excluir o grupo.';
            $_SESSION['admin_message_type'] = 'error';
            adminRedirect();
        }
    }

    if ($action === 'add_group_member') {
        $groupId = filter_var(isset($_POST['grupo_id']) ? $_POST['grupo_id'] : null, FILTER_VALIDATE_INT);
        $memberId = filter_var(isset($_POST['usuario_id']) ? $_POST['usuario_id'] : null, FILTER_VALIDATE_INT);
        if (!$groupId || !$memberId) {
            adminFail('Selecione um grupo e um usuário.');
        }
        try {
            $statement = $pdo->prepare('INSERT INTO grupo_aprovadores (grupo_id, usuario_id, ordem_aprovacao, ativo, inicio_vigencia) SELECT g.id, u.id, (SELECT COALESCE(MAX(ga.ordem_aprovacao), 0) + 1 FROM grupo_aprovadores ga WHERE ga.grupo_id = g.id), 1, CURDATE() FROM grupos_aprovacao g INNER JOIN usuarios u ON u.id = :user_id AND u.ativo = 1 WHERE g.id = :group_id AND g.ativo = 1');
            $statement->execute(array('user_id' => $memberId, 'group_id' => $groupId));
            if ($statement->rowCount() !== 1) {
                adminFail('O grupo ou usuário não está ativo.');
            }
            systemLogEvent('adicionou_membro_grupo', 'grupo_aprovador', $memberId, 'sucesso', array('grupo_id' => $groupId), null, null, $pdo);
            $_SESSION['admin_message'] = 'Aprovador adicionado ao grupo.';
            adminRedirect('?grupo_modal=' . $groupId);
        } catch (PDOException $exception) {
            systemLogEvent('erro_sistema', 'grupo_aprovador', $memberId, 'falha', array('operacao' => 'adicionou_membro_grupo', 'grupo_id' => $groupId), $exception->getMessage(), null, $pdo);
            adminFail($exception->getCode() === '23000' ? 'Esse usuário já pertence ao grupo.' : 'Não foi possível adicionar o aprovador.');
        }
    }

    if ($action === 'set_group_member_order') {
        $groupId = filter_var(isset($_POST['grupo_id']) ? $_POST['grupo_id'] : null, FILTER_VALIDATE_INT);
        $memberId = filter_var(isset($_POST['usuario_id']) ? $_POST['usuario_id'] : null, FILTER_VALIDATE_INT);
        $approvalOrder = filter_var(isset($_POST['ordem_aprovacao']) ? $_POST['ordem_aprovacao'] : null, FILTER_VALIDATE_INT);
        if (!$groupId || !$memberId || !$approvalOrder || $approvalOrder > 65535) {
            adminFail('Informe uma ordem hierárquica positiva para o aprovador.');
        }
        $statement = $pdo->prepare('UPDATE grupo_aprovadores SET ordem_aprovacao = :approval_order WHERE grupo_id = :group_id AND usuario_id = :user_id');
        $statement->execute(array('approval_order' => $approvalOrder, 'group_id' => $groupId, 'user_id' => $memberId));
        systemLogEvent('alterou_ordem_membro_grupo', 'grupo_aprovador', $memberId, 'sucesso', array('grupo_id' => $groupId, 'ordem_aprovacao' => $approvalOrder), null, null, $pdo);
        $_SESSION['admin_message'] = 'Ordem hierárquica atualizada.';
        adminRedirect('?grupo_modal=' . $groupId);
    }

    if ($action === 'reorder_group_members') {
        $groupId = filter_var(isset($_POST['grupo_id']) ? $_POST['grupo_id'] : null, FILTER_VALIDATE_INT);
        $submittedMemberIds = isset($_POST['membros']) && is_array($_POST['membros']) ? $_POST['membros'] : array();
        $orderedMemberIds = array();
        foreach ($submittedMemberIds as $submittedMemberId) {
            if (!is_scalar($submittedMemberId)) {
                adminFail('A lista de membros para ordenação é inválida.');
            }
            $memberId = filter_var($submittedMemberId, FILTER_VALIDATE_INT);
            if (!$memberId || in_array($memberId, $orderedMemberIds, true)) {
                adminFail('A lista de membros para ordenação é inválida.');
            }
            $orderedMemberIds[] = $memberId;
        }
        if (!$groupId || !$orderedMemberIds || count($orderedMemberIds) > 65535) {
            adminFail('Informe um grupo e uma lista válida de membros para ordenação.');
        }

        try {
            $pdo->beginTransaction();
            $membersStatement = $pdo->prepare('SELECT ga.usuario_id FROM grupo_aprovadores ga INNER JOIN usuarios u ON u.id = ga.usuario_id AND u.ativo = 1 INNER JOIN grupos_aprovacao g ON g.id = ga.grupo_id AND g.ativo = 1 WHERE ga.grupo_id = :group_id AND ga.ativo = 1 AND (ga.inicio_vigencia IS NULL OR ga.inicio_vigencia <= CURDATE()) AND (ga.fim_vigencia IS NULL OR ga.fim_vigencia >= CURDATE()) ORDER BY ga.ordem_aprovacao, ga.usuario_id FOR UPDATE');
            $membersStatement->execute(array('group_id' => $groupId));
            $currentMemberIds = array_map('intval', $membersStatement->fetchAll(PDO::FETCH_COLUMN));
            $expectedMemberIds = $currentMemberIds;
            $submittedMemberIds = $orderedMemberIds;
            sort($expectedMemberIds, SORT_NUMERIC);
            sort($submittedMemberIds, SORT_NUMERIC);
            if ($expectedMemberIds !== $submittedMemberIds) {
                $pdo->rollBack();
                adminFail('A lista de membros foi alterada. Atualize a página e tente novamente.');
            }

            $updateOrder = $pdo->prepare('UPDATE grupo_aprovadores SET ordem_aprovacao = :approval_order WHERE grupo_id = :group_id AND usuario_id = :user_id');
            foreach ($orderedMemberIds as $index => $memberId) {
                $updateOrder->execute(array(
                    'approval_order' => $index + 1,
                    'group_id' => $groupId,
                    'user_id' => $memberId
                ));
            }
            $pdo->commit();
            systemLogEvent('reordenou_membros_grupo', 'grupo', $groupId, 'sucesso', array('membros' => $orderedMemberIds), null, null, $pdo);
            $_SESSION['admin_message'] = 'Ordem hierárquica dos membros atualizada.';
            adminRedirect('?grupo_modal=' . $groupId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao reordenar membros do grupo: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'grupo', $groupId, 'falha', array('operacao' => 'reordenou_membros_grupo'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível atualizar a ordem dos membros.');
        }
    }

    if ($action === 'remove_group_member') {
        $groupId = filter_var(isset($_POST['grupo_id']) ? $_POST['grupo_id'] : null, FILTER_VALIDATE_INT);
        $memberId = filter_var(isset($_POST['usuario_id']) ? $_POST['usuario_id'] : null, FILTER_VALIDATE_INT);
        if (!$groupId || !$memberId) {
            adminFail('Associação de grupo inválida.');
        }
        $statement = $pdo->prepare('DELETE FROM grupo_aprovadores WHERE grupo_id = :group_id AND usuario_id = :user_id');
        $statement->execute(array('group_id' => $groupId, 'user_id' => $memberId));
        systemLogEvent('removeu_membro_grupo', 'grupo_aprovador', $memberId, 'sucesso', array('grupo_id' => $groupId), null, null, $pdo);
        $_SESSION['admin_message'] = 'Aprovador removido do grupo.';
        adminRedirect('?grupo_modal=' . $groupId);
    }

    if ($action === 'save_calendar') {
        $name = trim(isset($_POST['nome']) ? $_POST['nome'] : '');
        $timezone = trim(isset($_POST['fuso_horario']) ? $_POST['fuso_horario'] : 'America/Sao_Paulo');
        $weekdays = isset($_POST['dias_semana']) && is_array($_POST['dias_semana']) ? array_values(array_unique(array_map('intval', $_POST['dias_semana']))) : array();
        $start = isset($_POST['hora_inicio']) ? $_POST['hora_inicio'] : '';
        $end = isset($_POST['hora_fim']) ? $_POST['hora_fim'] : '';
        if ($name === '' || !$weekdays || array_diff($weekdays, array(1, 2, 3, 4, 5, 6, 7)) || !in_array($timezone, DateTimeZone::listIdentifiers(), true) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $end <= $start) {
            adminFail('Informe nome, fuso horário, dias de trabalho e uma janela de horário válida.');
        }
        try {
            $statement = $pdo->prepare('INSERT INTO calendarios (nome, fuso_horario, dias_semana_json, hora_inicio, hora_fim, criado_por) VALUES (:name, :timezone, :weekdays, :start_time, :end_time, :user_id)');
            $statement->execute(array('name' => $name, 'timezone' => $timezone, 'weekdays' => json_encode($weekdays), 'start_time' => $start . ':00', 'end_time' => $end . ':00', 'user_id' => $_SESSION['user_id']));
            $calendarId = (int) $pdo->lastInsertId();
            systemLogEvent('criou_calendario', 'calendario', $calendarId, 'sucesso', array(), null, null, $pdo);
            $_SESSION['admin_message'] = 'Calendário de trabalho cadastrado.';
            adminRedirect();
        } catch (PDOException $exception) {
            error_log('Falha ao cadastrar calendário: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'calendario', isset($calendarId) ? $calendarId : null, 'falha', array('operacao' => 'criou_calendario'), $exception->getMessage(), null, $pdo);
            adminFail($exception->getCode() === '23000' ? 'Já existe um calendário com esse nome.' : 'Não foi possível salvar o calendário.');
        }
    }

    if ($action === 'add_holiday') {
        $calendarId = filter_var(isset($_POST['calendario_id']) ? $_POST['calendario_id'] : null, FILTER_VALIDATE_INT);
        $date = isset($_POST['data']) ? $_POST['data'] : '';
        $description = trim(isset($_POST['descricao']) ? $_POST['descricao'] : '');
        if (!$calendarId || !validDateValue($date) || $description === '') {
            adminFail('Informe um calendário, uma data válida e a descrição do feriado.');
        }
        try {
            $statement = $pdo->prepare('INSERT INTO feriados (calendario_id, data, descricao) VALUES (:calendar_id, :holiday_date, :description)');
            $statement->execute(array('calendar_id' => $calendarId, 'holiday_date' => $date, 'description' => $description));
            $holidayId = (int) $pdo->lastInsertId();
            systemLogEvent('adicionou_feriado', 'feriado', $holidayId, 'sucesso', array('calendario_id' => $calendarId, 'data' => $date), null, null, $pdo);
            $_SESSION['admin_message'] = 'Feriado adicionado ao calendário.';
            adminRedirect('?calendario_id=' . $calendarId);
        } catch (PDOException $exception) {
            error_log('Falha ao cadastrar feriado: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'calendario', $calendarId, 'falha', array('operacao' => 'adicionou_feriado'), $exception->getMessage(), null, $pdo);
            adminFail($exception->getCode() === '23000' ? 'Esse feriado já está cadastrado nesse calendário.' : 'Não foi possível salvar o feriado.');
        }
    }

    if ($action === 'delete_holiday') {
        $holidayId = filter_var(isset($_POST['feriado_id']) ? $_POST['feriado_id'] : null, FILTER_VALIDATE_INT);
        $calendarId = filter_var(isset($_POST['calendario_id']) ? $_POST['calendario_id'] : null, FILTER_VALIDATE_INT);
        if (!$holidayId || !$calendarId) {
            adminFail('Feriado inválido.');
        }
        $statement = $pdo->prepare('DELETE FROM feriados WHERE id = :id AND calendario_id = :calendar_id');
        $statement->execute(array('id' => $holidayId, 'calendar_id' => $calendarId));
        systemLogEvent('removeu_feriado', 'feriado', $holidayId, 'sucesso', array('calendario_id' => $calendarId), null, null, $pdo);
        $_SESSION['admin_message'] = 'Feriado removido do calendário.';
        adminRedirect('?calendario_id=' . $calendarId);
    }

    if ($action === 'create_center') {
        $code = isset($_POST['codigo']) && is_string($_POST['codigo']) ? trim($_POST['codigo']) : '';
        $name = isset($_POST['nome']) && is_string($_POST['nome']) ? trim($_POST['nome']) : '';
        $unitId = isset($_POST['unidade_id']) && $_POST['unidade_id'] !== ''
            ? filter_var($_POST['unidade_id'], FILTER_VALIDATE_INT)
            : null;
        $_SESSION['center_form_draft'] = array('codigo' => $code, 'nome' => $name, 'unidade_id' => $unitId ? (int) $unitId : null);

        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $nameLength > 180 || strlen($code) > 50 || ($unitId !== null && !$unitId)) {
            adminFail('Informe um nome válido, um código com até 50 caracteres e uma unidade existente, se desejar.');
        }

        try {
            if ($unitId !== null) {
                $unitStatement = $pdo->prepare('SELECT id FROM unidades WHERE id = :id AND ativa = 1');
                $unitStatement->execute(array('id' => $unitId));
                if (!$unitStatement->fetchColumn()) {
                    adminFail('A unidade selecionada não está ativa.');
                }
            }

            $insertCenter = $pdo->prepare('INSERT INTO centros_custo (omie_id, unidade_id, codigo, nome, ativo_omie) VALUES (NULL, :unit_id, :code, :name, 1)');
            $insertCenter->execute(array(
                'unit_id' => $unitId,
                'code' => $code !== '' ? $code : null,
                'name' => $name
            ));
            $centerId = (int) $pdo->lastInsertId();
            unset($_SESSION['center_form_draft']);
            systemLogEvent('criou_centro_custo', 'centro_custo', $centerId, 'sucesso', array('codigo' => $code, 'unidade_id' => $unitId), null, null, $pdo);
            $_SESSION['admin_message'] = 'Centro de Custo cadastrado. Configure a trilha de aprovação abaixo.';
            adminRedirect('?centro_custo_id=' . $centerId . '&configurar=1');
        } catch (PDOException $exception) {
            error_log('Falha ao cadastrar Centro de Custo: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'centro_custo', isset($centerId) ? $centerId : null, 'falha', array('operacao' => 'criou_centro_custo'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível cadastrar o Centro de Custo. Tente novamente mais tarde.');
        }
    }

    if ($action === 'update_center') {
        $centerId = filter_var(isset($_POST['centro_custo_id']) ? $_POST['centro_custo_id'] : null, FILTER_VALIDATE_INT);
        $name = isset($_POST['nome']) && is_string($_POST['nome']) ? trim($_POST['nome']) : '';
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if (!$centerId || $name === '' || $nameLength > 180) {
            adminFail('Informe um Centro de Custo e um nome válido com até 180 caracteres.');
        }
        try {
            $updateCenter = $pdo->prepare('UPDATE centros_custo SET nome = :name WHERE id = :center_id');
            $updateCenter->execute(array('name' => $name, 'center_id' => $centerId));
            if ($updateCenter->rowCount() !== 1) {
                $exists = $pdo->prepare('SELECT 1 FROM centros_custo WHERE id = :center_id');
                $exists->execute(array('center_id' => $centerId));
                if (!$exists->fetchColumn()) {
                    adminFail('Centro de Custo não encontrado.');
                }
            }
            systemLogEvent('editou_centro_custo', 'centro_custo', $centerId, 'sucesso', array('campos' => array('nome')), null, null, $pdo);
            $_SESSION['admin_message'] = 'Nome do Centro de Custo atualizado.';
            adminRedirect('?centro_custo_id=' . $centerId);
        } catch (PDOException $exception) {
            error_log('Falha ao editar Centro de Custo: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'centro_custo', $centerId, 'falha', array('operacao' => 'editou_centro_custo'), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível editar o Centro de Custo.');
        }
    }

    if ($action === 'remove_center') {
        $centerId = filter_var(isset($_POST['centro_custo_id']) ? $_POST['centro_custo_id'] : null, FILTER_VALIDATE_INT);
        if (!$centerId) {
            adminFail('Centro de Custo inválido.');
        }
        try {
            $pdo->beginTransaction();
            $centerLock = $pdo->prepare('SELECT id FROM centros_custo WHERE id = :center_id FOR UPDATE');
            $centerLock->execute(array('center_id' => $centerId));
            if (!$centerLock->fetchColumn()) {
                $pdo->rollBack();
                adminFail('Centro de Custo não encontrado.');
            }

            $references = array(
                'usuario_perfis' => 'SELECT COUNT(*) FROM usuario_perfis WHERE centro_custo_id = :center_id',
                'fluxo_centro_custo' => 'SELECT COUNT(*) FROM fluxo_centro_custo WHERE centro_custo_id = :center_id',
                'solicitacoes' => 'SELECT COUNT(*) FROM solicitacoes WHERE centro_custo_id = :center_id',
                'contratos' => 'SELECT COUNT(*) FROM contratos WHERE centro_custo_id = :center_id'
            );
            $referenceCounts = array();
            foreach ($references as $table => $sql) {
                $referenceQuery = $pdo->prepare($sql);
                $referenceQuery->execute(array('center_id' => $centerId));
                $referenceCounts[$table] = (int) $referenceQuery->fetchColumn();
            }
            if (array_sum($referenceCounts) > 0) {
                $pdo->rollBack();
                systemLogEvent('removeu_centro_custo', 'centro_custo', $centerId, 'negado', array('motivo' => 'registros_vinculados'), null, null, $pdo);
                $_SESSION['admin_message'] = 'Este Centro de Custo possui perfis, trilhas, solicitações ou contratos vinculados e não pode ser removido.';
                $_SESSION['admin_message_type'] = 'error';
                adminRedirect();
            }

            $removeCenter = $pdo->prepare('DELETE FROM centros_custo WHERE id = :center_id');
            $removeCenter->execute(array('center_id' => $centerId));
            if ($removeCenter->rowCount() !== 1) {
                throw new RuntimeException('Centro de Custo não encontrado durante a remoção.');
            }
            $pdo->commit();
            systemLogEvent('removeu_centro_custo', 'centro_custo', $centerId, 'sucesso', array(), null, null, $pdo);
            $_SESSION['admin_message'] = 'Centro de Custo removido.';
            adminRedirect();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao remover Centro de Custo: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'centro_custo', $centerId, 'falha', array('operacao' => 'removeu_centro_custo'), $exception->getMessage(), null, $pdo);
            $_SESSION['admin_message'] = 'Não foi possível remover o Centro de Custo.';
            $_SESSION['admin_message_type'] = 'error';
            adminRedirect();
        }
    }

    if ($action === 'save_workflow') {
        $centerId = filter_var(isset($_POST['centro_custo_id']) ? $_POST['centro_custo_id'] : null, FILTER_VALIDATE_INT);
        $directorLimitRaw = isset($_POST['limite_alcada_diretor']) ? str_replace(',', '.', trim($_POST['limite_alcada_diretor'])) : '';
        $directorLimit = null;
        if ($directorLimitRaw !== '') {
            if (preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $directorLimitRaw) !== 1) {
                adminFail('Informe um limite de alçada válido, maior ou igual a zero.');
            }
            $limitParts = explode('.', $directorLimitRaw, 2);
            $directorLimit = ltrim($limitParts[0], '0');
            $directorLimit = ($directorLimit === '' ? '0' : $directorLimit) . '.' . str_pad(isset($limitParts[1]) ? $limitParts[1] : '', 2, '0');
        }
        $stages = isset($_POST['etapas']) && is_array($_POST['etapas']) ? $_POST['etapas'] : array();
        $activeStages = array();
        foreach ($stageNames as $code => $label) {
            if (isset($stages[$code]) && is_array($stages[$code]) && !empty($stages[$code]['ativa'])) {
                $activeStages[] = $code;
            }
        }
        if (!$centerId || !$activeStages) {
            adminFail('Selecione um Centro de Custo e habilite ao menos uma etapa.');
        }
        if (in_array('aprovacao_valor', $activeStages, true) !== in_array('cotacao', $activeStages, true)) {
            adminFail('Mapa de cotação e Aprovação de valor devem estar ativos juntos: a aprovação decide a oferta vencedora do mapa.');
        }

        try {
            $centerStatement = $pdo->prepare('SELECT id FROM centros_custo WHERE id = :id AND ativo_omie = 1');
            $centerStatement->execute(array('id' => $centerId));
            if (!$centerStatement->fetchColumn()) {
                adminFail('O Centro de Custo selecionado não está ativo no OMIE.');
            }
            $validated = array();
            foreach ($activeStages as $code) {
                $config = isset($stages[$code]) && is_array($stages[$code]) ? $stages[$code] : array();
                $approverType = isset($config['responsavel_tipo']) ? $config['responsavel_tipo'] : 'grupo';
                $groupId = filter_var(isset($config['grupo_id']) ? $config['grupo_id'] : null, FILTER_VALIDATE_INT);
                $rawUserIds = isset($config['usuario_ids']) && is_array($config['usuario_ids']) ? $config['usuario_ids'] : array();
                $userIds = array();
                foreach ($rawUserIds as $rawUserId) {
                    if ($rawUserId === '' || $rawUserId === null) {
                        continue;
                    }
                    $parsedUserId = filter_var($rawUserId, FILTER_VALIDATE_INT);
                    if (!$parsedUserId || in_array((int) $parsedUserId, $userIds, true)) {
                        adminFail('Selecione usuários aprovadores distintos.');
                    }
                    $userIds[] = (int) $parsedUserId;
                }
                $userId = $userIds ? $userIds[0] : null;
                $mode = isset($config['modo']) ? $config['modo'] : '';
                $duration = isset($config['duracao']) ? str_replace(',', '.', trim($config['duracao'])) : '';
                $unit = isset($config['unidade_tempo']) ? $config['unidade_tempo'] : '';
                $regime = isset($config['regime']) ? $config['regime'] : '';
                $calendarId = isset($config['calendario_id']) && $config['calendario_id'] !== '' ? filter_var($config['calendario_id'], FILTER_VALIDATE_INT) : null;
                $windowStart = isset($config['janela_inicio']) ? $config['janela_inicio'] : '';
                $windowEnd = isset($config['janela_fim']) ? $config['janela_fim'] : '';
                $usesGroup = in_array($approverType, array('grupo', 'grupo_usuario'), true);
                $usesUsers = in_array($approverType, array('usuario', 'grupo_usuario'), true);
                if (!in_array($approverType, array('grupo', 'usuario', 'grupo_usuario'), true) || !in_array($mode, array('E', 'OU'), true) || !is_numeric($duration) || (float) $duration <= 0 || !in_array($unit, array('horas', 'dias'), true) || !in_array($regime, array('corridos', 'uteis'), true) || ($usesGroup && !$groupId) || ($usesUsers && !$userIds) || (!$usesUsers && $userIds)) {
                    adminFail('Cada etapa ativa precisa de grupo aprovador, regra E/OU e prazo válido.');
                }
                if (($unit === 'horas' || $regime === 'uteis') && !$calendarId) {
                    adminFail('Prazos em horas ou dias úteis exigem um calendário de trabalho cadastrado.');
                }
                if ($calendarId) {
                    $calendarStatement = $pdo->prepare('SELECT id, hora_inicio, hora_fim FROM calendarios WHERE id = :id');
                    $calendarStatement->execute(array('id' => $calendarId));
                    $calendar = $calendarStatement->fetch();
                    if (!$calendar) {
                        adminFail('Selecione um calendário existente.');
                    }
                    $windowStart = $calendar['hora_inicio'];
                    $windowEnd = $calendar['hora_fim'];
                }
                if ($usesGroup) {
                    $groupStatement = $pdo->prepare('SELECT g.id FROM grupos_aprovacao g WHERE g.id = :id AND g.ativo = 1 AND EXISTS (SELECT 1 FROM grupo_aprovadores ga INNER JOIN usuarios u ON u.id = ga.usuario_id WHERE ga.grupo_id = g.id AND ga.ativo = 1 AND u.ativo = 1 AND (ga.inicio_vigencia IS NULL OR ga.inicio_vigencia <= CURDATE()) AND (ga.fim_vigencia IS NULL OR ga.fim_vigencia >= CURDATE()))');
                    $groupStatement->execute(array('id' => $groupId));
                    if (!$groupStatement->fetchColumn()) {
                        adminFail('O grupo selecionado precisa ter ao menos um aprovador ativo.');
                    }
                    if ($mode === 'E') {
                        $orderStatement = $pdo->prepare('SELECT COUNT(*), COUNT(DISTINCT ga.ordem_aprovacao) FROM grupo_aprovadores ga INNER JOIN usuarios u ON u.id = ga.usuario_id AND u.ativo = 1 WHERE ga.grupo_id = :id AND ga.ativo = 1 AND (ga.inicio_vigencia IS NULL OR ga.inicio_vigencia <= CURDATE()) AND (ga.fim_vigencia IS NULL OR ga.fim_vigencia >= CURDATE())');
                        $orderStatement->execute(array('id' => $groupId));
                        $orderCounts = $orderStatement->fetch(PDO::FETCH_NUM);
                        if (!$orderCounts || (int) $orderCounts[0] !== (int) $orderCounts[1]) {
                            adminFail('Para a regra E, configure uma ordem hierárquica exclusiva para cada membro do grupo.');
                        }
                    }
                }
                if ($usesUsers) {
                    $userStatement = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id AND ativo = 1');
                    foreach ($userIds as $selectedUserId) {
                        $userStatement->execute(array('id' => $selectedUserId));
                        if (!$userStatement->fetchColumn()) {
                            adminFail('Um dos usuários selecionados não está ativo.');
                        }
                    }
                } else {
                    $userIds = array();
                    $userId = null;
                }
                if (!$usesGroup) {
                    $groupId = null;
                }
                $validated[$code] = array(
                    'responsavel_tipo' => $approverType,
                    'grupo_id' => $groupId,
                    'usuario_id' => $userId,
                    'usuario_ids' => $userIds,
                    'modo' => $mode,
                    'duracao' => number_format((float) $duration, 2, '.', ''),
                    'unidade_tempo' => $unit,
                    'regime' => $regime,
                    'calendario_id' => $calendarId,
                    'janela_inicio' => $windowStart !== '' ? $windowStart . ':00' : null,
                    'janela_fim' => $windowEnd !== '' ? $windowEnd . ':00' : null
                );
            }

            $pdo->beginTransaction();
            $name = 'Fluxo CC ' . (int) $centerId;
            $versionStatement = $pdo->prepare('SELECT COALESCE(MAX(versao), 0) FROM fluxos_aprovacao WHERE nome = :name');
            $versionStatement->execute(array('name' => $name));
            $version = (int) $versionStatement->fetchColumn() + 1;
            $flowInsert = $pdo->prepare('INSERT INTO fluxos_aprovacao (nome, versao, ativo, vigente_desde, limite_alcada_diretor) VALUES (:name, :version, 1, NOW(), :director_limit)');
            $flowInsert->execute(array('name' => $name, 'version' => $version, 'director_limit' => $directorLimit));
            $flowId = (int) $pdo->lastInsertId();
            $pdo->prepare('DELETE FROM fluxo_centro_custo WHERE centro_custo_id = :center_id')->execute(array('center_id' => $centerId));
            $mappingInsert = $pdo->prepare('INSERT INTO fluxo_centro_custo (fluxo_id, centro_custo_id) VALUES (:flow_id, :center_id)');
            $mappingInsert->execute(array('flow_id' => $flowId, 'center_id' => $centerId));
            $ruleInsert = $pdo->prepare('INSERT INTO regras_etapa (fluxo_id, codigo_etapa, ordem, ativa, modo, responsavel_tipo, grupo_id, usuario_id) VALUES (:flow_id, :stage, :step_order, 1, :mode, :responsible_type, :group_id, :user_id)');
            $ruleUserInsert = $pdo->prepare('INSERT INTO regras_etapa_usuarios (regra_etapa_id, usuario_id, ordem) VALUES (:rule_id, :user_id, :user_order)');
            $slaInsert = $pdo->prepare('INSERT INTO politicas_sla (fluxo_id, codigo_etapa, duracao, unidade_tempo, regime_contagem, calendario_id, janela_inicio, janela_fim) VALUES (:flow_id, :stage, :duration, :unit, :regime, :calendar_id, :window_start, :window_end)');
            $order = 0;
            foreach ($stageNames as $code => $label) {
                if (!isset($validated[$code])) {
                    continue;
                }
                $config = $validated[$code];
                $order++;
                $ruleInsert->execute(array('flow_id' => $flowId, 'stage' => $code, 'step_order' => $order, 'mode' => $config['modo'], 'responsible_type' => $config['responsavel_tipo'], 'group_id' => $config['grupo_id'], 'user_id' => $config['usuario_id']));
                $ruleId = (int) $pdo->lastInsertId();
                foreach ($config['usuario_ids'] as $userOrder => $directUserId) {
                    $ruleUserInsert->execute(array('rule_id' => $ruleId, 'user_id' => $directUserId, 'user_order' => $userOrder + 1));
                }
                $slaInsert->execute(array('flow_id' => $flowId, 'stage' => $code, 'duration' => $config['duracao'], 'unit' => $config['unidade_tempo'], 'regime' => $config['regime'], 'calendar_id' => $config['calendario_id'], 'window_start' => $config['janela_inicio'], 'window_end' => $config['janela_fim']));
            }
            systemLogEvent('configurou_fluxo_aprovacao', 'fluxo_aprovacao', $flowId, 'sucesso', array('centro_custo_id' => $centerId, 'versao' => $version, 'limite_alcada_diretor' => $directorLimit), null, null, $pdo);
            $pdo->commit();
            $_SESSION['admin_message'] = 'Trilha e SLAs salvos como versão ' . $version . '. As solicitações existentes mantêm a configuração que já receberam.';
            $_SESSION['admin_message_type'] = 'success';
            $_SESSION['admin_message_sweetalert'] = true;
            adminRedirect('?centro_custo_id=' . $centerId);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Falha ao salvar trilha administrativa: ' . $exception->getMessage());
            systemLogEvent('erro_sistema', 'fluxo_aprovacao', isset($flowId) ? $flowId : null, 'falha', array('operacao' => 'configurou_fluxo_aprovacao', 'centro_custo_id' => $centerId), $exception->getMessage(), null, $pdo);
            adminFail('Não foi possível salvar a trilha. Revise a configuração e tente novamente.');
        }
    }

    adminFail('Ação administrativa inválida.');
}

$centers = $pdo->query('SELECT cc.id, cc.omie_id, cc.codigo, cc.nome, cc.unidade_id, cc.ativo_omie, cc.sincronizado_em, u.nome AS unidade_nome, (SELECT COUNT(*) FROM usuario_perfis up WHERE up.centro_custo_id = cc.id) AS perfis_vinculados, (SELECT COUNT(*) FROM fluxo_centro_custo fcc WHERE fcc.centro_custo_id = cc.id) AS trilhas_vinculadas, (SELECT COUNT(*) FROM solicitacoes s WHERE s.centro_custo_id = cc.id) AS solicitacoes_vinculadas, (SELECT COUNT(*) FROM contratos c WHERE c.centro_custo_id = cc.id) AS contratos_vinculados FROM centros_custo cc LEFT JOIN unidades u ON u.id = cc.unidade_id ORDER BY cc.nome')->fetchAll();
$groups = $pdo->query('SELECT g.id, g.nome, g.descricao, (SELECT COUNT(*) FROM grupo_aprovadores ga INNER JOIN usuarios u ON u.id = ga.usuario_id WHERE ga.grupo_id = g.id AND ga.ativo = 1 AND u.ativo = 1 AND (ga.inicio_vigencia IS NULL OR ga.inicio_vigencia <= CURDATE()) AND (ga.fim_vigencia IS NULL OR ga.fim_vigencia >= CURDATE())) AS quantidade_membros FROM grupos_aprovacao g WHERE g.ativo = 1 ORDER BY g.nome')->fetchAll();
$approvers = $pdo->query('SELECT id, nome, matricula FROM usuarios WHERE ativo = 1 ORDER BY nome')->fetchAll();
$groupMembers = $pdo->query('SELECT ga.grupo_id, ga.usuario_id, ga.ordem_aprovacao, u.nome, u.matricula FROM grupo_aprovadores ga INNER JOIN usuarios u ON u.id = ga.usuario_id INNER JOIN grupos_aprovacao g ON g.id = ga.grupo_id WHERE ga.ativo = 1 AND g.ativo = 1 AND u.ativo = 1 AND (ga.inicio_vigencia IS NULL OR ga.inicio_vigencia <= CURDATE()) AND (ga.fim_vigencia IS NULL OR ga.fim_vigencia >= CURDATE()) ORDER BY g.nome, ga.ordem_aprovacao, ga.usuario_id')->fetchAll();
$calendars = $pdo->query('SELECT id, nome, fuso_horario, dias_semana_json, hora_inicio, hora_fim FROM calendarios ORDER BY nome')->fetchAll();
$selectedCenter = isset($_GET['centro_custo_id']) ? filter_var($_GET['centro_custo_id'], FILTER_VALIDATE_INT) : null;
$selectedCalendar = isset($_GET['calendario_id']) ? filter_var($_GET['calendario_id'], FILTER_VALIDATE_INT) : null;
$currentFlow = null;
$currentStages = array();
$currentStageUsers = array();
$selectedCenterRecord = null;
if ($selectedCenter) {
    $centerRecordQuery = $pdo->prepare('SELECT cc.id, cc.omie_id, cc.codigo, cc.nome, cc.unidade_id, cc.ativo_omie, cc.sincronizado_em, u.nome AS unidade_nome FROM centros_custo cc LEFT JOIN unidades u ON u.id = cc.unidade_id WHERE cc.id = :center_id');
    $centerRecordQuery->execute(array('center_id' => $selectedCenter));
    $selectedCenterRecord = $centerRecordQuery->fetch();
    $flowQuery = $pdo->prepare('SELECT f.id, f.nome, f.versao, f.limite_alcada_diretor FROM fluxo_centro_custo fcc INNER JOIN fluxos_aprovacao f ON f.id = fcc.fluxo_id WHERE fcc.centro_custo_id = :center_id ORDER BY f.versao DESC LIMIT 1');
    $flowQuery->execute(array('center_id' => $selectedCenter));
    $currentFlow = $flowQuery->fetch();
    if ($currentFlow) {
        $stageQuery = $pdo->prepare('SELECT r.codigo_etapa, r.modo, r.responsavel_tipo, r.grupo_id, r.usuario_id, s.duracao, s.unidade_tempo, s.regime_contagem, s.calendario_id, s.janela_inicio, s.janela_fim FROM regras_etapa r LEFT JOIN politicas_sla s ON s.fluxo_id = r.fluxo_id AND s.codigo_etapa = r.codigo_etapa WHERE r.fluxo_id = :flow_id AND r.ativa = 1 ORDER BY r.ordem');
        $stageQuery->execute(array('flow_id' => $currentFlow['id']));
        foreach ($stageQuery->fetchAll() as $stage) {
            $currentStages[$stage['codigo_etapa']] = $stage;
        }
        $stageUsersQuery = $pdo->prepare('SELECT r.codigo_etapa, ru.usuario_id FROM regras_etapa r INNER JOIN regras_etapa_usuarios ru ON ru.regra_etapa_id = r.id WHERE r.fluxo_id = :flow_id ORDER BY r.codigo_etapa, ru.ordem');
        $stageUsersQuery->execute(array('flow_id' => $currentFlow['id']));
        foreach ($stageUsersQuery->fetchAll() as $stageUser) {
            $currentStageUsers[$stageUser['codigo_etapa']][] = (int) $stageUser['usuario_id'];
        }
    }
}
$editCenterId = isset($_GET['editar_centro_custo']) ? filter_var($_GET['editar_centro_custo'], FILTER_VALIDATE_INT) : null;
$editCenter = null;
if ($editCenterId) {
    foreach ($centers as $centerRow) {
        if ((int) $centerRow['id'] === (int) $editCenterId) {
            $editCenter = $centerRow;
            break;
        }
    }
}
if (!$selectedCalendar && $calendars) {
    $selectedCalendar = (int) $calendars[0]['id'];
}
$holidays = array();
if ($selectedCalendar) {
    $holidayQuery = $pdo->prepare('SELECT id, data, descricao FROM feriados WHERE calendario_id = :calendar_id ORDER BY data');
    $holidayQuery->execute(array('calendar_id' => $selectedCalendar));
    $holidays = $holidayQuery->fetchAll();
}
$units = $pdo->query('SELECT id, nome FROM unidades WHERE ativa = 1 ORDER BY nome')->fetchAll();
$centerFormDraft = isset($_SESSION['center_form_draft']) && is_array($_SESSION['center_form_draft'])
    ? $_SESSION['center_form_draft']
    : array('codigo' => '', 'nome' => '', 'unidade_id' => null);
unset($_SESSION['center_form_draft']);
$csrfToken = htmlspecialchars(applicationCsrfToken(), ENT_QUOTES, 'UTF-8');
$showUserImportModal = isset($_GET['importar_usuarios']) && (string) $_GET['importar_usuarios'] === '1';
$selectedGroupModal = isset($_GET['grupo_modal']) ? filter_var($_GET['grupo_modal'], FILTER_VALIDATE_INT) : null;
$selectedCategoryModal = isset($_GET['editar_categoria']) ? filter_var($_GET['editar_categoria'], FILTER_VALIDATE_INT) : null;
$selectedProductModal = isset($_GET['editar_produto']) ? filter_var($_GET['editar_produto'], FILTER_VALIDATE_INT) : null;
$displayName = htmlspecialchars(isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Administrador', ENT_QUOTES, 'UTF-8');
$adminSections = array(
    'usuarios' => array('title' => 'Gerenciamento de usuários', 'description' => 'Consulte e administre os usuários, perfis e situação das contas do sistema.'),
    'categorias' => array('title' => 'Gerenciamento de categorias', 'description' => 'Consulte e administre as categorias de produtos cadastradas no sistema.'),
    'produtos' => array('title' => 'Gerenciamento de produtos', 'description' => 'Consulte e administre os produtos cadastrados e suas categorias.'),
    'grupos' => array('title' => 'Grupos e membros aprovadores', 'description' => 'Crie grupos reutilizáveis e gerencie seus membros e ordem hierárquica.'),
    'calendarios' => array('title' => 'Calendários de trabalho e feriados', 'description' => 'Configure dias de trabalho, horários e feriados usados no cálculo dos prazos.'),
    'trilhas' => array('title' => 'Trilha por Centro de Custo', 'description' => 'Defina a trilha de aprovação e o SLA aplicáveis a cada Centro de Custo.'),
    'logs' => array('title' => 'Logs do sistema', 'description' => 'Consulte os eventos e erros registrados pelo sistema.')
);
$activeSection = isset($_GET['secao']) && is_string($_GET['secao']) && isset($adminSections[$_GET['secao']])
    ? $_GET['secao']
    : (isset($_GET['calendario_id']) ? 'calendarios' : (isset($_GET['grupo_modal']) ? 'grupos' : 'trilhas'));
$userImportPreview = $activeSection === 'usuarios' && isset($_SESSION['user_import_preview']) && is_array($_SESSION['user_import_preview'])
    ? $_SESSION['user_import_preview']
    : null;
$selectedUserModal = isset($_GET['usuario_modal']) ? filter_var($_GET['usuario_modal'], FILTER_VALIDATE_INT) : null;
$users = array();
$userProfiles = array();
$userSelectedProfileIds = array();
$availableProfiles = array();
if ($activeSection === 'usuarios') {
    $users = $pdo->query('SELECT id, matricula, nome, email, ativo FROM usuarios ORDER BY nome, matricula')->fetchAll();
    $profileRows = $pdo->query('SELECT up.usuario_id, up.id AS vinculo_id, p.id AS perfil_id, p.codigo, p.nome, p.ativo FROM usuario_perfis up INNER JOIN perfis p ON p.id = up.perfil_id ORDER BY p.nome, up.id')->fetchAll();
    foreach ($profileRows as $profileRow) {
        $userId = (int) $profileRow['usuario_id'];
        if (!isset($userProfiles[$userId])) {
            $userProfiles[$userId] = array('ids' => array(), 'labels' => array(), 'names' => array(), 'active' => array(), 'codes' => array(), 'link_count' => 0);
        }
        $userProfiles[$userId]['link_count']++;
        $profileId = (int) $profileRow['perfil_id'];
        $userProfiles[$userId]['ids'][$profileId] = true;
        $label = $profileRow['nome'] . ((int) $profileRow['ativo'] === 1 ? '' : ' (inativo)');
        $userProfiles[$userId]['labels'][$label] = $label;
        $userProfiles[$userId]['names'][$profileId] = $label;
        $userProfiles[$userId]['active'][$profileId] = (int) $profileRow['ativo'] === 1;
        $userProfiles[$userId]['codes'][$profileId] = $profileRow['codigo'];
    }
    $availableProfiles = $pdo->query('SELECT id, nome FROM perfis WHERE ativo = 1 ORDER BY nome')->fetchAll();
    foreach ($users as $user) {
        $userId = (int) $user['id'];
        if (!isset($userProfiles[$userId])) {
            continue;
        }
        foreach ($userProfiles[$userId]['ids'] as $profileId => $assigned) {
            if (
                $userId === (int) $_SESSION['user_id']
                && $userProfiles[$userId]['codes'][$profileId] === 'administrador'
                && $userProfiles[$userId]['active'][$profileId]
            ) {
                $userSelectedProfileIds[$userId] = (int) $profileId;
                break;
            }
            if (!isset($userSelectedProfileIds[$userId])) {
                $userSelectedProfileIds[$userId] = (int) $profileId;
            }
        }
    }
}
$categories = array();
$products = array();
$productCategories = array();
if ($activeSection === 'categorias') {
    $categories = $pdo->query('SELECT c.id, c.omie_id, c.codigo, c.nome, c.ativo_omie, c.sincronizado_em, (SELECT COUNT(*) FROM produtos p WHERE p.categoria_id = c.id) AS produtos_vinculados, (SELECT COUNT(*) FROM solicitacoes s WHERE s.categoria_id = c.id) AS solicitacoes_vinculadas FROM categorias c ORDER BY c.nome')->fetchAll();
}
if ($activeSection === 'produtos') {
    $products = $pdo->query('SELECT p.id, p.omie_id, p.categoria_id, p.codigo, p.nome, p.unidade_medida, p.ativo_omie, p.sincronizado_em, c.nome AS categoria_nome, (SELECT COUNT(*) FROM solicitacao_itens si WHERE si.produto_id = p.id) AS solicitacoes_vinculadas FROM produtos p LEFT JOIN categorias c ON c.id = p.categoria_id ORDER BY p.nome')->fetchAll();
    $productCategories = $pdo->query('SELECT id, nome, ativo_omie FROM categorias ORDER BY nome')->fetchAll();
}
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Administração · Central de Compras</title>
    <link rel="shortcut icon" href="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" type="image/x-icon">
    <link rel="stylesheet" href="https://unpkg.com/multiple-select@2.4.0/dist/multiple-select.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
    <?php if ($activeSection === 'logs') { ?><link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css"><?php } ?>
    <link rel="stylesheet" href="assets/css/admin.css?v=<?php echo filemtime(__DIR__ . '/assets/css/admin.css'); ?>">
    <link rel="stylesheet" href="assets/css/account.css">
</head>

<body>
    <main class="admin-layout" id="adminLayout">
        <aside class="sidebar" id="adminSidebar">
            <div class="sidebar-brand">
                <img class="sidebar-brand-expanded" src="assets/images/logos.png" alt="Logos">
                <img class="sidebar-brand-collapsed" src="https://centraldeservicos.logos-ma.com.br/plugins/trademark/front/picture.send.php?path=8d/66be65b142d8d.png" alt="Logos">
                <p style="color: #fff;">Central de Compras</p>
            </div>
            <br>
            <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-controls="adminSidebar" aria-expanded="true" aria-label="Recolher menu lateral">
                <span class="sidebar-toggle-icon" aria-hidden="true"><i class="bi bi-chevron-double-left"></i></i></span>
            </button>
            <nav class="sidebar-nav" aria-label="Seções de administração">
                <details class="sidebar-group" open>
                    <summary class="sidebar-link sidebar-group-toggle" title="Administração">
                        <i class="bi bi-gear"></i><span class="sidebar-link-label">Administração</span><i class="bi bi-chevron-down sidebar-group-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="sidebar-group-links">
                        <a class="sidebar-link<?php echo $activeSection === 'usuarios' ? ' active' : ''; ?>" href="admin.php?secao=usuarios" <?php echo $activeSection === 'usuarios' ? 'aria-current="page"' : ''; ?> title="Gerenciamento de usuários">
                            <i class="bi bi-person-gear"></i><span class="sidebar-link-label">Gerenciamento de usuários</span>
                        </a>
                        <a class="sidebar-link<?php echo $activeSection === 'produtos' ? ' active' : ''; ?>" href="admin.php?secao=produtos" <?php echo $activeSection === 'produtos' ? 'aria-current="page"' : ''; ?> title="Gerenciamento de produtos">
                            <i class="bi bi-box-seam"></i><span class="sidebar-link-label">Gerenciamento de produtos</span>
                        </a>
                        <a class="sidebar-link<?php echo $activeSection === 'categorias' ? ' active' : ''; ?>" href="admin.php?secao=categorias" <?php echo $activeSection === 'categorias' ? 'aria-current="page"' : ''; ?> title="Gerenciamento de categorias">
                            <i class="bi bi-tags"></i><span class="sidebar-link-label">Gerenciamento de categorias</span>
                        </a>
                        <a class="sidebar-link<?php echo $activeSection === 'grupos' ? ' active' : ''; ?>" href="admin.php?secao=grupos" <?php echo $activeSection === 'grupos' ? 'aria-current="page"' : ''; ?> title="Grupos e membros aprovadores">
                            <i class="bi bi-people"></i><span class="sidebar-link-label">Grupos e membros aprovadores</span>
                        </a>
                        <a class="sidebar-link<?php echo $activeSection === 'calendarios' ? ' active' : ''; ?>" href="admin.php?secao=calendarios" <?php echo $activeSection === 'calendarios' ? 'aria-current="page"' : ''; ?> title="Calendários de trabalho e feriados">
                            <i class="bi bi-calendar3"></i><span class="sidebar-link-label">Calendários de trabalho e feriados</span>
                        </a>
                        <a class="sidebar-link<?php echo $activeSection === 'trilhas' ? ' active' : ''; ?>" href="admin.php?secao=trilhas" <?php echo $activeSection === 'trilhas' ? 'aria-current="page"' : ''; ?> title="Trilha por Centro de Custo">
                            <i class="bi bi-currency-dollar"></i><span class="sidebar-link-label">Trilha por Centro de Custo</span>
                        </a>
                        <a class="sidebar-link<?php echo $activeSection === 'logs' ? ' active' : ''; ?>" href="admin.php?secao=logs" <?php echo $activeSection === 'logs' ? 'aria-current="page"' : ''; ?> title="Logs do sistema">
                            <i class="bi bi-journal-text"></i><span class="sidebar-link-label">Logs do sistema</span>
                        </a>
                    </div>
                </details>
                <details class="sidebar-group">
                    <summary class="sidebar-link sidebar-group-toggle" title="Sistema">
                        <i class="bi bi-grid"></i><span class="sidebar-link-label">Sistema</span><i class="bi bi-chevron-down sidebar-group-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="sidebar-group-links">
                        <a class="sidebar-link" href="painel.php?perfil=administrador&amp;secao=resumo" title="Dashboard">
                            <i class="bi bi-speedometer2"></i><span class="sidebar-link-label">Dashboard</span>
                        </a>
                        <a class="sidebar-link" href="painel.php?perfil=administrador&amp;secao=solicitacoes" title="Solicitações">
                            <i class="bi bi-clipboard-check"></i><span class="sidebar-link-label">Solicitações</span>
                        </a>
                        <a class="sidebar-link" href="painel.php?perfil=administrador&amp;secao=minhas_solicitacoes" title="Minhas solicitações">
                            <i class="bi bi-person-lines-fill"></i><span class="sidebar-link-label">Minhas solicitações</span>
                        </a>
                        <a class="sidebar-link" href="painel.php?perfil=administrador&amp;secao=contratos" title="Contratos">
                            <i class="bi bi-file-earmark-text"></i><span class="sidebar-link-label">Contratos</span>
                        </a>
                        <a class="sidebar-link" href="painel.php?perfil=administrador&amp;secao=aprovacoes" title="Aprovações pendentes">
                            <i class="bi bi-check2-square"></i><span class="sidebar-link-label">Aprovações pendentes</span>
                        </a>
                        <a class="sidebar-link" href="painel.php?perfil=administrador&amp;secao=historico" title="Histórico de solicitações">
                            <i class="bi bi-clock-history"></i><span class="sidebar-link-label">Histórico de solicitações</span>
                        </a>
                    </div>
                </details>
                <details class="sidebar-group">
                    <summary class="sidebar-link sidebar-group-toggle" title="Minha conta">
                        <i class="bi bi-person-circle"></i><span class="sidebar-link-label">Minha conta</span><i class="bi bi-chevron-down sidebar-group-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="sidebar-group-links">
                        <a class="sidebar-link" href="meu_perfil.php" title="Meu perfil"><i class="bi bi-person"></i><span class="sidebar-link-label">Meu perfil</span></a>
                        <a class="sidebar-link" href="ajuda.php" title="Ajuda"><i class="bi bi-question-circle"></i><span class="sidebar-link-label">Ajuda</span></a>
                    </div>
                </details>
            </nav>
        </aside>
        <div class="admin-content">
            <header class="top">
                <div class="brand">Painel do Administrador</div>
                <?php renderApplicationUserMenu(); ?>
            </header>
            <div class="wrap">
                <div class="head">
                    <div>
                        <h1><?php echo h($adminSections[$activeSection]['title']); ?></h1>
                        <p class="sub"><?php echo h($adminSections[$activeSection]['description']); ?></p>
                    </div>
                </div>
                <?php if ($message !== '') { ?><div class="message <?php echo h($messageType); ?>" id="adminMessage" role="status"><?php echo h($message); ?></div><?php } ?>

                <?php if ($activeSection === 'usuarios') { ?>
                    <section class="panel">
                        <h2>Usuários cadastrados</h2>
                        <p class="hint">Edite os dados e perfis, altere o status da conta ou remova usuários sem registros vinculados.</p>
                        <div class="user-list-actions">
                            <button class="btn" type="button" data-open-user-import="user-import-modal"><i class="bi bi-file-earmark-arrow-up"></i> Importar usuários</button>
                            <button class="btn primary" type="button" data-open-user="create-user-modal"><i class="bi bi-person-plus"></i> Cadastrar usuário</button>
                        </div>
                        <?php if ($users) { ?>
                            <div class="table-wrap">
                                <table class="users-table">
                                    <thead>
                                        <tr>
                                            <th>Matrícula</th>
                                            <th>Nome</th>
                                            <th>E-mail</th>
                                            <th>Status</th>
                                            <th>Perfil</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $user) {
                                            $userId = (int) $user['id'];
                                            $userProfileIds = isset($userProfiles[$userId]) ? $userProfiles[$userId]['ids'] : array();
                                            $profileLabels = isset($userProfiles[$userId]) ? array_values($userProfiles[$userId]['labels']) : array();
                                            $hasMultipleProfiles = count($userProfileIds) > 1;
                                            $hasMultipleLinks = isset($userProfiles[$userId]) && $userProfiles[$userId]['link_count'] > 1;
                                            $isCurrentUser = $userId === (int) $_SESSION['user_id']; ?>
                                            <tr>
                                                <td><?php echo h($user['matricula']); ?></td>
                                                <td><?php echo h($user['nome']); ?></td>
                                                <td><?php echo h($user['email']); ?></td>
                                                <td><span class="user-status <?php echo (int) $user['ativo'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $user['ativo'] === 1 ? 'Ativo' : 'Inativo'; ?></span></td>
                                                <td><?php
                                                    if (!$profileLabels) {
                                                        echo '<span class="muted">Sem perfil</span>';
                                                    } elseif ($hasMultipleProfiles) {
                                                        echo '<span class="user-profile-warning">Dados legados: mais de um perfil. Edite e salve para manter apenas um.</span>';
                                                    } elseif ($hasMultipleLinks) {
                                                        echo '<span class="user-profile-warning">' . h($profileLabels[0]) . ' · vínculos/escopos legados. Edite e salve para manter apenas um.</span>';
                                                    } else {
                                                        echo h($profileLabels[0]);
                                                    }
                                                    ?></td>
                                                <td>
                                                    <div class="user-actions">
                                                        <button class="btn" type="button" data-open-user="user-modal-<?php echo $userId; ?>" aria-label="Editar <?php echo h($user['nome']); ?>" title="Editar"><i class="bi bi-pencil"></i></button>
                                                        <form method="post" action="admin.php" class="inline">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                            <input type="hidden" name="action" value="set_user_status">
                                                            <input type="hidden" name="usuario_id" value="<?php echo $userId; ?>">
                                                            <input type="hidden" name="ativo" value="<?php echo (int) $user['ativo'] === 1 ? '0' : '1'; ?>">
                                                            <button class="btn toggle-status" type="submit" <?php echo $isCurrentUser ? 'disabled title="Não é possível inativar seu próprio usuário."' : 'aria-label="' . ((int) $user['ativo'] === 1 ? 'Inativar ' : 'Ativar ') . h($user['nome']) . '" title="' . ((int) $user['ativo'] === 1 ? 'Inativar' : 'Ativar') . '"'; ?>>
                                                                <i class="bi <?php echo (int) $user['ativo'] === 1 ? 'bi-person-x' : 'bi-person-check'; ?>"></i>
                                                            </button>
                                                        </form>
                                                        <form method="post" action="admin.php" class="inline delete-user-form" data-user-name="<?php echo h($user['nome']); ?>">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="usuario_id" value="<?php echo $userId; ?>">
                                                            <button class="btn remove" type="submit" <?php echo $isCurrentUser ? 'disabled title="Não é possível remover seu próprio usuário."' : 'title="Remover usuário"'; ?>><i class="bi bi-trash"></i></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } else { ?>
                            <p class="hint">Não há usuários cadastrados.</p>
                        <?php } ?>
                    </section>

                    <div class="modal-backdrop" id="create-user-modal" role="dialog" aria-modal="true" aria-labelledby="create-user-title" hidden>
                        <div class="modal-card user-modal-card">
                            <div class="modal-heading">
                                <div>
                                    <h2 id="create-user-title">Cadastrar usuário</h2>
                                    <p class="hint">Realize o cadastro de novos usuários no sistema.</p>
                                </div>
                                <button class="modal-close" type="button" aria-label="Fechar" data-close-user>&times;</button>
                            </div>
                            <form method="post" action="admin.php?secao=usuarios" class="user-edit-form">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="create_user">
                                <div class="grid user-fields">
                                    <div class="field"><label for="new-user-matricula">Matrícula</label><input id="new-user-matricula" name="matricula" maxlength="40" required></div>
                                    <div class="field"><label for="new-user-name">Nome</label><input id="new-user-name" name="nome" maxlength="180" required></div>
                                    <div class="field"><label for="new-user-email">E-mail</label><input id="new-user-email" name="email" type="email" maxlength="254" required></div>
                                </div>
                                <fieldset class="user-profile-list">
                                    <legend>Perfil</legend>
                                    <?php if ($availableProfiles) { ?>
                                        <div class="user-profile-options">
                                            <?php foreach ($availableProfiles as $profile) { ?>
                                                <label><input type="radio" name="perfil_id" value="<?php echo (int) $profile['id']; ?>" required> <?php echo h($profile['nome']); ?></label>
                                            <?php } ?>
                                        </div>
                                    <?php } else { ?>
                                        <p class="hint">Não há perfis ativos disponíveis para atribuição.</p>
                                    <?php } ?>
                                </fieldset>
                                <p class="hint">Uma senha temporária será enviada para o e-mail cadastrado. <br> O usuário deve trocá-la no primeiro login. <br> Ele também poderá usar “Esqueci minha senha” na tela de login.</p>
                                <button class="btn primary" type="submit" <?php echo !$availableProfiles ? 'disabled' : ''; ?>>Cadastrar e enviar acesso</button>
                            </form>
                        </div>
                    </div>

                    <div class="modal-backdrop" id="user-import-modal" role="dialog" aria-modal="true" aria-labelledby="user-import-title" hidden>
                        <div class="modal-card user-import-modal-card">
                            <div class="modal-heading">
                                <div>
                                    <h2 id="user-import-title">Importar usuários</h2>
                                    <p class="hint">Baixe o modelo, preencha os dados e envie um arquivo CSV ou XLSX para pré-validação.</p>
                                </div>
                                <button class="modal-close" type="button" aria-label="Fechar" data-close-user-import>&times;</button>
                            </div>
                            <p class="hint user-import-intro">Baixe um dos modelos para preencher a planilha:</p>
                            <div class="user-import-template-actions">
                                <a class="btn" href="admin.php?modelo_importacao=xlsx"><i class="bi bi-download"></i> Baixar Modelo (.xlsx)</a>
                                <a class="btn" href="admin.php?modelo_importacao=csv"><i class="bi bi-download"></i> Baixar Modelo (.csv)</a>
                            </div>
                            <p class="hint user-import-instructions">Use os cabeçalhos do modelo sem alterar sua ordem. Centro de Custo e Perfil podem ser informados pelo nome ou código; os cadastros precisam estar ativos. Informe CPF com 11 dígitos e preserve zeros à esquerda.</p>
                            <form method="post" action="admin.php?secao=usuarios" class="user-edit-form user-import-upload" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="preview_user_import">
                                <div class="field">
                                    <label for="user-import-file">Arquivo (.csv ou .xlsx)</label>
                                    <input id="user-import-file" name="planilha" type="file" accept=".csv,.xlsx" required>
                                </div>
                                <button class="btn primary" type="submit"><i class="bi bi-search"></i> Validar planilha</button>
                            </form>

                            <?php if ($userImportPreview && isset($userImportPreview['rows']) && is_array($userImportPreview['rows'])) {
                                $validImportCount = 0;
                                foreach ($userImportPreview['rows'] as $importRow) {
                                    if (!$importRow['errors']) {
                                        $validImportCount++;
                                    }
                                }
                                $invalidImportCount = count($userImportPreview['rows']) - $validImportCount; ?>
                                <h3>Pré-validação</h3>
                                <p class="hint"><?php echo $validImportCount; ?> válido(s) e <?php echo $invalidImportCount; ?> com erro. Até 500 usuários por importação.</p>
                                <div class="table-wrap user-import-table-wrap">
                                    <table class="users-table user-import-table">
                                        <thead>
                                            <tr><th>Linha</th><th>Matrícula</th><th>Nome Completo</th><th>E-mail</th><th>CPF</th><th>Centro de Custo</th><th>Perfil</th><th>Validação</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($userImportPreview['rows'] as $importRow) { ?>
                                                <tr class="<?php echo $importRow['errors'] ? 'import-row-invalid' : 'import-row-valid'; ?>">
                                                    <td><?php echo (int) $importRow['line']; ?></td>
                                                    <td><?php echo h($importRow['matricula']); ?></td>
                                                    <td><?php echo h($importRow['nome']); ?></td>
                                                    <td><?php echo h($importRow['email']); ?></td>
                                                    <td><?php echo h($importRow['cpf']); ?></td>
                                                    <td><?php echo h($importRow['centro_custo']); ?></td>
                                                    <td><?php echo h($importRow['perfil']); ?></td>
                                                    <td><?php echo $importRow['errors'] ? h(implode(' ', $importRow['errors'])) : 'Válido'; ?></td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                                <form method="post" action="admin.php?secao=usuarios&amp;importar_usuarios=1" class="user-import-confirm">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="confirm_user_import">
                                    <input type="hidden" name="preview_token" value="<?php echo h($userImportPreview['token']); ?>">
                                    <button class="btn primary" type="submit" <?php echo !$validImportCount ? 'disabled' : ''; ?>><i class="bi bi-check2-circle"></i> Confirmar Importação (<?php echo $validImportCount; ?>)</button>
                                </form>
                            <?php } ?>
                        </div>
                    </div>

                    <?php foreach ($users as $user) {
                        $userId = (int) $user['id'];
                        $userProfileIds = isset($userProfiles[$userId]) ? $userProfiles[$userId]['ids'] : array(); ?>
                        <div class="modal-backdrop" id="user-modal-<?php echo $userId; ?>" role="dialog" aria-modal="true" aria-labelledby="user-title-<?php echo $userId; ?>" hidden>
                            <div class="modal-card user-modal-card">
                                <div class="modal-heading">
                                    <div>
                                        <h2 id="user-title-<?php echo $userId; ?>">Editar usuário</h2>
                                        <p class="hint">Cada usuário pode ter um único perfil e escopo. Ao salvar, os demais vínculos e escopos serão removidos; um novo vínculo será global.</p>
                                    </div>
                                    <button class="modal-close" type="button" aria-label="Fechar" data-close-user>&times;</button>
                                </div>
                                <form method="post" action="admin.php" class="user-edit-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="update_user">
                                    <input type="hidden" name="usuario_id" value="<?php echo $userId; ?>">
                                    <div class="grid user-fields">
                                        <div class="field"><label for="user-matricula-<?php echo $userId; ?>">Matrícula</label><input id="user-matricula-<?php echo $userId; ?>" name="matricula" maxlength="40" required value="<?php echo h($user['matricula']); ?>"readonly></div>
                                        
                                        <div class="field"><label for="user-name-<?php echo $userId; ?>">Nome</label><input id="user-name-<?php echo $userId; ?>" name="nome" maxlength="180" required value="<?php echo h($user['nome']); ?>"></div>
                                        <div class="field"><label for="user-email-<?php echo $userId; ?>">E-mail</label><input id="user-email-<?php echo $userId; ?>" name="email" type="email" maxlength="254" required value="<?php echo h($user['email']); ?>"></div>
                                    </div>
                                    <fieldset class="user-profile-list">
                                        <legend>Perfis</legend>
                                        <div class="user-profile-options">
                                            <?php $profileOptionsRendered = false; ?>
                                            <?php foreach ($availableProfiles as $profile) {
                                                $profileId = (int) $profile['id'];
                                                $profileOptionsRendered = true; ?>
                                                <label><input type="radio" name="perfil_id" value="<?php echo $profileId; ?>" required <?php echo isset($userSelectedProfileIds[$userId]) && $userSelectedProfileIds[$userId] === $profileId ? 'checked' : ''; ?>> <?php echo h($profile['nome']); ?></label>
                                            <?php } ?>
                                            <?php foreach (array_keys($userProfileIds) as $assignedProfileId) {
                                                $isActiveProfile = false;
                                                foreach ($availableProfiles as $profile) {
                                                    if ((int) $profile['id'] === (int) $assignedProfileId) {
                                                        $isActiveProfile = true;
                                                        break;
                                                    }
                                                }
                                                if (!$isActiveProfile) {
                                                    $profileOptionsRendered = true;
                                                    $profileName = isset($userProfiles[$userId]['names'][(int) $assignedProfileId]) ? $userProfiles[$userId]['names'][(int) $assignedProfileId] : 'Perfil inativo'; ?>
                                                    <label class="user-profile-disabled"><input type="radio" name="perfil_id" value="<?php echo (int) $assignedProfileId; ?>" required <?php echo isset($userSelectedProfileIds[$userId]) && $userSelectedProfileIds[$userId] === (int) $assignedProfileId ? 'checked' : ''; ?>> <?php echo h($profileName); ?></label>
                                                <?php }
                                            }
                                            if (!$profileOptionsRendered) { ?>
                                                <span class="muted">Não há perfis ativos para atribuir.</span>
                                            <?php } ?>
                                        </div>
                                    </fieldset><br>
                                    <button class="btn primary" type="submit">Salvar alterações</button>
                                </form>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>

                <?php if ($activeSection === 'logs') { ?>
                    <section class="panel">
                        <h2>Registros do sistema</h2>
                        <p class="hint">Pesquise por usuário, ação, entidade, status, IP, data, detalhes ou erro e escolha quantas linhas visualizar por página.</p>
                        <div class="table-wrap logs-table-wrap">
                            <table id="systemLogsTable" class="display">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Data e hora</th>
                                        <th>Usuário</th>
                                        <th>Ação</th>
                                        <th>Entidade</th>
                                        <th>ID do item</th>
                                        <th>Status</th>
                                        <th>IP</th>
                                        <th>Detalhes</th>
                                        <th>Erro</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </section>
                <?php } ?>

                <?php if ($activeSection === 'categorias') { ?>
                    <section class="panel">
                        <h2>Categorias cadastradas</h2>
                        <p class="hint">Cadastre e edite categorias, altere seu status ou remova apenas as que não possuem produtos nem solicitações vinculados.</p>
                        <div class="user-list-actions">
                            <button class="btn primary" type="button" data-open-catalog="create-category-modal"><i class="bi bi-plus-lg"></i> Cadastrar categoria</button>
                        </div>
                        <?php if ($categories) { ?>
                            <div class="table-wrap">
                                <table class="users-table">
                                    <thead><tr><th>ID OMIE</th><th>Código</th><th>Nome</th><th>Sincronização</th><th>Status</th><th>Ações</th></tr></thead>
                                    <tbody><?php foreach ($categories as $category) {
                                        $categoryId = (int) $category['id'];
                                        $categoryHasReferences = (int) $category['produtos_vinculados'] + (int) $category['solicitacoes_vinculadas'] > 0; ?>
                                        <tr>
                                            <td><?php echo h($category['omie_id']); ?></td>
                                            <td><?php echo $category['codigo'] !== null && $category['codigo'] !== '' ? h($category['codigo']) : '—'; ?></td>
                                            <td><?php echo h($category['nome']); ?></td>
                                            <td><?php echo $category['sincronizado_em'] ? h($category['sincronizado_em']) : '—'; ?></td>
                                            <td><span class="user-status <?php echo (int) $category['ativo_omie'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $category['ativo_omie'] === 1 ? 'Ativa' : 'Inativa'; ?></span></td>
                                            <td>
                                                <div class="user-actions">
                                                    <button class="btn" type="button" data-open-catalog="category-modal-<?php echo $categoryId; ?>" aria-label="Editar <?php echo h($category['nome']); ?>" title="Editar"><i class="bi bi-pencil"></i></button>
                                                    <form method="post" action="admin.php" class="inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="set_category_status"><input type="hidden" name="categoria_id" value="<?php echo $categoryId; ?>"><input type="hidden" name="ativo" value="<?php echo (int) $category['ativo_omie'] === 1 ? '0' : '1'; ?>">
                                                        <button class="btn toggle-status" type="submit" aria-label="<?php echo (int) $category['ativo_omie'] === 1 ? 'Inativar' : 'Ativar'; ?> <?php echo h($category['nome']); ?>" title="<?php echo (int) $category['ativo_omie'] === 1 ? 'Inativar' : 'Ativar'; ?>"><i class="bi <?php echo (int) $category['ativo_omie'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off'; ?>"></i></button>
                                                    </form>
                                                    <form method="post" action="admin.php" class="inline delete-catalog-form" data-catalog-name="<?php echo h($category['nome']); ?>" data-catalog-type="categoria">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="categoria_id" value="<?php echo $categoryId; ?>">
                                                        <button class="btn remove" type="submit" <?php echo $categoryHasReferences ? 'disabled title="Inative categorias com produtos ou solicitações vinculados"' : 'title="Remover categoria"'; ?>><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php } ?></tbody>
                                </table>
                            </div>
                        <?php } else { ?><p class="hint">Não há categorias cadastradas.</p><?php } ?>
                    </section>
                    <div class="modal-backdrop" id="create-category-modal" role="dialog" aria-modal="true" aria-labelledby="create-category-title" hidden>
                        <div class="modal-card user-modal-card">
                            <div class="modal-heading"><div><h2 id="create-category-title">Cadastrar categoria</h2><p class="hint">Informe os dados da categoria e seu identificador no OMIE.</p></div><button class="modal-close" type="button" aria-label="Fechar" data-close-catalog>&times;</button></div>
                            <form method="post" action="admin.php?secao=categorias" class="user-edit-form">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="create_category">
                                <div class="grid user-fields">
                                    <div class="field"><label for="new-category-omie">ID OMIE</label><input id="new-category-omie" name="omie_id" maxlength="80" required></div>
                                    <div class="field"><label for="new-category-code">Código</label><input id="new-category-code" name="codigo" maxlength="50"></div>
                                    <div class="field"><label for="new-category-name">Nome</label><input id="new-category-name" name="nome" maxlength="180" required></div>
                                </div>
                                <button class="btn primary" type="submit">Cadastrar categoria</button>
                            </form>
                        </div>
                    </div>
                    <?php foreach ($categories as $category) { $categoryId = (int) $category['id']; ?>
                        <div class="modal-backdrop" id="category-modal-<?php echo $categoryId; ?>" role="dialog" aria-modal="true" aria-labelledby="category-title-<?php echo $categoryId; ?>" hidden>
                            <div class="modal-card user-modal-card">
                                <div class="modal-heading"><div><h2 id="category-title-<?php echo $categoryId; ?>">Editar categoria</h2><p class="hint">A edição preserva as solicitações já vinculadas à categoria.</p></div><button class="modal-close" type="button" aria-label="Fechar" data-close-catalog>&times;</button></div>
                                <form method="post" action="admin.php?secao=categorias" class="user-edit-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="update_category"><input type="hidden" name="categoria_id" value="<?php echo $categoryId; ?>">
                                    <div class="grid user-fields">
                                        <div class="field"><label for="category-omie-<?php echo $categoryId; ?>">ID OMIE</label><input id="category-omie-<?php echo $categoryId; ?>" name="omie_id" maxlength="80" required value="<?php echo h($category['omie_id']); ?>"></div>
                                        <div class="field"><label for="category-code-<?php echo $categoryId; ?>">Código</label><input id="category-code-<?php echo $categoryId; ?>" name="codigo" maxlength="50" value="<?php echo h($category['codigo']); ?>"></div>
                                        <div class="field"><label for="category-name-<?php echo $categoryId; ?>">Nome</label><input id="category-name-<?php echo $categoryId; ?>" name="nome" maxlength="180" required value="<?php echo h($category['nome']); ?>"></div>
                                    </div>
                                    <button class="btn primary" type="submit">Salvar alterações</button>
                                </form>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>

                <?php if ($activeSection === 'produtos') { ?>
                    <section class="panel">
                        <h2>Produtos cadastrados</h2>
                        <p class="hint">Cadastre e edite produtos, altere seu status ou remova apenas os que não possuem itens de solicitação vinculados.</p>
                        <div class="user-list-actions">
                            <button class="btn primary" type="button" data-open-catalog="create-product-modal"><i class="bi bi-plus-lg"></i> Cadastrar produto</button>
                        </div>
                        <?php if ($products) { ?>
                            <div class="table-wrap">
                                <table class="users-table">
                                    <thead><tr><th>ID OMIE</th><th>Código</th><th>Nome</th><th>Categoria</th><th>Unidade</th><th>Status</th><th>Ações</th></tr></thead>
                                    <tbody><?php foreach ($products as $product) {
                                        $productId = (int) $product['id'];
                                        $productHasReferences = (int) $product['solicitacoes_vinculadas'] > 0; ?>
                                        <tr>
                                            <td><?php echo h($product['omie_id']); ?></td>
                                            <td><?php echo $product['codigo'] !== null && $product['codigo'] !== '' ? h($product['codigo']) : '—'; ?></td>
                                            <td><?php echo h($product['nome']); ?></td>
                                            <td><?php echo $product['categoria_nome'] !== null ? h($product['categoria_nome']) : '—'; ?></td>
                                            <td><?php echo $product['unidade_medida'] !== null && $product['unidade_medida'] !== '' ? h($product['unidade_medida']) : '—'; ?></td>
                                            <td><span class="user-status <?php echo (int) $product['ativo_omie'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $product['ativo_omie'] === 1 ? 'Ativo' : 'Inativo'; ?></span></td>
                                            <td>
                                                <div class="user-actions">
                                                    <button class="btn" type="button" data-open-catalog="product-modal-<?php echo $productId; ?>" aria-label="Editar <?php echo h($product['nome']); ?>" title="Editar"><i class="bi bi-pencil"></i></button>
                                                    <form method="post" action="admin.php" class="inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="set_product_status"><input type="hidden" name="produto_id" value="<?php echo $productId; ?>"><input type="hidden" name="ativo" value="<?php echo (int) $product['ativo_omie'] === 1 ? '0' : '1'; ?>">
                                                        <button class="btn toggle-status" type="submit" aria-label="<?php echo (int) $product['ativo_omie'] === 1 ? 'Inativar' : 'Ativar'; ?> <?php echo h($product['nome']); ?>" title="<?php echo (int) $product['ativo_omie'] === 1 ? 'Inativar' : 'Ativar'; ?>"><i class="bi <?php echo (int) $product['ativo_omie'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off'; ?>"></i></button>
                                                    </form>
                                                    <form method="post" action="admin.php" class="inline delete-catalog-form" data-catalog-name="<?php echo h($product['nome']); ?>" data-catalog-type="produto">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="produto_id" value="<?php echo $productId; ?>">
                                                        <button class="btn remove" type="submit" <?php echo $productHasReferences ? 'disabled title="Inative produtos com solicitações vinculadas"' : 'title="Remover produto"'; ?>><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php } ?></tbody>
                                </table>
                            </div>
                        <?php } else { ?><p class="hint">Não há produtos cadastrados.</p><?php } ?>
                    </section>
                    <div class="modal-backdrop" id="create-product-modal" role="dialog" aria-modal="true" aria-labelledby="create-product-title" hidden>
                        <div class="modal-card user-modal-card">
                            <div class="modal-heading"><div><h2 id="create-product-title">Cadastrar produto</h2><p class="hint">Informe os dados do produto e seu identificador no OMIE.</p></div><button class="modal-close" type="button" aria-label="Fechar" data-close-catalog>&times;</button></div>
                            <form method="post" action="admin.php?secao=produtos" class="user-edit-form">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="create_product">
                                <div class="grid user-fields">
                                    <div class="field"><label for="new-product-omie">ID OMIE</label><input id="new-product-omie" name="omie_id" maxlength="80" required></div>
                                    <div class="field"><label for="new-product-code">Código</label><input id="new-product-code" name="codigo" maxlength="80"></div>
                                    <div class="field"><label for="new-product-name">Nome</label><input id="new-product-name" name="nome" maxlength="240" required></div>
                                    <div class="field"><label for="new-product-category">Categoria</label><select id="new-product-category" name="categoria_id"><option value="">Sem categoria</option><?php foreach ($productCategories as $productCategory) { ?><option value="<?php echo (int) $productCategory['id']; ?>"><?php echo h($productCategory['nome']); ?><?php echo (int) $productCategory['ativo_omie'] === 1 ? '' : ' (inativa)'; ?></option><?php } ?></select></div>
                                    <div class="field"><label for="new-product-unit">Unidade de medida</label><input id="new-product-unit" name="unidade_medida" maxlength="30"></div>
                                </div>
                                <button class="btn primary" type="submit">Cadastrar produto</button>
                            </form>
                        </div>
                    </div>
                    <?php foreach ($products as $product) { $productId = (int) $product['id']; ?>
                        <div class="modal-backdrop" id="product-modal-<?php echo $productId; ?>" role="dialog" aria-modal="true" aria-labelledby="product-title-<?php echo $productId; ?>" hidden>
                            <div class="modal-card user-modal-card">
                                <div class="modal-heading"><div><h2 id="product-title-<?php echo $productId; ?>">Editar produto</h2><p class="hint">A edição preserva os itens de solicitações já vinculados ao produto.</p></div><button class="modal-close" type="button" aria-label="Fechar" data-close-catalog>&times;</button></div>
                                <form method="post" action="admin.php?secao=produtos" class="user-edit-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="update_product"><input type="hidden" name="produto_id" value="<?php echo $productId; ?>">
                                    <div class="grid user-fields">
                                        <div class="field"><label for="product-omie-<?php echo $productId; ?>">ID OMIE</label><input id="product-omie-<?php echo $productId; ?>" name="omie_id" maxlength="80" required value="<?php echo h($product['omie_id']); ?>"></div>
                                        <div class="field"><label for="product-code-<?php echo $productId; ?>">Código</label><input id="product-code-<?php echo $productId; ?>" name="codigo" maxlength="80" value="<?php echo h($product['codigo']); ?>"></div>
                                        <div class="field"><label for="product-name-<?php echo $productId; ?>">Nome</label><input id="product-name-<?php echo $productId; ?>" name="nome" maxlength="240" required value="<?php echo h($product['nome']); ?>"></div>
                                        <div class="field"><label for="product-category-<?php echo $productId; ?>">Categoria</label><select id="product-category-<?php echo $productId; ?>" name="categoria_id"><option value="">Sem categoria</option><?php foreach ($productCategories as $productCategory) { $productCategoryId = (int) $productCategory['id']; ?><option value="<?php echo $productCategoryId; ?>" <?php echo (int) $product['categoria_id'] === $productCategoryId ? 'selected' : ''; ?>><?php echo h($productCategory['nome']); ?><?php echo (int) $productCategory['ativo_omie'] === 1 ? '' : ' (inativa)'; ?></option><?php } ?></select></div>
                                        <div class="field"><label for="product-unit-<?php echo $productId; ?>">Unidade de medida</label><input id="product-unit-<?php echo $productId; ?>" name="unidade_medida" maxlength="30" value="<?php echo h($product['unidade_medida']); ?>"></div>
                                    </div>
                                    <button class="btn primary" type="submit">Salvar alterações</button>
                                </form>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>

                <?php if ($activeSection === 'grupos') { ?>
                    <section class="panel">
                        <h2>Grupos e membros aprovadores</h2>
                        <p class="hint">Crie grupos reutilizáveis e gerencie nome, membros e ordem hierárquica pela ação Editar. Reordene os membros pelas setas ou arrastando-os na tabela. Cada etapa pode atribuir aprovação a um grupo ou a uma pessoa específica.</p>
                        <form method="post" action="admin.php" class="grid" style="align-items:end"><input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="save_group">
                            <div class="field"><label>Nome do grupo</label><input name="nome" maxlength="160" required placeholder="Ex.: Comitê de compras"></div>
                            <div class="field"><label>Descrição</label><input name="descricao" maxlength="255" placeholder="Opcional"></div>
                            <div><button class="btn primary" type="submit">Criar grupo</button></div>
                        </form>
                        <?php if ($groups) { ?><div class="table-wrap" style="margin-top:18px">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Grupo</th>
                                            <th>Quantidade de membros</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($groups as $group) { ?>
                                            <tr>
                                                <td><?php echo h($group['nome']); ?></td>
                                                <td><?php echo (int) $group['quantidade_membros']; ?></td>
                                                <td class="group-actions">
                                                    <button class="btn" type="button" data-open-group="group-modal-<?php echo (int) $group['id']; ?>" title="Editar grupo <?php echo h($group['nome']); ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="post" action="admin.php" class="inline dissolve-form remove" title="Remover grupo <?php echo h($group['nome']); ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="action" value="delete_group">
                                                        <input type="hidden" name="grupo_id" value="<?php echo (int) $group['id']; ?>">
                                                        <button class="btn" type="submit" data-group-name="<?php echo h($group['nome']); ?>"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } else { ?><p class="hint">Ainda não há grupos criados. Também é possível selecionar um aprovador individual por etapa.</p><?php } ?>
                    </section>

                    <?php foreach ($groups as $group) {
                        $membersInGroup = array_values(array_filter($groupMembers, function ($member) use ($group) {
                            return (int) $member['grupo_id'] === (int) $group['id'];
                        })); ?>
                        <div class="modal-backdrop" id="group-modal-<?php echo (int) $group['id']; ?>" role="dialog" aria-modal="true" aria-labelledby="group-title-<?php echo (int) $group['id']; ?>" hidden>
                            <div class="modal-card">
                                <div class="modal-heading">
                                    <div>
                                        <h2 id="group-title-<?php echo (int) $group['id']; ?>">Editar grupo</h2>
                                        <p class="hint">Atualize os dados e organize a ordem de decisão pelas setas, arrastando os membros ou informando a posição.</p>
                                    </div><button class="modal-close" type="button" aria-label="Fechar" data-close-group>&times;</button>
                                </div>
                                <form method="post" action="admin.php" class="grid modal-edit-form"><input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="update_group"><input type="hidden" name="grupo_id" value="<?php echo (int) $group['id']; ?>">
                                    <div class="field"><label>Nome do grupo</label><input name="nome" maxlength="160" required value="<?php echo h($group['nome']); ?>"></div>
                                    <div class="field"><label>Descrição</label><input name="descricao" maxlength="255" value="<?php echo h($group['descricao']); ?>"></div>
                                    <div><button class="btn primary" type="submit">Salvar dados</button></div>
                                </form>
                                <h3>Membros aprovadores</h3>
                                <form method="post" action="admin.php" class="grid modal-add-form"><input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="add_group_member"><input type="hidden" name="grupo_id" value="<?php echo (int) $group['id']; ?>">
                                    <div class="field"><select name="usuario_id" required>
                                            <option value="">Selecione os aprovadores</option><?php foreach ($approvers as $approver) { ?><option value="<?php echo (int) $approver['id']; ?>"><?php echo h($approver['matricula'] . ' · ' . $approver['nome']); ?></option><?php } ?>
                                        </select></div>
                                    <div><button class="btn" type="submit">Adicionar membro</button></div>
                                </form>
                                <?php if ($membersInGroup) { ?><div class="table-wrap modal-members">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Ordem hierárquica</th>
                                                    <th>Membro</th>
                                                    <th>Matrícula</th>
                                                    <th>Ação</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($membersInGroup as $memberIndex => $member) { ?><tr data-member-id="<?php echo (int) $member['usuario_id']; ?>">
                                                        <td class="member-order-cell">
                                                            <div class="member-order-content">
                                                                <button class="member-drag-handle" type="button" draggable="true" aria-label="Arrastar <?php echo h($member['nome']); ?> para reorganizar" title="Segure e arraste para reorganizar"><span aria-hidden="true">&#9776;</span></button>
                                                                <span class="member-order-value"><?php echo (int) $member['ordem_aprovacao']; ?></span>
                                                                <div class="member-order-controls">
                                                                    <button class="member-order-button" type="button" data-order-move="up" aria-label="Mover <?php echo h($member['nome']); ?> para cima" title="Mover para cima" <?php echo $memberIndex === 0 ? 'disabled' : ''; ?>>&#8593;</button>
                                                                    <button class="member-order-button" type="button" data-order-move="down" aria-label="Mover <?php echo h($member['nome']); ?> para baixo" title="Mover para baixo" <?php echo $memberIndex === count($membersInGroup) - 1 ? 'disabled' : ''; ?>>&#8595;</button>
                                                                </div>
                                                                <form method="post" action="admin.php" class="order-form">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                                    <input type="hidden" name="action" value="set_group_member_order">
                                                                    <input type="hidden" name="grupo_id" value="<?php echo (int) $group['id']; ?>">
                                                                    <input type="hidden" name="usuario_id" value="<?php echo (int) $member['usuario_id']; ?>">
                                                                </form>
                                                            </div>
                                                        </td>
                                                        <td><?php echo h($member['nome']); ?></td>
                                                        <td><?php echo h($member['matricula']); ?></td>
                                                        <td>
                                                            <form method="post" action="admin.php" class="remove-member-form">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                                <input type="hidden" name="action" value="remove_group_member">
                                                                <input type="hidden" name="grupo_id" value="<?php echo (int) $group['id']; ?>">
                                                                <input type="hidden" name="usuario_id" value="<?php echo (int) $member['usuario_id']; ?>">
                                                                <button class="btn" type="submit" title="Remover aprovador"><i class="bi bi-trash"></i></button>
                                                            </form>
                                                        </td>
                                                    </tr><?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <form method="post" action="admin.php" class="member-reorder-form" hidden>
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="reorder_group_members">
                                        <input type="hidden" name="grupo_id" value="<?php echo (int) $group['id']; ?>">
                                    </form>
                                <?php } else { ?><p class="hint">Este grupo ainda não possui membros ativos.</p><?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>
                <?php if ($activeSection === 'calendarios') { ?>
                    <section class="panel">
                        <h2>Calendários de trabalho e feriados</h2>
                        <p class="hint">Dias úteis usam os dias da semana e os feriados deste calendário. Horas contabilizam apenas dentro da janela configurada.</p>
                        <form method="post" action="admin.php"><input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="save_calendar">
                            <div class="grid">
                                <div class="field"><label for="calendarName">Nome do calendário</label><input id="calendarName" name="nome" maxlength="120" required placeholder="Ex.: Matriz SP"></div>
                                <div class="field"><label for="timezone">Fuso horário</label><select id="timezone" name="fuso_horario"><?php foreach (DateTimeZone::listIdentifiers() as $timezone) { ?><option value="<?php echo h($timezone); ?>" <?php echo $timezone === 'America/Sao_Paulo' ? ' selected' : ''; ?>><?php echo h($timezone); ?></option><?php } ?></select></div>
                                <div class="field"><label>Jornada diária</label>
                                    <div style="display:flex;gap:7px"><input type="time" name="hora_inicio" value="08:00" required><input type="time" name="hora_fim" value="18:00" required></div>
                                </div>
                            </div>
                            <div class="field" style="margin-top:12px"><label>Dias de trabalho</label>
                                <div class="days"><?php foreach (array(1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo') as $day => $label) { ?><label><input id="weekdays" type="checkbox" name="dias_semana[]" value="<?php echo $day; ?>" <?php echo $day <= 5 ? ' checked' : ''; ?>><?php echo $label; ?></label><?php } ?></div>
                            </div>
                            <div class="actions"><button class="btn primary" type="submit">Criar calendário</button></div>
                        </form>
                        <?php if ($calendars) { ?><form method="post" action="admin.php" style="border-top:1px solid var(--line);padding-top:17px;margin-top:17px"><input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="add_holiday">
                                <div class="grid">
                                    <div class="field"><label for="holidayCalendar">Calendário</label><select id="holidayCalendar" name="calendario_id" onchange="window.location='admin.php?secao=calendarios&amp;calendario_id='+this.value"><?php foreach ($calendars as $calendar) { ?><option value="<?php echo (int) $calendar['id']; ?>" <?php echo $selectedCalendar === (int) $calendar['id'] ? ' selected' : ''; ?>><?php echo h($calendar['nome']); ?></option><?php } ?></select></div>
                                    <div class="field"><label for="holidayDate">Data do feriado</label><input id="holidayDate" name="data" type="date" required></div>
                                    <div class="field"><label for="holidayDescription">Descrição</label><input id="holidayDescription" name="descricao" maxlength="160" required placeholder="Ex.: Feriado municipal"></div>
                                </div>
                                <div class="actions"><button class="btn primary" type="submit">Adicionar feriado</button></div>
                            </form>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th>Feriado</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody><?php if (!$holidays) { ?><tr>
                                                <td colspan="3">Nenhum feriado cadastrado para este calendário.</td>
                                            </tr><?php } else {
                                                    foreach ($holidays as $holiday) { ?><tr>
                                                    <td><?php echo h(date('d/m/Y', strtotime($holiday['data']))); ?></td>
                                                    <td><?php echo h($holiday['descricao']); ?></td>
                                                    <td>
                                                        <form class="inline" method="post" action="admin.php"><input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="delete_holiday"><input type="hidden" name="calendario_id" value="<?php echo $selectedCalendar; ?>"><input type="hidden" name="feriado_id" value="<?php echo (int) $holiday['id']; ?>"><button class="remove" type="submit">Remover</button></form>
                                                    </td>
                                                </tr><?php }
                                                } ?></tbody>
                                </table>
                            </div><?php } else { ?><div class="message error">Cadastre um calendário para habilitar SLAs em horas ou dias úteis.</div><?php } ?>
                    </section>
                <?php } ?>

                <?php if ($activeSection === 'trilhas') { ?>
                    <section class="panel">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                            <h2>Trilha por Centro de Custo</h2>
                            <button class="btn primary" type="button" data-open-center="create-center-modal"><i class="bi bi-plus-lg"></i> Novo Centro de Custo</button>
                        </div>
                        <p class="hint">Cada gravação cria uma nova versão da trilha. Solicitações em andamento mantêm o fluxo que receberam na abertura.</p>
                        <?php if (!$centers) { ?><div class="message">Nenhum Centro de Custo cadastrado. Use “Novo Centro de Custo” para adicionar um.</div><?php } else { ?>
                            <div class="table-wrap">
                                <table class="center-cost-table">
                                    <thead>
                                        <tr>
                                            <th>Nome</th>
                                            <th>Unidade</th>
                                            <th>OMIE</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody><?php foreach ($centers as $center) {
                                                                                                                                                                $centerId = (int) $center['id'];
                                                                                                                                                                $centerHasReferences = (int) $center['perfis_vinculados'] + (int) $center['trilhas_vinculadas'] + (int) $center['solicitacoes_vinculadas'] + (int) $center['contratos_vinculados'] > 0;
                                                                                                                                                                $centerIsActive = (int) $center['ativo_omie'] === 1; ?>
                                            <tr>
                                                <td><?php echo h($center['nome']); ?></td>
                                                <td><?php echo $center['unidade_nome'] !== null ? h($center['unidade_nome']) : '—'; ?></td>
                                                <td><?php echo $center['omie_id'] !== null && $center['omie_id'] !== '' ? 'Sim' : 'Não'; ?></td>
                                                <td>
                                                    <div class="center-cost-actions">
                                                        <button class="btn" type="button" data-open-center-edit="center-edit-modal-<?php echo $centerId; ?>" aria-label="Editar <?php echo h($center['nome']); ?>" title="Editar"><i class="bi bi-pencil"></i> Editar</button>
                                                        <?php if ($centerIsActive) { ?>
                                                            <a class="btn" href="admin.php?secao=trilhas&amp;centro_custo_id=<?php echo $centerId; ?>&amp;configurar=1" aria-label="Configurar trilha de <?php echo h($center['nome']); ?>" title="Configurar"><i class="bi bi-gear"></i> Configurar</a>
                                                        <?php } else { ?>
                                                            <button class="btn" type="button" disabled title="Somente Centros de Custo ativos podem ser configurados"><i class="bi bi-gear"></i> Configurar</button>
                                                        <?php } ?>
                                                        <form class="inline delete-center-form" method="post" action="admin.php" data-center-name="<?php echo h($center['nome']); ?>">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                            <input type="hidden" name="action" value="remove_center">
                                                            <input type="hidden" name="centro_custo_id" value="<?php echo $centerId; ?>">
                                                            <button class="btn remove" type="submit" <?php echo $centerHasReferences ? 'disabled title="Não é possível remover um Centro de Custo com dados vinculados"' : 'title="Remover Centro de Custo"'; ?>><i class="bi bi-trash"></i> Remover</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } ?>
                        <?php foreach ($centers as $center) {
                            $centerId = (int) $center['id']; ?>
                            <div class="modal-backdrop" id="center-edit-modal-<?php echo $centerId; ?>" role="dialog" aria-modal="true" aria-labelledby="center-edit-title-<?php echo $centerId; ?>" hidden>
                                <div class="modal-card center-edit-card">
                                    <div class="modal-heading">
                                        <div>
                                            <h2 id="center-edit-title-<?php echo $centerId; ?>">Editar Centro de Custo</h2>
                                            <p class="hint">Somente o nome pode ser alterado.</p>
                                        </div>
                                        <button class="modal-close" type="button" aria-label="Fechar" data-close-center-edit>&times;</button>
                                    </div>
                                    <form method="post" action="admin.php" class="user-edit-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="update_center">
                                        <input type="hidden" name="centro_custo_id" value="<?php echo $centerId; ?>">
                                        <div class="account-form-grid">
                                            <div class="field"><label for="center-id-<?php echo $centerId; ?>">ID interno</label><input id="center-id-<?php echo $centerId; ?>" type="text" value="<?php echo $centerId; ?>" readonly></div>
                                            <div class="field"><label for="center-name-<?php echo $centerId; ?>">Nome</label><input id="center-name-<?php echo $centerId; ?>" name="nome" type="text" maxlength="180" value="<?php echo h($center['nome']); ?>" required></div>
                                            <div class="field"><label for="center-code-<?php echo $centerId; ?>">Código</label><input id="center-code-<?php echo $centerId; ?>" type="text" value="<?php echo h($center['codigo'] !== null ? $center['codigo'] : ''); ?>" readonly></div>
                                            <div class="field"><label for="center-unit-<?php echo $centerId; ?>">Unidade</label><input id="center-unit-<?php echo $centerId; ?>" type="text" value="<?php echo h($center['unidade_nome'] !== null ? $center['unidade_nome'] : 'Sem unidade'); ?>" readonly></div>
                                            <div class="field"><label for="center-omie-<?php echo $centerId; ?>">OMIE</label><input id="center-omie-<?php echo $centerId; ?>" type="text" value="<?php echo $center['omie_id'] !== null && $center['omie_id'] !== '' ? 'Sim · ' . h($center['omie_id']) : 'Não vinculado'; ?>" readonly></div>
                                            <div class="field"><label for="center-status-<?php echo $centerId; ?>">Situação</label><input id="center-status-<?php echo $centerId; ?>" type="text" value="<?php echo (int) $center['ativo_omie'] === 1 ? 'Ativo' : 'Inativo'; ?>" readonly></div>
                                            <div class="field"><label for="center-sync-<?php echo $centerId; ?>">Sincronizado em</label><input id="center-sync-<?php echo $centerId; ?>" type="text" value="<?php echo h($center['sincronizado_em'] !== null ? $center['sincronizado_em'] : 'Nunca'); ?>" readonly></div>
                                        </div>
                                        <div class="actions">
                                            <button class="btn" type="button" data-close-center-edit>Cancelar</button>
                                            <button class="btn primary" type="submit">Salvar nome</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php } ?>
                        <?php if ($selectedCenterRecord && (int) $selectedCenterRecord['ativo_omie'] === 1 && isset($_GET['configurar']) && (string) $_GET['configurar'] === '1') { ?>
                            <div class="modal-backdrop" id="workflow-config-modal" role="dialog" aria-modal="true" aria-labelledby="workflow-config-title" hidden>
                                <div class="modal-card workflow-modal-card">
                                    <div class="modal-heading">
                                        <div>
                                            <h2 id="workflow-config-title">Configurar trilha · <?php echo h($selectedCenterRecord['nome']); ?></h2>
                                            <?php if ($currentFlow) { ?><p class="hint">Versão atual: <?php echo (int) $currentFlow['versao']; ?>. Salvar criará uma nova versão.</p><?php } else { ?><p class="hint">Este Centro de Custo ainda não possui uma trilha configurada.</p><?php } ?>
                                        </div>
                                        <button class="modal-close" type="button" aria-label="Fechar" data-close-workflow-config>&times;</button>
                                    </div>
                                    <form method="post" action="admin.php"><input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="save_workflow"><input type="hidden" name="centro_custo_id" value="<?php echo (int) $selectedCenter; ?>">
                                        <div class="field" style="max-width:390px;margin-bottom:18px"><label for="directorLimit">Alçada para aprovação automática do Diretor (R$)</label><input id="directorLimit" name="limite_alcada_diretor" type="number" min="0" step="0.01" value="<?php echo $currentFlow && $currentFlow['limite_alcada_diretor'] !== null ? h($currentFlow['limite_alcada_diretor']) : ''; ?>" placeholder="Deixe vazio para desativar"><small class="hint">Se a cotação vencedora aprovada ultrapassar esse valor, qualquer Diretor ativo será incluído como aprovador final.</small></div>
                                        <div class="stage-grid">
                                            <?php foreach ($stageNames as $code => $label) {
                                                $current = isset($currentStages[$code]) ? $currentStages[$code] : array();
                                                $enabled = (bool) $current; ?>
                                                <div class="stage-row">
                                                    <div class="stage-title"><input type="checkbox" name="etapas[<?php echo h($code); ?>][ativa]" value="1" <?php echo $enabled ? ' checked' : ''; ?>><span><?php echo h($label); ?></span></div>
                                                    <div class="stage-fields">
                                                        <div class="field"><label>Tipo de aprovador</label><select class="approver-type" name="etapas[<?php echo h($code); ?>][responsavel_tipo]">
                                                                <option value="grupo" <?php echo !isset($current['responsavel_tipo']) || $current['responsavel_tipo'] === 'grupo' ? ' selected' : ''; ?>>Grupo</option>
                                                                <option value="usuario" <?php echo isset($current['responsavel_tipo']) && $current['responsavel_tipo'] === 'usuario' ? ' selected' : ''; ?>>Usuário</option>
                                                                <option value="grupo_usuario" <?php echo isset($current['responsavel_tipo']) && $current['responsavel_tipo'] === 'grupo_usuario' ? ' selected' : ''; ?>>Grupo e Usuário</option>
                                                            </select></div>
                                                        <div class="field approver-group"><label>Grupo</label><select name="etapas[<?php echo h($code); ?>][grupo_id]">
                                                                <option value="">Selecione</option><?php foreach ($groups as $group) { ?><option value="<?php echo (int) $group['id']; ?>" <?php echo isset($current['grupo_id']) && (int) $current['grupo_id'] === (int) $group['id'] ? ' selected' : ''; ?>><?php echo h($group['nome']); ?></option><?php } ?>
                                                            </select></div>
                                                        <div class="field approver-users" style="grid-column:span 2"><label for="approver-users-<?php echo h($code); ?>">Usuários aprovadores</label>
                                                            <?php $selectedUserIds = isset($currentStageUsers[$code]) ? $currentStageUsers[$code] : (isset($current['usuario_id']) && $current['usuario_id'] ? array((int) $current['usuario_id']) : array()); ?>
                                                            <select id="approver-users-<?php echo h($code); ?>" class="approver-multiselect" name="etapas[<?php echo h($code); ?>][usuario_ids][]" multiple aria-label="Usuários aprovadores">
                                                                <?php foreach ($approvers as $approver) { ?>
                                                                    <option value="<?php echo (int) $approver['id']; ?>" <?php echo in_array((int) $approver['id'], $selectedUserIds, true) ? ' selected' : ''; ?>><?php echo h($approver['nome'] . ' · ' . $approver['matricula']); ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                        <div class="field"><label>Regra</label><select name="etapas[<?php echo h($code); ?>][modo]">
                                                                <option value="E" <?php echo !isset($current['modo']) || $current['modo'] === 'E' ? ' selected' : ''; ?>>E</option>
                                                                <option value="OU" <?php echo isset($current['modo']) && $current['modo'] === 'OU' ? ' selected' : ''; ?>>OU</option>
                                                            </select></div>
                                                        <div class="field"><label>Prazo</label><input type="number" min="0.25" step="0.25" name="etapas[<?php echo h($code); ?>][duracao]" value="<?php echo h(isset($current['duracao']) ? $current['duracao'] : '24'); ?>" required></div>
                                                        <div class="field"><label>Unidade</label><select name="etapas[<?php echo h($code); ?>][unidade_tempo]">
                                                                <option value="horas" <?php echo !isset($current['unidade_tempo']) || $current['unidade_tempo'] === 'horas' ? ' selected' : ''; ?>>Horas</option>
                                                                <option value="dias" <?php echo isset($current['unidade_tempo']) && $current['unidade_tempo'] === 'dias' ? ' selected' : ''; ?>>Dias</option>
                                                            </select></div>
                                                        <div class="field"><label>Contagem</label><select name="etapas[<?php echo h($code); ?>][regime]">
                                                                <option value="uteis" <?php echo isset($current['regime_contagem']) && $current['regime_contagem'] === 'uteis' ? ' selected' : ''; ?>>Úteis</option>
                                                                <option value="corridos" <?php echo !isset($current['regime_contagem']) || $current['regime_contagem'] === 'corridos' ? ' selected' : ''; ?>>Corridos</option>
                                                            </select></div>
                                                        <div class="field"><label>Calendário</label><select name="etapas[<?php echo h($code); ?>][calendario_id]">
                                                                <option value="">Não usar</option><?php foreach ($calendars as $calendar) { ?><option value="<?php echo (int) $calendar['id']; ?>" <?php echo isset($current['calendario_id']) && (int) $current['calendario_id'] === (int) $calendar['id'] ? ' selected' : ''; ?>><?php echo h($calendar['nome']); ?></option><?php } ?>
                                                            </select></div>
                                                        <div class="field" style="grid-column:span 2"><label>Janela de horas</label>
                                                            <div style="font-size:11px;color:#727b88;padding:10px 0">Usa o horário do calendário selecionado.</div>
                                                        </div>
                                                    </div>
                                                </div><?php } ?>
                                        </div>
                                        <div class="actions"><button class="btn primary" type="submit">Salvar nova versão da trilha</button></div>
                                    </form>
                                </div>
                            </div>
                        <?php } ?>
                    </section>
                    <div class="modal-backdrop" id="create-center-modal" role="dialog" aria-modal="true" aria-labelledby="create-center-title" hidden>
                        <div class="modal-card" style="width:min(560px,100%)">
                            <div class="modal-heading">
                                <div>
                                    <h2 id="create-center-title">Novo Centro de Custo</h2>
                                    <p class="hint">Informe os dados do Centro de Custo. O cadastro ficará ativo para configuração da trilha.</p>
                                </div>
                                <button class="modal-close" type="button" aria-label="Fechar" data-close-center>&times;</button>
                            </div>
                            <form method="post" action="admin.php" class="grid" style="grid-template-columns:1fr;margin-top:18px">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="create_center">
                                <div class="field">
                                    <label for="newCenterName">Nome</label>
                                    <input id="newCenterName" name="nome" type="text" maxlength="180" value="<?php echo h($centerFormDraft['nome']); ?>" required>
                                </div>
                                <div class="grid" style="grid-template-columns:1fr 1fr">
                                    <div class="field">
                                        <label for="newCenterCode">Código <span class="muted">(opcional)</span></label>
                                        <input id="newCenterCode" name="codigo" type="text" maxlength="50" value="<?php echo h($centerFormDraft['codigo']); ?>">
                                    </div>
                                    <div class="field">
                                        <label for="newCenterUnit">Unidade <span class="muted">(opcional)</span></label>
                                        <select id="newCenterUnit" name="unidade_id">
                                            <option value="">Sem unidade</option>
                                            <?php foreach ($units as $unit) { ?>
                                                <option value="<?php echo (int) $unit['id']; ?>" <?php echo isset($centerFormDraft['unidade_id']) && (int) $centerFormDraft['unidade_id'] === (int) $unit['id'] ? 'selected' : ''; ?>><?php echo h($unit['nome']); ?></option>
                                            <?php } ?>
                                        </select>
                                        <?php if (!$units) { ?><small class="hint">Não há unidades ativas cadastradas; a unidade pode ser associada depois.</small><?php } ?>
                                    </div>
                                </div>
                                <div class="actions">
                                    <button class="btn" type="button" data-close-center>Cancelar</button>
                                    <button class="btn primary" type="submit">Cadastrar Centro de Custo</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://unpkg.com/multiple-select@2.4.0/dist/multiple-select.min.js"></script>
    <?php if ($activeSection === 'logs') { ?>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script>
        $(function() {
            $('#systemLogsTable').DataTable({
                processing: true,
                serverSide: true,
                searchDelay: 350,
                scrollX: true,
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100],
                order: [[1, 'desc']],
                ajax: {
                    url: 'admin_logs.php',
                    type: 'GET'
                },
                columns: [
                    { data: 'id' },
                    { data: 'ocorrido_em' },
                    { data: 'usuario' },
                    { data: 'acao' },
                    { data: 'entidade' },
                    { data: 'item_id' },
                    { data: 'status' },
                    { data: 'ip_usuario' },
                    { data: 'detalhes' },
                    { data: 'erro' }
                ],
                columnDefs: [{
                    targets: [0, 1, 2, 3, 4, 5, 6, 7, 9],
                    render: $.fn.dataTable.render.text()
                }, {
                    targets: 8,
                    render: function(data, type) {
                        if (type !== 'display' || data === null || data === '') {
                            return data;
                        }

                        var formatted = data;
                        try {
                            formatted = JSON.stringify(JSON.parse(data), null, 2);
                        } catch (error) {
                            formatted = data;
                        }

                        return $('<pre>').addClass('log-details').text(formatted)[0].outerHTML;
                    }
                }],
                language: {
                    decimal: ',',
                    thousands: '.',
                    emptyTable: 'Nenhum log registrado.',
                    info: 'Exibindo _START_ a _END_ de _TOTAL_ registros',
                    infoEmpty: 'Exibindo 0 a 0 de 0 registros',
                    infoFiltered: '(filtrado de _MAX_ registros)',
                    lengthMenu: 'Exibir _MENU_ linhas',
                    loadingRecords: 'Carregando logs...',
                    processing: 'Carregando...',
                    search: 'Buscar:',
                    zeroRecords: 'Nenhum registro correspondente encontrado.',
                    paginate: {
                        first: 'Primeira',
                        last: 'Última',
                        next: 'Próxima',
                        previous: 'Anterior'
                    },
                    aria: {
                        sortAscending: ': ordenar coluna crescente',
                        sortDescending: ': ordenar coluna decrescente'
                    }
                }
            });
        });
    </script>
    <?php } ?>
    <script>
        (function() {
        var workflowMessage = <?php echo $messageInSweetAlert && $message !== '' ? json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) : 'null'; ?>;
        var workflowMessageType = <?php echo $messageInSweetAlert ? json_encode($messageType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) : 'null'; ?>;
        if (workflowMessage && window.Swal) {
            var messageElement = document.getElementById('adminMessage');
            if (messageElement) messageElement.hidden = true;
            var workflowMessageIsError = workflowMessageType === 'error';
            Swal.fire({
                title: workflowMessageIsError ? 'Não foi possível configurar a trilha' : 'Configuração da trilha',
                text: workflowMessage,
                icon: workflowMessageIsError ? 'error' : (workflowMessageType === 'success' ? 'success' : 'warning'),
                confirmButtonText: 'Entendi'
            });
        }

        var adminLayout = document.getElementById('adminLayout');
            var sidebarToggle = document.getElementById('sidebarToggle');
            if (window.matchMedia && window.matchMedia('(max-width: 680px)').matches) {
                adminLayout.classList.add('sidebar-collapsed');
                sidebarToggle.setAttribute('aria-expanded', 'false');
                sidebarToggle.setAttribute('aria-label', 'Expandir menu lateral');
                sidebarToggle.querySelector('.sidebar-toggle-label').textContent = 'Expandir menu';
            }
            sidebarToggle.addEventListener('click', function() {
                var collapsed = adminLayout.classList.toggle('sidebar-collapsed');
                sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                sidebarToggle.setAttribute('aria-label', collapsed ? 'Expandir menu lateral' : 'Recolher menu lateral');
                sidebarToggle.querySelector('.sidebar-toggle-label').textContent = collapsed ? 'Expandir menu' : 'Recolher menu';
            });

            if (window.jQuery && jQuery.fn.multipleSelect) {
                jQuery('.approver-multiselect').multipleSelect({
                    filter: true,
                    filterPlaceholder: 'Pesquisar por nome ou matrícula',
                    filterSelectAll: true,
                    selectAll: true,
                    showClear: true,
                    minimumCountSelected: 1,
                    width: '100%',
                    formatSelectAll: function() {
                        return 'Selecionar todos';
                    },
                    formatAllSelected: function() {
                        return 'Todos selecionados';
                    },
                    formatCountSelected: function(count, total) {
                        return count + ' de ' + total + ' selecionados';
                    },
                    formatNoMatchesFound: function() {
                        return 'Nenhum usuário encontrado';
                    }
                });
            }

            function updateApproverFields(typeSelect) {
                var row = typeSelect.closest('.stage-row');
                var groupField = row.querySelector('.approver-group');
                var userField = row.querySelector('.approver-users');
                var useGroup = typeSelect.value === 'grupo' || typeSelect.value === 'grupo_usuario';
                var useUsers = typeSelect.value === 'usuario' || typeSelect.value === 'grupo_usuario';

                groupField.style.display = useGroup ? '' : 'none';
                userField.style.display = useUsers ? '' : 'none';
                groupField.querySelector('select').disabled = !useGroup;

                var userSelect = userField.querySelector('.approver-multiselect');

                if (window.jQuery && jQuery.fn.multipleSelect && userSelect) {
                    jQuery(userSelect).multipleSelect(useUsers ? 'enable' : 'disable');
                } else if (userSelect) {
                    userSelect.disabled = !useUsers;
                }
            }
            document.querySelectorAll('.approver-type').forEach(function(select) {
                select.addEventListener('change', function() {
                    updateApproverFields(select);
                });
                updateApproverFields(select);
            });

            function updateMemberOrder(tbody) {
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-member-id]'));
                rows.forEach(function(row, index) {
                    row.querySelector('.member-order-value').textContent = index + 1;
                    row.querySelector('[data-order-move="up"]').disabled = index === 0;
                    row.querySelector('[data-order-move="down"]').disabled = index === rows.length - 1;
                });
            }

            function saveMemberOrder(tbody) {
                var form = tbody.closest('.modal-card').querySelector('.member-reorder-form');
                form.querySelectorAll('input[name="membros[]"]').forEach(function(input) {
                    input.remove();
                });
                tbody.querySelectorAll('tr[data-member-id]').forEach(function(row) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'membros[]';
                    input.value = row.getAttribute('data-member-id');
                    form.appendChild(input);
                });
                form.submit();
            }

            document.querySelectorAll('.modal-members tbody').forEach(function(tbody) {
                var draggedRow = null;
                tbody.addEventListener('click', function(event) {
                    var button = event.target.closest('[data-order-move]');
                    if (!button || button.disabled) return;
                    var row = button.closest('tr[data-member-id]');
                    if (button.getAttribute('data-order-move') === 'up' && row.previousElementSibling) {
                        tbody.insertBefore(row, row.previousElementSibling);
                    } else if (button.getAttribute('data-order-move') === 'down' && row.nextElementSibling) {
                        tbody.insertBefore(row.nextElementSibling, row);
                    }
                    updateMemberOrder(tbody);
                    saveMemberOrder(tbody);
                });

                tbody.addEventListener('dragstart', function(event) {
                    if (!event.target.closest('.member-drag-handle')) return;
                    draggedRow = event.target.closest('tr[data-member-id]');
                    if (!draggedRow) return;
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', draggedRow.getAttribute('data-member-id'));
                    draggedRow.classList.add('is-dragging');
                });

                tbody.addEventListener('dragover', function(event) {
                    var targetRow = event.target.closest('tr[data-member-id]');
                    if (!draggedRow || !targetRow || targetRow === draggedRow) return;
                    event.preventDefault();
                    tbody.querySelectorAll('.is-drop-target').forEach(function(row) {
                        row.classList.remove('is-drop-target');
                    });
                    targetRow.classList.add('is-drop-target');
                });

                tbody.addEventListener('drop', function(event) {
                    var targetRow = event.target.closest('tr[data-member-id]');
                    if (!draggedRow || !targetRow || targetRow === draggedRow) return;
                    event.preventDefault();
                    var insertAfter = event.clientY >= targetRow.getBoundingClientRect().top + targetRow.offsetHeight / 2;
                    tbody.insertBefore(draggedRow, insertAfter ? targetRow.nextElementSibling : targetRow);
                    draggedRow.classList.remove('is-dragging');
                    draggedRow = null;
                    tbody.querySelectorAll('.is-drop-target').forEach(function(row) {
                        row.classList.remove('is-drop-target');
                    });
                    updateMemberOrder(tbody);
                    saveMemberOrder(tbody);
                });

                tbody.addEventListener('dragend', function() {
                    if (draggedRow) draggedRow.classList.remove('is-dragging');
                    draggedRow = null;
                    tbody.querySelectorAll('.is-drop-target').forEach(function(row) {
                        row.classList.remove('is-drop-target');
                    });
                });
            });

            function openModal(modal) {
                if (!modal) return;
                modal.hidden = false;
                document.body.classList.add('modal-open');
                var firstField = modal.querySelector('input[name="nome"], select:not([disabled]), input:not([type="hidden"]):not([disabled])');
                if (firstField) firstField.focus();
            }

            function closeModal(modal) {
                if (!modal) return;
                modal.hidden = true;
                document.body.classList.remove('modal-open');
            }

            document.querySelectorAll('[data-open-group]').forEach(function(button) {
                button.addEventListener('click', function() {
                    openModal(document.getElementById(button.getAttribute('data-open-group')));
                });
            });
            document.querySelectorAll('[data-open-user]').forEach(function(button) {
                button.addEventListener('click', function() {
                    openModal(document.getElementById(button.getAttribute('data-open-user')));
                });
            });
            document.querySelectorAll('[data-open-user-import]').forEach(function(button) {
                button.addEventListener('click', function() {
                    openModal(document.getElementById(button.getAttribute('data-open-user-import')));
                });
            });
            document.querySelectorAll('[data-open-catalog]').forEach(function(button) {
                button.addEventListener('click', function() {
                    openModal(document.getElementById(button.getAttribute('data-open-catalog')));
                });
            });
            document.querySelectorAll('[data-open-center]').forEach(function(button) {
                button.addEventListener('click', function() {
                    openModal(document.getElementById(button.getAttribute('data-open-center')));
                });
            });
            document.querySelectorAll('[data-open-center-edit]').forEach(function(button) {
                button.addEventListener('click', function() {
                    openModal(document.getElementById(button.getAttribute('data-open-center-edit')));
                });
            });
            document.querySelectorAll('[data-close-group]').forEach(function(button) {
                button.addEventListener('click', function() {
                    closeModal(button.closest('.modal-backdrop'));
                });
            });
            document.querySelectorAll('[data-close-user]').forEach(function(button) {
                button.addEventListener('click', function() {
                    closeModal(button.closest('.modal-backdrop'));
                });
            });
            document.querySelectorAll('[data-close-user-import]').forEach(function(button) {
                button.addEventListener('click', function() {
                    closeModal(button.closest('.modal-backdrop'));
                });
            });
            document.querySelectorAll('[data-close-catalog]').forEach(function(button) {
                button.addEventListener('click', function() {
                    closeModal(button.closest('.modal-backdrop'));
                });
            });
            document.querySelectorAll('[data-close-center]').forEach(function(button) {
                button.addEventListener('click', function() {
                    closeModal(button.closest('.modal-backdrop'));
                });
            });
            document.querySelectorAll('[data-close-center-edit], [data-close-workflow-config]').forEach(function(button) {
                button.addEventListener('click', function() {
                    closeModal(button.closest('.modal-backdrop'));
                });
            });
            document.querySelectorAll('.modal-backdrop').forEach(function(modal) {
                modal.addEventListener('click', function(event) {
                    if (event.target === modal) closeModal(modal);
                });
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') document.querySelectorAll('.modal-backdrop:not([hidden])').forEach(closeModal);
            });

            var requestedGroup = <?php echo $selectedGroupModal ? (int) $selectedGroupModal : 'null'; ?>;
            if (requestedGroup) openModal(document.getElementById('group-modal-' + requestedGroup));
            var requestedUser = <?php echo $selectedUserModal ? (int) $selectedUserModal : 'null'; ?>;
            if (requestedUser) openModal(document.getElementById('user-modal-' + requestedUser));
            var requestedUserImport = <?php echo $showUserImportModal ? 'true' : 'false'; ?>;
            if (requestedUserImport) openModal(document.getElementById('user-import-modal'));
            var requestedNewCategory = <?php echo isset($_GET['nova_categoria']) && (string) $_GET['nova_categoria'] === '1' ? 'true' : 'false'; ?>;
            if (requestedNewCategory) openModal(document.getElementById('create-category-modal'));
            var requestedCategory = <?php echo $selectedCategoryModal ? (int) $selectedCategoryModal : 'null'; ?>;
            if (requestedCategory) openModal(document.getElementById('category-modal-' + requestedCategory));
            var requestedNewProduct = <?php echo isset($_GET['novo_produto']) && (string) $_GET['novo_produto'] === '1' ? 'true' : 'false'; ?>;
            if (requestedNewProduct) openModal(document.getElementById('create-product-modal'));
            var requestedProduct = <?php echo $selectedProductModal ? (int) $selectedProductModal : 'null'; ?>;
            if (requestedProduct) openModal(document.getElementById('product-modal-' + requestedProduct));
            var requestedNewCenter = <?php echo isset($_GET['novo_centro_custo']) && (string) $_GET['novo_centro_custo'] === '1' ? 'true' : 'false'; ?>;
            if (requestedNewCenter) openModal(document.getElementById('create-center-modal'));
            var requestedEditCenter = <?php echo $editCenter ? (int) $editCenter['id'] : 'null'; ?>;
            if (requestedEditCenter) openModal(document.getElementById('center-edit-modal-' + requestedEditCenter));
            var requestedWorkflowConfig = <?php echo $selectedCenterRecord && (int) $selectedCenterRecord['ativo_omie'] === 1 && isset($_GET['configurar']) && (string) $_GET['configurar'] === '1' ? 'true' : 'false'; ?>;
            if (requestedWorkflowConfig) openModal(document.getElementById('workflow-config-modal'));

            document.querySelectorAll('.delete-user-form').forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    var userName = form.getAttribute('data-user-name') || 'este usuário';
                    if (window.Swal) {
                        Swal.fire({
                            title: 'Remover usuário?',
                            text: 'A conta de ' + userName + ' será removida. Usuários com registros vinculados não podem ser removidos; nesses casos, inative a conta.',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Remover',
                            cancelButtonText: 'Cancelar',
                            confirmButtonColor: '#a03933'
                        }).then(function(result) {
                            if (result.isConfirmed) form.submit();
                        });
                    } else if (window.confirm('Remover a conta de ' + userName + '? Usuários com registros vinculados não podem ser removidos.')) {
                        form.submit();
                    }
                });
            });

            document.querySelectorAll('.delete-center-form').forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    var centerName = form.getAttribute('data-center-name') || 'este Centro de Custo';
                    if (window.Swal) {
                        Swal.fire({
                            title: 'Remover Centro de Custo?',
                            text: 'O Centro de Custo "' + centerName + '" será removido. Registros com perfis, trilhas, solicitações ou contratos vinculados não podem ser removidos.',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Remover',
                            cancelButtonText: 'Cancelar',
                            confirmButtonColor: '#a03933'
                        }).then(function(result) {
                            if (result.isConfirmed) form.submit();
                        });
                    } else if (window.confirm('Remover o Centro de Custo "' + centerName + '"? Registros vinculados impedem a remoção.')) {
                        form.submit();
                    }
                });
            });

            document.querySelectorAll('.delete-catalog-form').forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    var name = form.getAttribute('data-catalog-name') || 'este registro';
                    var type = form.getAttribute('data-catalog-type') || 'registro';
                    var message = '"' + name + '" será removido. Registros vinculados impedem a remoção.';
                    if (window.Swal) {
                        Swal.fire({
                            title: 'Remover ' + type + '?',
                            text: message,
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Remover',
                            cancelButtonText: 'Cancelar',
                            confirmButtonColor: '#a03933'
                        }).then(function(result) {
                            if (result.isConfirmed) form.submit();
                        });
                    } else if (window.confirm(message)) {
                        form.submit();
                    }
                });
            });

            document.querySelectorAll('.dissolve-form').forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    var button = form.querySelector('[data-group-name]');
                    var groupName = button ? button.getAttribute('data-group-name') : 'este grupo';
                    var escapedName = groupName.replace(/[&<>"']/g, function(character) {
                        return {
                            '&': '&amp;',
                            '<': '&lt;',
                            '>': '&gt;',
                            '"': '&quot;',
                            "'": '&#039;'
                        } [character];
                    });
                    if (window.Swal) {
                        Swal.fire({
                            title: 'Dissolver grupo?',
                            html: 'Os membros serão removidos deste grupo, mas continuarão cadastrados no sistema. O grupo <strong>' + escapedName + '</strong> será permanentemente excluído.',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Sim, excluir grupo',
                            cancelButtonText: 'Cancelar',
                            confirmButtonColor: '#a03933'
                        }).then(function(result) {
                            if (result.isConfirmed) form.submit();
                        });
                    } else if (window.confirm('Os membros serão removidos deste grupo, mas continuarão cadastrados no sistema. O grupo será permanentemente excluído. Deseja continuar?')) {
                        form.submit();
                    }
                });
            });
        }());
    </script>
</body>

</html>