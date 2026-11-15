<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/security/environment.php';
try {
    clevo_load_environment(); clevo_validate_environment(); $conn=clevo_connect_database();
    if (!in_array('--empty-only',$argv,true)) throw new RuntimeException('Empty database acknowledgement required.');
    $n=(int)$conn->query('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetch_assoc()['n'];
    if ($n!==0) throw new RuntimeException('Refusing nonempty database.');
    $sql=file_get_contents(__DIR__.'/../migrations/000_legacy_schema.sql');
    $sql=preg_replace('/^\s*--[^\r\n]*/m','',$sql);
    foreach (array_filter(array_map('trim',explode(';',$sql))) as $statement) $conn->query($statement);
    echo "Empty database initialized with legacy schema, without seeds. Apply 001, 002 and 003 next.\n";
} catch(Throwable $e) { fwrite(STDERR,'Initialization refused/failed ('.get_class($e).', code '.(int)$e->getCode()."). No reset attempted.\n"); exit(1); }
