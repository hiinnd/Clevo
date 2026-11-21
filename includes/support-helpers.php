<?php

function clevoEnsureSupportTicketsGuestEmailColumn(mysqli $conn): void
{
    clevo_require_schema($conn, 'support_tickets', ['guest_email']);
}

function clevoEnsureSupportMessagesTable(mysqli $conn): void
{
    clevo_require_schema($conn, 'support_tickets', ['guest_email']);
    clevo_require_schema($conn, 'support_messages', []);
}

function clevoCleanupExpiredSupportChats(mysqli $conn): void
{
    // Compatibility entry point: tickets close only through explicit administrative review.
    clevoEnsureSupportMessagesTable($conn);
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
    return clevo_send_mail($toEmail, $guestName, 'CLEVO support reply', '<h3>' . e($subject) . '</h3><p>' . nl2br(e($replyMessage)) . '</p>');
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

function clevo_market_ticket(mysqli $db,int $user,int $id,bool $admin=false,bool $lock=false):array
{
    $row=clevo_stmt($db,'SELECT * FROM support_tickets WHERE id=?'.($admin?'':' AND user_id=?').($lock?' FOR UPDATE':''),$admin?'i':'ii',$admin?[$id]:[$id,$user])->get_result()->fetch_assoc();
    if(!$row)throw new ClevoSecurityException('not_found',404);return $row;
}
function clevo_market_support_reply(mysqli $db,int $user,int $id,string $message,bool $admin=false):void
{
    if($admin)clevo_admin_actor($db,$user);
    if(trim($message)===''||mb_strlen($message)>4000)throw new ClevoSecurityException('invalid_message',422);
    $ticket=clevo_market_ticket($db,$user,$id,$admin,true);
    if($ticket['status']==='Closed')throw new ClevoSecurityException('ticket_closed',409);
    if(!clevoAddSupportMessage($db,$id,$admin?'admin':'user',$user,$message))throw new RuntimeException('Message unavailable.');
    // Full content remains in support_messages; do not overflow the legacy TEXT preview/cache.
    $legacy=mb_strcut($ticket['message'].($admin?"\n\n--- Admin Reply ---\n":"\n\n--- User Reply ---\n").$message,0,60000,'UTF-8');
    clevo_stmt($db,'UPDATE support_tickets SET status=?,message=? WHERE id=?','ssi',[$admin?'Replied':'New',$legacy,$id]);
    if($admin&&(int)$ticket['user_id']>0)clevoCreateNotification($db,(int)$ticket['user_id'],'رد على طلب الدعم','وصل رد جديد على طلب الدعم.','info','support.php?ticket_id='.$id,'Support reply','A reply to your support request is available.');
    if($admin&&(int)$ticket['is_guest']===1&&$ticket['guest_email'])clevoSendGuestSupportReplyEmail($ticket['guest_email'],$ticket['user_name'],$ticket['subject'],$message);
    clevo_db_audit($db,$user,'support.replied','support_tickets',$id);
}
