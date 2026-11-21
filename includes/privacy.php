<?php
declare(strict_types=1);

function clevo_legal_metadata():array
{
    $operator=trim(clevo_env('LEGAL_OPERATOR_NAME','')??'');$contact=trim(clevo_env('PRIVACY_CONTACT_EMAIL','')??'');
    $approved=clevo_env('LEGAL_POLICY_APPROVED','0')==='1'&&$operator!==''&&filter_var($contact,FILTER_VALIDATE_EMAIL)&&trim(clevo_env('LEGAL_POLICY_VERSION','')??'')!=='';
    return ['approved'=>(bool)$approved,'operator'=>$operator,'support_email'=>clevo_env('SUPPORT_EMAIL','')??'','privacy_email'=>$contact,
        'version'=>clevo_env('LEGAL_POLICY_VERSION','')??'','processing_days'=>(int)clevo_env('ACCOUNT_DELETION_PROCESSING_DAYS','0'),
        'retention_description_ar'=>clevo_env('RETENTION_DESCRIPTION_AR','')??'','retention_description_en'=>clevo_env('RETENTION_DESCRIPTION_EN','')??'',
        'deletion_enabled'=>clevo_env('ACCOUNT_DELETION_ENABLED','0')==='1','privacy_url'=>clevo_public_url('general/privacy.php'),
        'terms_url'=>clevo_public_url('general/terms.php'),'deletion_url'=>clevo_public_url('general/delete-account.php'),'support_url'=>clevo_public_url('general/contact.php')];
}
function clevo_privacy_schema(mysqli $db):void
{
    if(clevo_env('ACCOUNT_DELETION_ENABLED','0')!=='1')throw new ClevoSecurityException('account_deletion_unavailable',503);
    clevo_require_schema($db,'account_deletion_requests',['RequestID','UserID','State']);
}
function clevo_privacy_reauthenticate(mysqli $db,int $user,string $password,bool $requireActive=true):array
{
    $account=clevo_account($db,$user,true);
    if(!$account||($requireActive&&!clevo_account_active($db,$account))||strlen($password)>72||!password_verify($password,$account['Password']))throw new ClevoSecurityException('reauthentication_failed',401);
    return $account;
}
function clevo_privacy_read(mysqli $db,int $user):?array
{
    clevo_privacy_schema($db);
    return clevo_stmt($db,'SELECT RequestID,State,RequestedAt,UpdatedAt,CompletedAt,PolicyVersion FROM account_deletion_requests WHERE UserID=?','i',[$user])->get_result()->fetch_assoc();
}
function clevo_privacy_request(mysqli $db,int $user,string $password,bool $cancel=false):array
{
    clevo_privacy_schema($db);
    return clevo_db_atomic($db,function()use($db,$user,$password,$cancel):array{
        $account=clevo_privacy_reauthenticate($db,$user,$password,false);
        if(clevo_role($account['Role'])==='admin')throw new ClevoSecurityException('administrator_review_required',409);
        $old=clevo_stmt($db,'SELECT RequestID,State FROM account_deletion_requests WHERE UserID=? FOR UPDATE','i',[$user])->get_result()->fetch_assoc();
        if($old&&in_array($old['State'],['processing','completed'],true))throw new ClevoSecurityException('erasure_already_processing',409);
        if($cancel){
            if(!$old)throw new ClevoSecurityException('not_found',404);
            clevo_stmt($db,"UPDATE account_deletion_requests SET State='cancelled' WHERE RequestID=?",'i',[(int)$old['RequestID']]);
            clevo_db_audit($db,$user,'privacy.request_cancelled','account_deletion_requests',(int)$old['RequestID']);
        }elseif(!$old||$old['State']==='cancelled'){
            clevo_stmt($db,"INSERT INTO account_deletion_requests (UserID) VALUES (?) ON DUPLICATE KEY UPDATE State='requested',RequestedAt=UTC_TIMESTAMP(6),CompletedAt=NULL,ReviewedBy=NULL,ReviewReference=NULL,PolicyVersion=NULL",'i',[$user]);
            clevo_db_audit($db,$user,'privacy.requested','user',$user);
        }
        return clevo_privacy_read($db,$user)??[];
    });
}
function clevo_privacy_fulfill(mysqli $db,int $admin,int $request,array $input):array
{
    clevo_privacy_schema($db);clevo_admin_actor($db,$admin);
    $policy=clevo_legal_metadata();
    if(!$policy['approved']||clevo_env('ERASURE_RETENTION_REVIEW_APPROVED','0')!=='1'||!$policy['retention_description_ar']||!$policy['retention_description_en'])throw new ClevoSecurityException('privacy_policy_not_approved',503);
    if(clevo_env('ERASURE_WORKER_FENCE','0')!=='1')throw new ClevoSecurityException('outbound_workers_must_be_drained',409);
    if(($input['retention_reviewed']??false)!==true||($input['external_cleanup_reviewed']??false)!==true)throw new ClevoSecurityException('privacy_review_required',422);
    return clevo_db_atomic($db,function()use($db,$admin,$request,$input,$policy):array{
        if(clevo_role(clevo_privacy_reauthenticate($db,$admin,$input['password'])['Role'])!=='admin')throw new ClevoSecurityException('forbidden',403);
        $r=clevo_stmt($db,'SELECT * FROM account_deletion_requests WHERE RequestID=? FOR UPDATE','i',[$request])->get_result()->fetch_assoc();
        if(!$r)throw new ClevoSecurityException('not_found',404);
        if($r['State']!=='requested')throw new ClevoSecurityException('erasure_state_conflict',409);
        $id=(int)$r['UserID'];$a=clevo_account($db,$id,true);
        if(!$a||clevo_role($a['Role'])==='admin')throw new ClevoSecurityException('administrator_review_required',409);
        if(clevo_stmt($db,"SELECT DeliveryID FROM notification_deliveries WHERE UserID=? AND State='sending' AND LeaseUntil>UTC_TIMESTAMP(6) LIMIT 1 FOR UPDATE",'i',[$id])->get_result()->fetch_assoc()||clevo_stmt($db,'SELECT ID FROM security_mail_queue WHERE UserID=? AND LeaseUntil>UTC_TIMESTAMP(6) LIMIT 1 FOR UPDATE','i',[$id])->get_result()->fetch_assoc())throw new ClevoSecurityException('outbound_delivery_in_progress',409);
        $active=clevo_stmt($db,"SELECT BookingID FROM booking WHERE (UserID=? OR DriverID=? OR OwnerIDSnapshot=?) AND Status IN ('Pending','Accepted','Arrived','In Progress','Pickup','Washing','Returning') LIMIT 1 FOR UPDATE",'iii',[$id,$id,$id])->get_result()->fetch_assoc();
        if($active)throw new ClevoSecurityException('active_work_requires_resolution',409);
        if(clevo_stmt($db,'SELECT StationID FROM stations WHERE UserID=? AND ArchivedAt IS NULL LIMIT 1 FOR UPDATE','i',[$id])->get_result()->fetch_assoc())throw new ClevoSecurityException('owner_station_review_required',409);
        if(clevo_stmt($db,"SELECT OrderID FROM payment_orders WHERE UserID=? AND State IN ('prepared','creating','pending','unknown','refund_due') LIMIT 1 FOR UPDATE",'i',[$id])->get_result()->fetch_assoc())throw new ClevoSecurityException('financial_review_required',409);
        if(clevo_stmt($db,"SELECT id FROM user_plans WHERE user_id=? AND status='active' LIMIT 1 FOR UPDATE",'i',[$id])->get_result()->fetch_assoc())throw new ClevoSecurityException('subscription_review_required',409);
        if(clevo_stmt($db,"SELECT r.RefundID FROM provider_refunds r JOIN payment_orders o ON o.OrderID=r.OrderID WHERE o.UserID=? AND r.State IN ('requested','submitting','pending','unknown') LIMIT 1 FOR UPDATE",'i',[$id])->get_result()->fetch_assoc())throw new ClevoSecurityException('financial_review_required',409);
        $profile=clevo_stmt($db,'SELECT ProfilePic FROM user WHERE UserID=?','i',[$id])->get_result()->fetch_assoc()['ProfilePic']??'';
        if($profile&&$profile!=='default_user.png'){
            if(!preg_match('/^private:profiles:[a-f0-9]{48}\.(?:jpg|png|gif|webp)$/D',$profile))throw new ClevoSecurityException('legacy_media_review_required',409);
            if(clevo_stmt($db,'SELECT UserID FROM user WHERE ProfilePic=? AND UserID<>? LIMIT 1','si',[$profile,$id])->get_result()->fetch_assoc())throw new ClevoSecurityException('shared_media_review_required',409);
            clevo_stmt($db,'INSERT INTO account_erasure_files (RequestID,StorageReference) VALUES (?,?)','is',[$request,$profile]);
        }
        // Bound and privately inspect older encrypted queue entries, never print their content.
        $legacy=clevo_stmt($db,"SELECT ID,PayloadCipher,(LeaseUntil>UTC_TIMESTAMP(6)) ActiveLease FROM security_mail_queue WHERE UserID IS NULL AND PayloadCipher<>'' AND SentAt IS NULL ORDER BY ID LIMIT 501 FOR UPDATE")->get_result()->fetch_all(MYSQLI_ASSOC);
        if(count($legacy)>500)throw new ClevoSecurityException('legacy_mail_review_required',409);
        foreach($legacy as $message){
            try{$recipient=clevo_decrypt_mail($message['PayloadCipher'])['to']??'';}catch(Throwable){throw new ClevoSecurityException('legacy_mail_review_required',409);}
            if(in_array(strtolower($recipient),array_filter([strtolower($a['Email']??''),strtolower($a['PendingEmail']??'')]),true)){
                if($message['ActiveLease'])throw new ClevoSecurityException('outbound_delivery_in_progress',409);
                clevo_stmt($db,"UPDATE security_mail_queue SET UserID=?,PayloadCipher='',Attempts=5,LastError='account_erased',Claim=NULL,LeaseUntil=NULL WHERE ID=?",'ii',[$id,(int)$message['ID']]);
            }
        }
        clevo_revoke_user($db,$id);
        clevo_stmt($db,'UPDATE auth_challenges SET TargetEmail=NULL WHERE UserID=?','i',[$id]);
        clevo_stmt($db,"UPDATE security_mail_queue SET PayloadCipher='',Attempts=5,LastError='account_erased',Claim=NULL,LeaseUntil=NULL WHERE UserID=?",'i',[$id]);
        clevo_stmt($db,"UPDATE mobile_devices SET TokenCipher=NULL,RevokedAt=COALESCE(RevokedAt,UTC_TIMESTAMP(6)) WHERE UserID=?",'i',[$id]);
        clevo_stmt($db,"UPDATE notification_deliveries SET State='skipped',Claim=NULL,LeaseUntil=NULL,LastError='account_erased' WHERE UserID=? AND State IN ('queued','sending')",'i',[$id]);
        clevo_stmt($db,"UPDATE notifications SET title='Account erased',title_en='Account erased',body=NULL,body_en=NULL,target_url=NULL,is_read=1 WHERE user_id=?",'i',[$id]);
        clevo_stmt($db,"UPDATE saved_addresses SET Label='Erased',AddressText='Erased',Latitude=0,Longitude=0,IsDefault=0,ArchivedAt=COALESCE(ArchivedAt,UTC_TIMESTAMP(6)) WHERE UserID=?",'i',[$id]);
        // Preserve commercial/dispute evidence under the reviewed policy; remove ordinary support content.
        clevo_stmt($db,"UPDATE support_messages m JOIN support_tickets t ON t.id=m.TicketID SET m.Message='Erased' WHERE t.user_id=? AND t.Category='support' AND t.BookingID IS NULL",'i',[$id]);
        clevo_stmt($db,"UPDATE support_tickets SET user_name='Erased',guest_email=NULL,subject=IF(Category='support' AND BookingID IS NULL,'Erased',subject),message=IF(Category='support' AND BookingID IS NULL,'Erased',message) WHERE user_id=?",'i',[$id]);
        clevo_stmt($db,'UPDATE reviews r JOIN booking b ON b.BookingID=r.BookingID SET r.Comment=NULL WHERE b.UserID=?','i',[$id]);
        clevo_stmt($db,'UPDATE reviews r JOIN booking b ON b.BookingID=r.BookingID JOIN stations s ON s.StationID=b.StationID SET r.Owner_Reply=NULL WHERE s.UserID=?','i',[$id]);
        clevo_stmt($db,'UPDATE booking SET Latitude=NULL,Longitude=NULL,Address_Details=NULL WHERE UserID=?','i',[$id]);
        clevo_stmt($db,'DELETE tr FROM tracking_records tr JOIN booking b ON b.BookingID=tr.BookingID WHERE tr.DriverID=? OR b.UserID=?','ii',[$id,$id]);
        clevo_stmt($db,"UPDATE user SET UserName='Erased account',Email=NULL,PhoneNumber=?,Password=?,AccountStatus='rejected',EmailVerifiedAt=NULL,PendingEmail=NULL,CarName=NULL,ProfilePic=NULL,DeviceID=NULL,CurrentAccessCode=NULL,reset_otp=NULL,reset_otp_expiry=NULL,VerificationToken=NULL,LastLatitude=NULL,LastLongitude=NULL,LastAddress=NULL,LastLocationUrl=NULL,LastLocationUpdatedAt=NULL,Last_Login=NULL WHERE UserID=?",'ssi',['erased-'.$id,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$id]);
        clevo_stmt($db,"UPDATE account_deletion_requests SET State=?,ReviewedBy=?,ReviewReference=?,PolicyVersion=?,CompletedAt=IF(?='completed',UTC_TIMESTAMP(6),NULL) WHERE RequestID=?",'sisssi',[$profile&&$profile!=='default_user.png'?'processing':'completed',$admin,$input['review_reference'],$policy['version'],$profile&&$profile!=='default_user.png'?'processing':'completed',$request]);
        clevo_db_audit($db,$admin,'privacy.personal_data_erased','account_deletion_requests',$request);
        return ['request_id'=>$request,'state'=>$profile&&$profile!=='default_user.png'?'processing':'completed'];
    });
}
function clevo_privacy_cleanup_files(mysqli $db,int $limit=50):array
{
    clevo_privacy_schema($db);
    if(clevo_env('ERASURE_RETENTION_REVIEW_APPROVED','0')!=='1')throw new ClevoSecurityException('privacy_policy_not_approved',503);
    $count=0;
    foreach(clevo_stmt($db,"SELECT f.* FROM account_erasure_files f JOIN account_deletion_requests r ON r.RequestID=f.RequestID WHERE f.State='queued' AND r.State='processing' ORDER BY f.FileID LIMIT ?",'i',[max(1,min(100,$limit))])->get_result()->fetch_all(MYSQLI_ASSOC) as $row){
        if(!preg_match('/^private:profiles:([a-f0-9]{48}\.(?:jpg|png|gif|webp))$/D',$row['StorageReference'],$m))throw new RuntimeException('Unsafe erasure reference.');
        if(clevo_stmt($db,'SELECT UserID FROM user WHERE ProfilePic=? LIMIT 1','s',[$row['StorageReference']])->get_result()->fetch_assoc())throw new RuntimeException('Referenced profile file requires review.');
        $root=realpath(rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/profiles');
        if(!$root)throw new RuntimeException('Profile storage unavailable.');
        $path=$root.DIRECTORY_SEPARATOR.$m[1];
        if(is_link($path))throw new RuntimeException('Symlink erasure forbidden.');
        if(file_exists($path)&&(!is_file($path)||!clevo_path_within(realpath($path)?:'',$root)||!unlink($path)))throw new RuntimeException('Profile erasure pending.');
        clevo_stmt($db,"UPDATE account_erasure_files SET State='deleted',Attempts=Attempts+1 WHERE FileID=?",'i',[(int)$row['FileID']]);$count++;
    }
    clevo_stmt($db,"UPDATE account_deletion_requests r SET State='completed',CompletedAt=UTC_TIMESTAMP(6) WHERE State='processing' AND NOT EXISTS(SELECT 1 FROM account_erasure_files f WHERE f.RequestID=r.RequestID AND f.State<>'deleted')");
    return ['files_erased'=>$count];
}
