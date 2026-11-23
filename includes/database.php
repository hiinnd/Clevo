<?php
declare(strict_types=1);

// Domain transaction nesting uses savepoints; no inner operation commits its caller.
function &clevo_db_depths(): WeakMap { static $map; $map ??= new WeakMap(); return $map; }
function clevo_db_begin(mysqli $conn): void
{
    $map=&clevo_db_depths(); $depth=$map[$conn]??0;
    if ($depth===0) $conn->begin_transaction(); else $conn->query('SAVEPOINT clevo_domain_'.$depth);
    $map[$conn]=$depth+1;
}
function clevo_db_commit(mysqli $conn): void
{
    $map=&clevo_db_depths(); $depth=($map[$conn]??0)-1;
    if ($depth<0) throw new LogicException('No domain transaction.');
    if ($depth===0) $conn->commit(); else $conn->query('RELEASE SAVEPOINT clevo_domain_'.$depth);
    $map[$conn]=$depth;
    if ($depth===0 && function_exists('clevo_flush_notifications') && !defined('CLEVO_NOTIFICATION_WORKER')) {
        try { clevo_flush_notifications($conn); }
        catch (Throwable $e) { error_log('CLEVO notification outbox pending: '.get_class($e)); }
    }
}
function clevo_db_rollback(mysqli $conn): void
{
    $map=&clevo_db_depths(); $depth=($map[$conn]??0)-1;
    if ($depth<0) return;
    if ($depth===0) $conn->rollback(); else $conn->query('ROLLBACK TO SAVEPOINT clevo_domain_'.$depth);
    $map[$conn]=$depth;
}
function clevo_db_atomic(mysqli $conn, callable $work): mixed
{
    $map=&clevo_db_depths(); $outer=($map[$conn]??0)===0;
    for ($attempt=0;;$attempt++) {
        clevo_db_begin($conn);
        try { $result=$work(); clevo_db_commit($conn); return $result; }
        catch (Throwable $e) {
            // InnoDB deadlocks roll back the entire transaction, including savepoints.
            if ((int)$e->getCode()===1213) { $map[$conn]=0; } else clevo_db_rollback($conn);
            if (!$outer || !in_array((int)$e->getCode(),[1205,1213],true) || $attempt>=2) throw $e;
            usleep(random_int(10000,50000));
        }
    }
}
function clevo_db_money(mixed $value): int
{
    $s=is_float($value)?number_format($value,2,'.',''):(string)$value;
    if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D',$s,$m)) throw new ClevoSecurityException('invalid_amount',422);
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}
function clevo_db_decimal(int $cents): string { return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
function clevo_db_audit(mysqli $conn, ?int $actor, string $action, string $entity, int $id): void
{
    // Intentionally contains no request bodies, secrets, coordinates or financial/PII payload.
    clevo_stmt($conn,'INSERT INTO audit_logs (ActorID,Action,EntityType,EntityID) VALUES (?,?,?,?)','issi',[$actor,$action,$entity,$id]);
}
function clevo_db_effect(mysqli $conn, int $booking, string $effect): bool
{
    try { clevo_stmt($conn,'INSERT INTO booking_effects (BookingID,Effect) VALUES (?,?)','is',[$booking,$effect]); return true; }
    catch(mysqli_sql_exception $e) { if((int)$e->getCode()===1062) return false; throw $e; }
}
function clevo_db_idempotent(mysqli $conn,int $user,string $scope,string $key,array $body,callable $work): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$key)) throw new ClevoSecurityException('idempotency_key_required',422);
    $keyHash=hash('sha256',$key); $legacyHash=hash('sha256',json_encode($body,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    $requestHash=clevo_db_request_hash($legacyHash);
    return clevo_db_atomic($conn,function()use($conn,$user,$scope,$keyHash,$requestHash,$work):array {
        clevo_stmt($conn,'INSERT INTO request_idempotency (UserID,Scope,KeyHash,RequestHash,RequestHashVersion) VALUES (?,?,?,?,2) ON DUPLICATE KEY UPDATE KeyHash=VALUES(KeyHash)','isss',[$user,$scope,$keyHash,$requestHash]);
        $row=clevo_stmt($conn,'SELECT RequestHash,RequestHashVersion,ResponseJson FROM request_idempotency WHERE UserID=? AND Scope=? AND KeyHash=? FOR UPDATE','iss',[$user,$scope,$keyHash])->get_result()->fetch_assoc();
        if((int)$row['RequestHashVersion']===1){
            // Upgrade a late legacy writer without re-executing the committed operation.
            $row['RequestHash']=clevo_db_request_hash($row['RequestHash']);
            clevo_stmt($conn,'UPDATE request_idempotency SET RequestHash=?,RequestHashVersion=2 WHERE UserID=? AND Scope=? AND KeyHash=?','siss',[$row['RequestHash'],$user,$scope,$keyHash]);
        }elseif((int)$row['RequestHashVersion']!==2)throw new RuntimeException('Unsupported request digest version.');
        if (!hash_equals($row['RequestHash'],$requestHash)) throw new ClevoSecurityException('idempotency_conflict',409);
        if ($row['ResponseJson']!==null) return json_decode($row['ResponseJson'],true,512,JSON_THROW_ON_ERROR);
        $result=$work();
        clevo_stmt($conn,'UPDATE request_idempotency SET ResponseJson=? WHERE UserID=? AND Scope=? AND KeyHash=?','siss',[json_encode($result,JSON_THROW_ON_ERROR),$user,$scope,$keyHash]);
        return $result;
    });
}

function clevo_db_request_hash(string $legacyDigest):string
{
    return hash_hmac('sha256','clevo.idempotency.v2:'.$legacyDigest,clevo_secret('APP_KEY'));
}

/** Called by the protected migration runner with all application writers drained. */
function clevo_db_harden_idempotency(mysqli $conn):void
{
    do{
        $count=clevo_db_atomic($conn,function()use($conn):int{
            $rows=clevo_stmt($conn,'SELECT UserID,Scope,KeyHash,RequestHash FROM request_idempotency WHERE RequestHashVersion=1 LIMIT 500 FOR UPDATE')->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach($rows as $row)clevo_stmt($conn,'UPDATE request_idempotency SET RequestHash=?,RequestHashVersion=2 WHERE UserID=? AND Scope=? AND KeyHash=? AND RequestHashVersion=1','siss',[clevo_db_request_hash($row['RequestHash']),(int)$row['UserID'],$row['Scope'],$row['KeyHash']]);
            return count($rows);
        });
    }while($count>0);
}
