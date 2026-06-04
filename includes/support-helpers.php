<?php

function clevoEnsureSupportTicketsGuestEmailColumn(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $result = $conn->query("SHOW COLUMNS FROM support_tickets LIKE 'guest_email'");
    if ($result && $result->num_rows > 0) {
        return;
    }

    $conn->query("ALTER TABLE support_tickets ADD COLUMN guest_email varchar(100) DEFAULT NULL AFTER user_type");
}

function clevoEnsureSupportMessagesTable(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    clevoEnsureSupportTicketsGuestEmailColumn($conn);

    $conn->query("CREATE TABLE IF NOT EXISTS support_messages (
        MessageID INT(11) NOT NULL AUTO_INCREMENT,
        TicketID INT(11) NOT NULL,
        SenderRole ENUM('user','admin') NOT NULL,
        SenderID INT(11) DEFAULT NULL,
        Message TEXT NOT NULL,
        CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (MessageID),
        KEY TicketID (TicketID),
        KEY SenderRole (SenderRole),
        CONSTRAINT support_messages_ticket_fk FOREIGN KEY (TicketID) REFERENCES support_tickets(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function clevoCleanupExpiredSupportChats(mysqli $conn): void
{
    clevoEnsureSupportMessagesTable($conn);
    $conn->query("DELETE t FROM support_tickets t
        JOIN (
            SELECT TicketID,
                   MAX(CASE WHEN SenderRole = 'admin' THEN CreatedAt END) AS last_admin_at,
                   MAX(CASE WHEN SenderRole = 'user' THEN CreatedAt END) AS last_user_at
            FROM support_messages
            GROUP BY TicketID
        ) m ON m.TicketID = t.id
        WHERE t.status = 'Replied'
          AND m.last_admin_at IS NOT NULL
          AND (m.last_user_at IS NULL OR m.last_admin_at > m.last_user_at)
          AND m.last_admin_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
}

function clevoAddSupportMessage(mysqli $conn, int $ticketID, string $senderRole, ?int $senderID, string $message): bool
{
    clevoEnsureSupportMessagesTable($conn);
    $message = trim($message);
    if ($ticketID <= 0 || !in_array($senderRole, ['user', 'admin'], true) || $message === '') {
        return false;
    }

    $stmt = $conn->prepare("INSERT INTO support_messages (TicketID, SenderRole, SenderID, Message) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isis", $ticketID, $senderRole, $senderID, $message);
    return $stmt->execute();
}

function clevoSendGuestSupportReplyEmail(string $toEmail, string $guestName, string $subject, string $replyMessage): bool
{
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        error_log('Guest support reply email failed: PHPMailer is not available.');
        return false;
    }

    $safeName = htmlspecialchars($guestName ?: 'Guest', ENT_QUOTES, 'UTF-8');
    $safeSubject = htmlspecialchars($subject ?: 'Support request', ENT_QUOTES, 'UTF-8');
    $safeReply = nl2br(htmlspecialchars($replyMessage, ENT_QUOTES, 'UTF-8'));

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'support.clevo@gmail.com';
        $mail->Password = 'aessfjspgpicvcje';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('support.clevo@gmail.com', 'Clevo Support');
        $mail->addAddress($toEmail, $guestName ?: 'Guest');
        $mail->isHTML(true);
        $mail->Subject = 'Clevo support reply: ' . ($subject ?: 'Support request');
        $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:620px;margin:auto;background:#f8fafc;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden'>
                <div style='background:#0ea5e9;color:#ffffff;padding:22px 26px'>
                    <h2 style='margin:0;font-size:24px'>Clevo Support Reply</h2>
                    <p style='margin:8px 0 0;font-size:14px;opacity:.9'>Subject: {$safeSubject}</p>
                </div>
                <div style='padding:26px;color:#111827'>
                    <p style='font-size:16px;margin-top:0'>Hello {$safeName},</p>
                    <p style='font-size:15px;line-height:1.8;color:#374151'>Our support team replied to your message:</p>
                    <div style='background:#ffffff;border:1px solid #dbeafe;border-radius:14px;padding:18px;font-size:15px;line-height:1.8;color:#111827'>{$safeReply}</div>
                    <p style='font-size:13px;color:#64748b;margin-top:22px'>You can reply to this email if you need more help.</p>
                </div>
            </div>";
        $mail->AltBody = "Clevo Support Reply\nSubject: {$subject}\n\n{$replyMessage}";

        return $mail->send();
    } catch (\Throwable $e) {
        error_log('Guest support reply email failed: ' . $e->getMessage());
        return false;
    }
}

function clevoGetSupportMessages(mysqli $conn, int $ticketID): array
{
    clevoEnsureSupportMessagesTable($conn);
    $stmt = $conn->prepare("SELECT * FROM support_messages WHERE TicketID = ? ORDER BY CreatedAt ASC, MessageID ASC");
    $stmt->bind_param("i", $ticketID);
    $stmt->execute();
    $messages = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $messages[] = $row;
    }
    return $messages;
}

function clevoLegacyTicketMessages(array $ticket): array
{
    $parts = explode("\n\n--- Admin Reply ---\n", (string) ($ticket['message'] ?? ''));
    $messages = [];
    $messages[] = [
        'SenderRole' => 'user',
        'Message' => $parts[0] ?? '',
        'CreatedAt' => $ticket['created_at'] ?? '',
    ];
    for ($i = 1; $i < count($parts); $i++) {
        $messages[] = [
            'SenderRole' => 'admin',
            'Message' => $parts[$i],
            'CreatedAt' => $ticket['created_at'] ?? '',
        ];
    }
    return $messages;
}

function clevoSupportMessagesForTicket(mysqli $conn, array $ticket): array
{
    $messages = clevoGetSupportMessages($conn, (int) ($ticket['id'] ?? 0));
    return $messages ?: clevoLegacyTicketMessages($ticket);
}
