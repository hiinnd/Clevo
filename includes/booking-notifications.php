<?php
declare(strict_types=1);

function clevo_queue_notification(mysqli $db, ?int $user, ?string $role, string $title, string $body, string $type, ?string $url, string $titleEn, string $bodyEn): bool
{
    clevo_stmt($db,'INSERT INTO notification_outbox (UserID,TargetRole,Title,TitleEn,Body,BodyEn,Type,TargetUrl) VALUES (?,?,?,?,?,?,?,?)','isssssss',[$user,$role,$title,$titleEn,$body,$bodyEn,$type,$url]);
    return true;
}

function clevo_flush_notifications(mysqli $db, int $limit = 50): int
{
    static $running;
    $running ??= new WeakMap();
    $depths = &clevo_db_depths();
    if (($depths[$db] ?? 0) > 0 || ($running[$db] ?? false)) return 0;
    $running[$db] = true;
    $delivered = 0;
    try {
        $limit = max(1,min(200,$limit));
        for ($i=0;$i<$limit;$i++) {
            $sent=clevo_db_atomic($db,function()use($db):bool {
                // The notification INSERT and delivery marker commit together. Another worker
                // rechecks this current locked row, so a crash/retry cannot duplicate delivery.
                $row=clevo_stmt($db,'SELECT * FROM notification_outbox WHERE DeliveredAt IS NULL ORDER BY OutboxID LIMIT 1 FOR UPDATE')->get_result()->fetch_assoc();
                if (!$row) return false;
                clevo_stmt($db,'INSERT INTO notifications (user_id,target_role,title,title_en,body,body_en,type,target_url) VALUES (?,?,?,?,?,?,?,?)','isssssss',[$row['UserID'],$row['TargetRole'],$row['Title'],$row['TitleEn'],$row['Body'],$row['BodyEn'],$row['Type'],$row['TargetUrl']]);
                $id=(int)$db->insert_id;
                clevo_stmt($db,'UPDATE notification_outbox SET DeliveredAt=UTC_TIMESTAMP(6),NotificationID=? WHERE OutboxID=? AND DeliveredAt IS NULL','ii',[$id,(int)$row['OutboxID']]);
                return true;
            });
            if (!$sent) break;
            $delivered++;
        }
        return $delivered;
    } finally { $running[$db]=false; }
}
