<?php

function quoteEvidenceDirectory()
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'quotes';
}

function quoteEvidencePath($storageKey)
{
    if (!is_string($storageKey) || !preg_match('#^quotes/[a-f0-9]{64}\.(pdf|jpg|jpeg|png|eml)$#', $storageKey)) {
        return null;
    }
    return quoteEvidenceDirectory() . DIRECTORY_SEPARATOR . basename($storageKey);
}

function storeQuoteEvidence(PDO $pdo, array $file, $requestId, $quoteId, $userId)
{
    if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Não foi possível receber o comprovante enviado. Selecione o arquivo novamente.');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > 20 * 1024 * 1024) {
        throw new RuntimeException('O comprovante deve ter tamanho máximo de 20 MB.');
    }

    $originalName = basename(str_replace('\\', '/', (string) $file['name']));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mimeFinder = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $mimeFinder->file($file['tmp_name']);
    $allowed = array(
        'pdf' => array('application/pdf'),
        'jpg' => array('image/jpeg'),
        'jpeg' => array('image/jpeg'),
        'png' => array('image/png'),
        'eml' => array('message/rfc822', 'text/plain')
    );
    if (!isset($allowed[$extension]) || !in_array($mimeType, $allowed[$extension], true)) {
        throw new RuntimeException('Anexe um comprovante em PDF, imagem JPG/PNG ou e-mail EML.');
    }

    $directory = quoteEvidenceDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar o armazenamento privado de comprovantes.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('O armazenamento privado de comprovantes não permite gravação. Verifique as permissões de storage/private/quotes para o usuário do servidor web.');
    }
    $storageKey = 'quotes/' . bin2hex(random_bytes(32)) . '.' . $extension;
    $absolutePath = quoteEvidencePath($storageKey);
    if (!$absolutePath || !@move_uploaded_file($file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Não foi possível armazenar o comprovante. Verifique as permissões e o espaço disponível em storage/private/quotes.');
    }
    @chmod($absolutePath, 0600);
    $size = filesize($absolutePath);
    $checksum = hash_file('sha256', $absolutePath);

    try {
        $insert = $pdo->prepare('INSERT INTO anexos (solicitacao_id, cotacao_id, autor_id, nome_original, mime_type, tamanho, storage_key, checksum) VALUES (:request_id, :quote_id, :user_id, :original_name, :mime_type, :size, :storage_key, :checksum)');
        $insert->execute(array(
            'request_id' => $requestId,
            'quote_id' => $quoteId,
            'user_id' => $userId,
            'original_name' => substr($originalName, 0, 255),
            'mime_type' => $mimeType,
            'size' => $size,
            'storage_key' => $storageKey,
            'checksum' => $checksum
        ));
    } catch (Throwable $exception) {
        @unlink($absolutePath);
        throw $exception;
    }

    return array('id' => (int) $pdo->lastInsertId(), 'storage_key' => $storageKey, 'path' => $absolutePath);
}
