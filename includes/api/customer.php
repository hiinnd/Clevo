<?php
declare(strict_types=1);

function clevo_api_profile(mysqli $db,int $user):array
{
    $r=clevo_stmt($db,'SELECT UserID,UserName,Role,Email,PendingEmail,PhoneNumber,CarName,ProfilePic,EmailVerifiedAt FROM user WHERE UserID=?','i',[$user])->get_result()->fetch_assoc();
    return ['id'=>(int)$r['UserID'],'name'=>$r['UserName'],'role'=>clevo_role($r['Role']),'email'=>$r['Email'],'pending_email'=>$r['PendingEmail'],'phone'=>$r['PhoneNumber'],'car_name'=>$r['CarName'],'profile_image'=>clevo_api_image($r['ProfilePic'],$r['UserName']),'email_verified'=>$r['EmailVerifiedAt']!==null];
}
function clevo_api_ticket(mysqli $db,int $user,int $id,bool $admin=false,bool $lock=false):array
{
    return clevo_api_require_row(clevo_stmt($db,'SELECT id,user_id,user_name,user_type,subject,message,status,is_guest,guest_email,created_at,Category,BookingID FROM support_tickets WHERE id=?'.($admin?'':' AND user_id=?').($lock?' FOR UPDATE':''),$admin?'i':'ii',$admin?[$id]:[$id,$user])->get_result()->fetch_assoc());
}
function clevo_api_ticket_dto(array $r):array {return array_intersect_key($r,array_flip(['id','subject','status','created_at','Category','BookingID']));}
function clevo_market_support_create(mysqli $db,array $ctx,array $input):int
{
    $a=$ctx['account'];$user=(int)$a['UserID'];$category=$input['category']??'support';$booking=$input['booking_id']??null;
    if($category==='dispute'&&!$booking)throw new ClevoSecurityException('booking_required',422);
    if($booking)clevo_api_booking($db,$ctx,$booking);
    $role=clevo_role($a['Role']);$type=$role==='customer'?'user':($role==='admin'?'user':$role);
    clevo_stmt($db,'INSERT INTO support_tickets (user_id,user_name,user_type,subject,message,Category,BookingID) VALUES (?,?,?,?,?,?,?)','isssssi',[$user,$a['UserName'],$type,$input['subject'],$input['message'],$category,$booking]);$id=(int)$db->insert_id;
    if(!clevoAddSupportMessage($db,$id,'user',$user,$input['message']))throw new RuntimeException('Message unavailable.');
    clevoCreateRoleNotification($db,'Admin','طلب دعم جديد','وصل طلب دعم جديد.','info','all-support-chats.php','Support request','A support request was received.');return $id;
}
function clevo_market_support_reply(mysqli $db,int $user,int $id,string $message,bool $admin=false):void
{
    $ticket=clevo_api_ticket($db,$user,$id,$admin,true);
    if($ticket['status']==='Closed')throw new ClevoSecurityException('ticket_closed',409);
    if(!clevoAddSupportMessage($db,$id,$admin?'admin':'user',$user,$message))throw new RuntimeException('Message unavailable.');
    // Full content remains in support_messages; do not overflow the legacy TEXT preview/cache.
    $legacy=mb_strcut($ticket['message'].($admin?"\n\n--- Admin Reply ---\n":"\n\n--- User Reply ---\n").$message,0,60000,'UTF-8');
    clevo_stmt($db,'UPDATE support_tickets SET status=?,message=? WHERE id=?','ssi',[$admin?'Replied':'New',$legacy,$id]);
    if($admin&&(int)$ticket['user_id']>0)clevoCreateNotification($db,(int)$ticket['user_id'],'رد على طلب الدعم','وصل رد جديد على طلب الدعم.','info','support.php?ticket_id='.$id,'Support reply','A reply to your support request is available.');
    if($admin&&(int)$ticket['is_guest']===1&&$ticket['guest_email'])clevoSendGuestSupportReplyEmail($ticket['guest_email'],$ticket['user_name'],$ticket['subject'],$message);
    clevo_db_audit($db,$user,'support.replied','support_tickets',$id);
}
function clevo_api_customer(mysqli $db,string $action,array $ctx,array $args,array $input):never
{
    $a=$ctx['account'];$user=(int)$a['UserID'];$role=clevo_role($a['Role']);$id=(int)($args['id']??0);
    if($action==='profile')clevo_api_result(['user'=>clevo_api_profile($db,$user)]);
    if($action==='verification')clevo_api_verification($db,$user);
    if($action==='avatar'){
        $r=clevo_api_mutate($db,$ctx,'profile.avatar',$input,function()use($db,$user){$ref=clevo_save_upload($_FILES['avatar'],'profiles');clevo_stmt($db,'UPDATE user SET ProfilePic=? WHERE UserID=?','si',[$ref,$user]);return ['profile_image'=>clevo_media_url($ref)];});clevo_api_result($r);
    }
    if($action==='profile_update'){
        $r=clevo_api_mutate($db,$ctx,'profile.update',$input,function()use($db,$user,$input){clevo_market_profile($db,$user,$input);return ['user'=>clevo_api_profile($db,$user)];});clevo_api_result($r);
    }
    if($action==='addresses')clevo_api_result(['addresses'=>clevo_api_list($db,'AddressID,Label,AddressText,Latitude,Longitude,IsDefault,CreatedAt,UpdatedAt','FROM saved_addresses','UserID=? AND ArchivedAt IS NULL','i',[$user],['id'=>'AddressID','label'=>'Label'],'id','AddressID')]);
    if(str_starts_with($action,'address_')){
        $result=clevo_api_mutate($db,$ctx,$action,['id'=>$id]+$input,function()use($db,$user,$id,$input,$action):array{
            clevo_account($db,$user,true);
            if($id)clevo_api_require_row(clevo_stmt($db,'SELECT AddressID FROM saved_addresses WHERE AddressID=? AND UserID=? AND ArchivedAt IS NULL FOR UPDATE','ii',[$id,$user])->get_result()->fetch_assoc());
            if($action==='address_delete'){clevo_stmt($db,'UPDATE saved_addresses SET ArchivedAt=UTC_TIMESTAMP(6),IsDefault=0 WHERE AddressID=? AND UserID=?','ii',[$id,$user]);return ['address_id'=>$id,'archived'=>true];}
            if(!trim($input['label'])||!trim($input['address']))throw new ClevoSecurityException('invalid_address',422);
            $default=(int)($input['is_default']??false);if($default)clevo_stmt($db,'UPDATE saved_addresses SET IsDefault=0 WHERE UserID=? AND ArchivedAt IS NULL','i',[$user]);
            if($id)clevo_stmt($db,'UPDATE saved_addresses SET Label=?,AddressText=?,Latitude=?,Longitude=?,IsDefault=? WHERE AddressID=? AND UserID=?','ssddiii',[$input['label'],$input['address'],$input['latitude'],$input['longitude'],$default,$id,$user]);
            else{clevo_stmt($db,'INSERT INTO saved_addresses (UserID,Label,AddressText,Latitude,Longitude,IsDefault) VALUES (?,?,?,?,?,?)','issddi',[$user,$input['label'],$input['address'],$input['latitude'],$input['longitude'],$default]);$id=(int)$db->insert_id;}
            return ['address_id'=>$id];
        });clevo_api_result($result,$action==='address_create'?201:200);
    }
    if($action==='notification_read'){clevo_mark_notification($db,$user,$role,$id);clevo_api_result();}
    if($action==='notification_all'){
        clevo_db_atomic($db,function()use($db,$user,$role){clevo_stmt($db,'UPDATE notifications SET is_read=1 WHERE user_id=?','i',[$user]);clevo_stmt($db,'INSERT IGNORE INTO notification_reads (NotificationID,UserID) SELECT id,? FROM notifications WHERE user_id IS NULL AND LOWER(target_role)=?','is',[$user,$role]);});clevo_api_result();
    }
    if($action==='notifications'){
        $after=clevo_api_integer('after_id',0,0);
        $rows=clevo_api_list($db,'n.id,n.title,n.title_en,n.body,n.body_en,n.type,n.created_at,IF(n.user_id IS NULL,nr.ReadAt IS NOT NULL,n.is_read) is_read','FROM notifications n LEFT JOIN notification_reads nr ON nr.NotificationID=n.id AND nr.UserID='.(int)$user,'(n.user_id=? OR (n.user_id IS NULL AND LOWER(n.target_role)=?)) AND n.id>?','isi',[$user,$role,$after],['id'=>'n.id','created_at'=>'n.created_at'],'id','n.id');clevo_api_result(['notifications'=>$rows]);
    }
    if($action==='reviews')clevo_api_result(['reviews'=>clevo_api_list($db,'r.ReviewID,r.BookingID,r.Rating,r.Comment,r.Owner_Reply','FROM reviews r JOIN booking b ON b.BookingID=r.BookingID','b.UserID=?','i',[$user],['id'=>'r.ReviewID','rating'=>'r.Rating'],'id','r.ReviewID')]);
    if($action==='review'){
        clevo_api_booking($db,$ctx,$id);
        $r=clevo_api_mutate($db,$ctx,'review.create',['id'=>$id]+$input,fn()=>['review_id'=>clevo_market_review($db,$user,$id,$input['rating'],$input['comment']??'')]);clevo_api_result($r,201);
    }
    if($action==='support')clevo_api_result(['support_requests'=>array_map('clevo_api_ticket_dto',clevo_api_list($db,'id,subject,status,created_at,Category,BookingID','FROM support_tickets','user_id=?','i',[$user],['id'=>'id','created_at'=>'created_at'],'id','id'))]);
    if($action==='support_create'){$r=clevo_api_mutate($db,$ctx,'support.create',$input,fn()=>['ticket_id'=>clevo_market_support_create($db,$ctx,$input)]);clevo_api_result($r,201);}
    if(str_starts_with($action,'support_')){
        $t=clevo_api_ticket($db,$user,$id);
        if($action==='support_detail')clevo_api_result(['support_request'=>clevo_api_ticket_dto($t)]);
        if($action==='support_messages'){
            $rows=clevo_api_list($db,'MessageID,SenderRole,Message,CreatedAt','FROM support_messages','TicketID=?','i',[$id],['id'=>'MessageID','created_at'=>'CreatedAt'],'id','MessageID');
            if(!$rows&&empty($_GET['page']))$rows=clevoLegacyTicketMessages($t);clevo_api_result(['messages'=>$rows]);
        }
        $r=clevo_api_mutate($db,$ctx,'support.reply',['id'=>$id]+$input,function()use($db,$user,$id,$input){clevo_market_support_reply($db,$user,$id,$input['message']);return ['ticket_id'=>$id];});clevo_api_result($r,201);
    }
    throw new LogicException('Unimplemented customer route.');
}
