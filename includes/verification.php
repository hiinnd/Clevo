<?php
declare(strict_types=1);

/** Resolve database-owned references only; never accept request paths. */
function clevo_verification_file(string $reference):string
{
    if(preg_match('/^private:verification:([a-f0-9]{48}\.(?:jpg|png|gif|webp|pdf))$/D',$reference,$m)){
        $file=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/verification/'.$m[1];
    }elseif($reference!==''&&!str_starts_with($reference,'private:')){
        $file=dirname(__DIR__).'/uploads/verification/'.basename($reference);
    }else throw new ClevoSecurityException('verification_document_required',409);
    if(!is_file($file)||is_link($file))throw new ClevoSecurityException('verification_document_required',409);
    if(!in_array((new finfo(FILEINFO_MIME_TYPE))->file($file),['image/jpeg','image/png','image/gif','image/webp','application/pdf'],true))throw new ClevoSecurityException('verification_document_required',409);
    return $file;
}

function clevo_station_verification_reference(mysqli $db,int $station):string
{
    $r=clevo_stmt($db,"SELECT COALESCE(NULLIF(s.CommercialRecordPath,''),u.VerificationFile) ref FROM stations s JOIN user u ON u.UserID=s.UserID WHERE s.StationID=? AND s.ArchivedAt IS NULL",'i',[$station])->get_result()->fetch_assoc();
    if(!$r)throw new ClevoSecurityException('not_found',404);return (string)$r['ref'];
}
