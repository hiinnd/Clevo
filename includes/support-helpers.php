<?php

function clevoEnsureSupportMessagesTable(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

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
