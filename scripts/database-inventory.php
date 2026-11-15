<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/security/environment.php';
try {
    clevo_load_environment(); clevo_validate_environment(); $conn=clevo_connect_database();
    $inventory=['engine'=>$conn->server_info,'session_timezone'=>$conn->query('SELECT @@session.time_zone n')->fetch_assoc()['n'],'session_sql_mode'=>$conn->query('SELECT @@session.sql_mode n')->fetch_assoc()['n']];
    $queries=[
        'tables'=>"SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME",
        'columns'=>'SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION',
        'indexes'=>'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',
        'relationships'=>'SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION',
    ];
    foreach($queries as $name=>$sql) $inventory[$name]=$conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    foreach($inventory['columns'] as &$column) if($column['COLUMN_DEFAULT']!==null&&preg_match('/password|secret|token|otp|accesscode|cipher/i',$column['COLUMN_NAME'])) $column['COLUMN_DEFAULT']='[redacted]';
    unset($column);
    echo json_encode($inventory,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) { fwrite(STDERR,"Schema inventory failed. No data values emitted.\n"); exit(1); }
