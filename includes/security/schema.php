<?php
declare(strict_types=1);
function clevo_require_schema(mysqli $conn, string $table, array $columns = [], array $indexes = []): void
{
    static $checked = [];
    $key = spl_object_id($conn) . ':' . $table . ':' . implode(',', $columns) . ':' . implode(',', $indexes);
    if (isset($checked[$key])) return;
    $result = clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', 's', [$table])->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$result || array_diff($columns, array_column($result, 'COLUMN_NAME'))) throw new RuntimeException('Deployment migration required.');
    foreach ($indexes as $index) {
        if (!clevo_stmt($conn, 'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1', 'ss', [$table, $index])->get_result()->fetch_assoc()) throw new RuntimeException('Deployment migration required.');
    }
    $checked[$key] = true;
}
