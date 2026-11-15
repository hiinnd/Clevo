<?php
declare(strict_types=1);

// Read-only inspection. Output contains schema metadata/counts, never row values.
function clevo_database_preflight(mysqli $conn): array
{
    $blockers = []; $warnings = [];
    $checks = [
        'duplicate_wallet_users' => 'SELECT COUNT(*) n FROM (SELECT UserID FROM wallet WHERE UserID IS NOT NULL GROUP BY UserID HAVING COUNT(*) > 1) d',
        'duplicate_order_numbers' => 'SELECT COUNT(*) n FROM (SELECT Order_Number FROM booking WHERE Order_Number IS NOT NULL GROUP BY Order_Number HAVING COUNT(*) > 1) d',
        'orphan_message_senders' => 'SELECT COUNT(*) n FROM support_messages m LEFT JOIN user u ON u.UserID=m.SenderID WHERE m.SenderID IS NOT NULL AND u.UserID IS NULL',
        'non_transactional_tables' => "SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE <> 'InnoDB'",
    ];
    foreach ($checks as $name => $sql) {
        $n = (int) $conn->query($sql)->fetch_assoc()['n'];
        if ($n) $blockers[$name] = $n;
    }
    $checks = [
        'cross_station_drivers' => 'SELECT COUNT(*) n FROM booking b JOIN user d ON d.UserID=b.DriverID WHERE d.StationID IS NULL OR d.StationID<>b.StationID OR LOWER(d.Role)<>\'driver\'',
        'cross_station_services' => 'SELECT COUNT(*) n FROM booking b JOIN services s ON s.ServiceID=b.ServiceID WHERE s.StationID<>b.StationID',
        'cross_user_plans' => 'SELECT COUNT(*) n FROM booking b JOIN user_plans p ON p.id=b.UserPlanID WHERE p.user_id<>b.UserID',
        'station_owners_with_wrong_role' => "SELECT COUNT(*) n FROM stations s JOIN user u ON u.UserID=s.UserID WHERE LOWER(u.Role)<>'owner' OR u.Role IS NULL",
        'drivers_without_station' => "SELECT COUNT(*) n FROM user WHERE LOWER(Role)='driver' AND StationID IS NULL",
        'cross_station_service_items' => 'SELECT COUNT(*) n FROM booking_services i JOIN booking b ON b.BookingID=i.BookingID JOIN services s ON s.ServiceID=i.ServiceID WHERE s.StationID<>b.StationID',
        'unattributed_wallet_entries' => 'SELECT COUNT(*) n FROM wallet_transactions WHERE WalletID IS NULL',
        'negative_wallets' => 'SELECT COUNT(*) n FROM wallet WHERE Balance<0',
        'negative_loyalty_buckets' => 'SELECT COUNT(*) n FROM loyalty WHERE PointsBalance<0',
        'negative_plan_washes' => 'SELECT COUNT(*) n FROM user_plans WHERE remaining_washes<0',
        'unknown_roles' => "SELECT COUNT(*) n FROM user WHERE Role IS NULL OR LOWER(Role) NOT IN ('user','customer','owner','driver','admin')",
        'nullable_wallet_owners' => 'SELECT COUNT(*) n FROM wallet WHERE UserID IS NULL',
    ];
    foreach ($checks as $name => $sql) {
        $n = (int) $conn->query($sql)->fetch_assoc()['n'];
        if ($n) $warnings[$name] = $n;
    }
    $foreignKeys = $conn->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetch_all(MYSQLI_ASSOC);
    foreach ($foreignKeys as $fk) {
        $quote = static fn(string $s): string => '`' . str_replace('`', '``', $s) . '`';
        $table=$quote($fk['TABLE_NAME']); $column=$quote($fk['COLUMN_NAME']); $parent=$quote($fk['REFERENCED_TABLE_NAME']); $pk=$quote($fk['REFERENCED_COLUMN_NAME']);
        $n=(int)$conn->query("SELECT COUNT(*) n FROM $table c LEFT JOIN $parent p ON c.$column=p.$pk WHERE c.$column IS NOT NULL AND p.$pk IS NULL")->fetch_assoc()['n'];
        if ($n) $blockers['orphan_' . $fk['TABLE_NAME'] . '_' . $fk['COLUMN_NAME']]=$n;
    }
    // Inspect missing expected relationships too; a disabled/absent FK must not hide orphans.
    $required=file_get_contents(__DIR__.'/../migrations/003_required_relationships.sql');
    preg_match_all('/ALTER TABLE `([^`]+)` ADD CONSTRAINT `[^`]+` FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)` \(`([^`]+)`\)/',$required,$matches,PREG_SET_ORDER);
    foreach ($matches as $fk) {
        $n=(int)$conn->query("SELECT COUNT(*) n FROM `$fk[1]` c LEFT JOIN `$fk[3]` p ON c.`$fk[2]`=p.`$fk[4]` WHERE c.`$fk[2]` IS NOT NULL AND p.`$fk[4]` IS NULL")->fetch_assoc()['n'];
        if ($n) $blockers['orphan_'.$fk[1].'_'.$fk[2]]=$n;
    }
    return ['blockers'=>$blockers,'warnings'=>$warnings];
}
