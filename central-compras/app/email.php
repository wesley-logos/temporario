<?php

require_once __DIR__ . '/environment.php';

function sendApplicationEmail($recipient, $subject, $body)
{
    $host = trim((string) applicationEnv('MAIL_HOST', ''));
    $port = filter_var(applicationEnv('MAIL_PORT', null), FILTER_VALIDATE_INT);
    $username = trim((string) applicationEnv('MAIL_USERNAME', ''));
    $password = (string) applicationEnv('MAIL_PASSWORD', '');
    $encryption = strtolower(trim((string) applicationEnv('MAIL_ENCRYPTION', '')));
    $fromAddress = trim((string) applicationEnv('MAIL_FROM_ADDRESS', ''));
    $fromName = trim((string) applicationEnv('MAIL_FROM_NAME', 'Central de Compras'));

    if ($host === '' || !$port || $port > 65535 || $username === '' || $password === ''
        || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || $fromName === ''
        || !in_array($encryption, array('ssl', 'tls'), true)
    ) {
        throw new RuntimeException('As configurações SMTP MAIL_* estão ausentes ou inválidas.');
    }

    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $host;
    $mailer->Port = $port;
    $mailer->SMTPAuth = true;
    $mailer->Username = $username;
    $mailer->Password = $password;
    $mailer->SMTPSecure = $encryption === 'ssl'
        ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Timeout = 15;
    $mailer->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mailer->setFrom($fromAddress, $fromName);
    $mailer->addAddress($recipient);
    $mailer->isHTML(false);
    $mailer->Subject = $subject;
    $mailer->Body = $body;
    $mailer->send();
}
