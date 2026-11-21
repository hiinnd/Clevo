<?php
declare(strict_types=1);
require __DIR__.'/admin-helpers.php';
$password='AdminPhase!'.ad_key();$admin=ad_actor('Admin',$password);$customer=ad_actor('customer',$password);$owner=ad_actor('owner',$password);$driver=ad_actor('driver',$password);
$station=ad_station($owner);clevo_stmt($db,'UPDATE user SET StationID=? WHERE UserID=?','ii',[$station,$driver]);
$service=clevo_market_service($db,$owner,$station,0,['name_ar'=>'غسيل اختبار','name_en'=>'Admin test wash','price'=>'10.25','duration'=>30,'is_station'=>true]);
$at=clevo_token_pair($db,clevo_account($db,$admin))['access_token'];$ct=clevo_token_pair($db,clevo_account($db,$customer))['access_token'];$ot=clevo_token_pair($db,clevo_account($db,$owner))['access_token'];$dt=clevo_token_pair($db,clevo_account($db,$driver))['access_token'];
[$jar,$csrf]=ad_login($admin,$password);check(strlen($csrf)===64,'Admin browser receives a CSRF token');
[$customerJar]=ad_login($customer,$password);[$ownerJar]=ad_login($owner,$password);[$driverJar]=ad_login($driver,$password);
$pages=['admin-dashboard.php','management.php','manage-users.php','manage-stations.php','manage-plans.php','station-approvals.php','financial-reports.php','security-center.php','all-support-chats.php','notifications.php'];
foreach($pages as $page){
    ad_expect(302,ad_http('GET','/admin/'.$page),'Unauthenticated page redirects '.$page);
    foreach([$customerJar,$ownerJar,$driverJar] as $restricted)ad_expect(403,ad_http('GET','/admin/'.$page,null,$restricted),'Non-admin denied '.$page);
    $render=ad_http('GET','/admin/'.$page,null,$jar);ad_expect(200,$render,'Admin page renders '.$page);check(ad_inline_check($render[2]),'Rendered JavaScript parses '.$page);
}
foreach(['bookings','people','services','subscriptions','refunds','provider_refunds','support','disputes','earnings','settlements','audit'] as $view){
    ad_expect(200,ad_http('GET','/admin/management.php?view='.$view,null,$jar),'Management view renders '.$view);
}
// The legacy page upload fallback must use the same audited profile service as the upload endpoint.
$avatar=__DIR__.'/../.security-test/admin-avatar.png';
file_put_contents($avatar,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9a0AAAAASUVORK5CYII='));
$upload=curl_init($base.'/admin/manage-users.php');
curl_setopt_array($upload,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEFILE=>$jar,CURLOPT_POSTFIELDS=>['csrf_token'=>$csrf,'admin_pic_upload'=>new CURLFile($avatar,'image/png','avatar.png')]]);
curl_exec($upload);$uploadStatus=(int)curl_getinfo($upload,CURLINFO_RESPONSE_CODE);curl_close($upload);
check($uploadStatus===302,'Legacy admin profile upload succeeds');
check(str_starts_with(clevo_stmt($db,'SELECT ProfilePic FROM user WHERE UserID=?','i',[$admin])->get_result()->fetch_assoc()['ProfilePic']??'','private:profiles:'),'Admin avatar is stored privately');
check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM audit_logs WHERE ActorID=? AND Action='admin.profile_image'",'i',[$admin])->get_result()->fetch_assoc()['n']===1,'Legacy admin upload records one canonical audit event');
$before=clevo_account($db,$customer)['AccountStatus'];
ad_expect(403,ad_http('POST','/admin/management.php?view=people',['action'=>'user_status','id'=>$customer,'status'=>'rejected','_key'=>ad_key()],$jar),'CSRF-less admin mutation denied');
check(clevo_account($db,$customer)['AccountStatus']===$before,'CSRF rejection leaves database unchanged');
ad_expect(422,ad_http('POST','/admin/management.php?view=services',['action'=>'service_update','id'=>[$service],'name_ar'=>'Invalid array','csrf_token'=>$csrf,'_key'=>ad_key()],$jar),'Array-valued form ID is rejected before mutation');
ad_expect(422,ad_submit('services','service_update',$service,['name_ar'=>'Invalid duration','name_en'=>'Invalid duration','price'=>'10.25','duration'=>'1oops','is_station'=>'on'],$jar,$csrf),'Malformed numeric form value cannot be truncated into a valid duration');
check(clevo_stmt($db,'SELECT Duration FROM services WHERE ServiceID=?','i',[$service])->get_result()->fetch_assoc()['Duration']===30,'Rejected malformed form preserves the service duration');
ad_expect(403,ad_submit('people','user_status',$customer,['status'=>'rejected'],$customerJar,$csrf),'Customer cannot forge an admin form');
ad_expect(405,ad_http('GET','/admin/export.php',null,$jar),'Export rejects GET requests');
ad_expect(403,ad_http('POST','/admin/export.php',['from'=>'2026-01-01','to'=>'2026-12-31','book'=>'cash'],$jar),'Export requires CSRF');
ad_expect(422,ad_http('GET','/admin/management.php?view=invalid',null,$jar),'View name is allowlisted');
ad_expect(422,ad_http('GET','/admin/financial-reports.php?from=2026-02-30',null,$jar),'Report rejects impossible date');
$search=ad_http('GET','/admin/management.php?view=people&query='.rawurlencode("' OR 1=1 --"),null,$jar);ad_expect(200,$search,'Search treats SQL metacharacters as data');
check(!str_contains($search[2],e(clevo_account($db,$customer)['Email'])),'Injected search does not broaden scope');
// User status changes immediately revoke mobile and browser access.
ad_expect(303,ad_submit('people','user_status',$customer,['status'=>'rejected'],$jar,$csrf),'Admin restricts customer');
ad_expect(401,ad_http('GET','/api/v1/me',null,null,$ct),'Restricted customer mobile token is rejected');
ad_expect(302,ad_http('GET','/user/profile.php',null,$customerJar),'Restricted browser session is rejected');
ad_submit('people','user_status',$customer,['status'=>'active'],$jar,$csrf);
ad_expect(401,ad_http('GET','/api/v1/me',null,null,$ct),'Restoration never revives revoked tokens');
$ct=clevo_token_pair($db,clevo_account($db,$customer))['access_token'];
[$customerJar]=ad_login($customer,$password);
ad_submit('people','user_status',$admin,['status'=>'rejected'],$jar,$csrf);check(clevo_account($db,$admin)['AccountStatus']==='active','Admin cannot self-restrict');
$unverified=ad_actor('customer',$password,false);clevo_stmt($db,"UPDATE user SET AccountStatus='pending' WHERE UserID=?",'i',[$unverified]);
ad_submit('people','user_status',$unverified,['status'=>'active'],$jar,$csrf);check(clevo_account($db,$unverified)['AccountStatus']==='pending','Unverified customer cannot be activated');
// Mobile creation and web cancellation share booking history, payment compensation and notifications.
$created=ad_expect(200,ad_http('POST','/api/v1/bookings',['station_id'=>$station,'service_ids'=>[$service],'service_location'=>'station','booking_date'=>(new DateTimeImmutable('+800 days'))->format('Y-m-d'),'booking_time'=>'12:00'],null,$ct),'Customer mobile API creates booking');
$booking=(int)($created['booking_id']??0);check($booking>0,'Mobile booking ID exists');
$rows=clevo_admin_rows($db,$admin,'bookings',1);check(in_array($booking,array_map('intval',array_column($rows['rows'],'BookingID')),true),'Mobile booking appears in admin database view');
ad_submit('bookings','booking_cancel',$booking,['reason'=>'Admin integration cancellation'],$jar,$csrf);
$visible=ad_expect(200,ad_http('GET','/api/v1/bookings/'.$booking,null,null,$ct),'Customer reads admin change');check(($visible['booking']['Status']??'')==='Cancelled','Admin cancellation is visible to customer API');
check((int)clevo_stmt($db,"SELECT COUNT(*) n FROM booking_status_history WHERE BookingID=? AND ToStatus='Cancelled'",'i',[$booking])->get_result()->fetch_assoc()['n']===1,'One canonical cancellation history event');
// Approval requires verified ownership plus a readable private commercial document.
$pendingOwner=ad_actor('owner',$password);$pending=ad_station($pendingOwner,'pending');
clevo_stmt($db,"UPDATE user SET AccountStatus='pending' WHERE UserID=?",'i',[$pendingOwner]);
ad_expect(302,ad_http('POST','/admin/station-approvals.php',['station_id'=>$pending,'action'=>'Approve','csrf_token'=>$csrf],$jar),'Approval without a document is controlled');
check(clevo_stmt($db,'SELECT admin_status FROM stations WHERE StationID=?','i',[$pending])->get_result()->fetch_assoc()['admin_status']==='pending','Missing commercial document prevents approval');
clevo_stmt($db,'UPDATE stations SET CommercialRecordPath=? WHERE StationID=?','si',[ad_document(),$pending]);
ad_expect(302,ad_http('POST','/admin/station-approvals.php',['station_id'=>$pending,'action'=>'Approve','csrf_token'=>$csrf],$jar),'Verified station can be approved');
check(clevo_account($db,$pendingOwner)['AccountStatus']==='active','Approval activates the verified owner');
// Registration-pending owners can be approved; an administrative restriction cannot be bypassed.
$restrictedOwner=ad_actor('owner',$password);$restrictedStation=ad_station($restrictedOwner,'pending');
clevo_stmt($db,'UPDATE stations SET CommercialRecordPath=? WHERE StationID=?','si',[ad_document(),$restrictedStation]);
clevo_admin_command($db,$admin,'user_status',$restrictedOwner,['status'=>'pending']);
rejects(fn()=>clevo_admin_command($db,$admin,'station_status',$restrictedStation,['admin_status'=>'approved']),'Station approval cannot override an administrative pending restriction','owner_restricted');
check(clevo_account($db,$restrictedOwner)['AccountStatus']==='pending','Failed approval preserves account restriction');
ad_expect(200,ad_http('GET','/api/v1/stations/'.$pending),'Approved station appears in customer catalog');
ad_expect(200,ad_http('GET','/includes/media.php?station_id='.$pending,null,$jar),'Admin reads station-specific private document');
ad_expect(403,ad_http('GET','/includes/media.php?station_id='.$pending,null,$customerJar),'Customer cannot read private verification document');
ad_http('POST','/admin/manage-stations.php',['confirm_block_id'=>$pending,'csrf_token'=>$csrf],$jar);
ad_expect(404,ad_http('GET','/api/v1/stations/'.$pending),'Blocked station disappears from mobile catalog');
// Service and driver changes use the same owner API services and are immediately readable.
$serviceInput=['name_ar'=>'غسيل معدل','name_en'=>'Updated admin wash','price'=>'12.75','duration'=>'45','is_station'=>'on'];
ad_expect(303,ad_submit('services','service_update',$service,$serviceInput,$jar,$csrf),'Admin edits existing service');
$catalog=ad_expect(200,ad_http('GET','/api/v1/stations/'.$station.'/services'),'Mobile catalog reads edited service');
check(str_contains(json_encode($catalog),'12.75')&&str_contains(json_encode($catalog),'Updated admin wash'),'Mobile service catalog reflects admin edits');
$name='Admin driver '.substr(ad_key(),0,8);
ad_submit('people','driver_create',0,['station_id'=>$station,'name'=>$name,'phone'=>'0791234567'],$jar,$csrf);
$savedDriver=clevo_stmt($db,"SELECT UserID,Password,MustResetPassword FROM user WHERE UserName=? AND LOWER(Role)='driver'",'s',[$name])->get_result()->fetch_assoc();
check((int)($savedDriver['MustResetPassword']??0)===1&&strlen($savedDriver['Password']??'')>50,'Created driver has a random hashed credential and requires enrollment');
$drivers=ad_expect(200,ad_http('GET','/api/v1/owner/drivers',null,null,$ot),'Partner API reads drivers');check(str_contains(json_encode($drivers),$name),'Admin-created driver appears in partner API');
// Plans and entitlements remain shared, without granting unpaid subscriptions.
$planName='Admin plan '.substr(ad_key(),0,6);
ad_http('POST','/admin/manage-plans.php',['add_plan'=>1,'name_ar'=>'باقة اختبار','name_en'=>$planName,'price'=>'22.50','duration'=>'1','duration_type'=>'month','wash_count'=>'3','desc_ar'=>'','desc_en'=>'','is_active'=>'on','csrf_token'=>$csrf],$jar);
$plan=(int)(clevo_stmt($db,'SELECT id FROM plans WHERE name_en=?','s',[$planName])->get_result()->fetch_assoc()['id']??0);check($plan>0,'Legacy plan form uses shared validated plan command');
$plans=ad_expect(200,ad_http('GET','/api/v1/subscription-plans'),'Customer catalog reads plans');check(str_contains(json_encode($plans),$planName),'Admin-created plan is visible to customer app');
// Mobile support/dispute creation, web reply and explicit closure use one message thread.
$ticket=ad_expect(201,ad_http('POST','/api/v1/support-requests',['subject'=>'Admin cross-app dispute','message'=>'Please review this booking','booking_id'=>$booking,'category'=>'dispute'],null,$ct),'Mobile creates dispute');
$ticketID=(int)($ticket['ticket_id']??0);check($ticketID>0,'Dispute has canonical support ticket ID');
$supportPage=ad_http('GET','/admin/management.php?view=disputes',null,$jar);
preg_match_all('/<option\s+value="(New|Replied|Closed)"[^>]*data-admin-ar=/',$supportPage[2],$ticketOptionValues);
check(count(array_unique($ticketOptionValues[1]))===3,'Translated support options submit canonical API status values');
ad_expect(200,ad_http('POST','/admin/support-chat.php?ticket_id='.$ticketID,['admin_reply'=>1,'reply_msg'=>'تمت مراجعة الحجز / Reviewed','csrf_token'=>$csrf],$jar),'Web admin replies to mobile dispute');
$messages=ad_expect(200,ad_http('GET','/api/v1/support-requests/'.$ticketID.'/messages',null,null,$ct),'Mobile reads admin reply');
check(str_contains(json_encode($messages),'Reviewed'),'Admin reply is present in the mobile message thread');
ad_submit('disputes','support_status',$ticketID,['status'=>'Closed'],$jar,$csrf);
ad_expect(409,ad_http('POST','/api/v1/support-requests/'.$ticketID.'/messages',['message'=>'Should not append'],null,$ct),'Mobile cannot reply to closed dispute');
$messageCount=(int)clevo_stmt($db,'SELECT COUNT(*) n FROM support_messages WHERE TicketID=?','i',[$ticketID])->get_result()->fetch_assoc()['n'];
ad_http('POST','/admin/support-chat.php?ticket_id='.$ticketID,['admin_reply'=>1,'reply_msg'=>'Closed thread reply','csrf_token'=>$csrf],$jar);
check((int)clevo_stmt($db,'SELECT COUNT(*) n FROM support_messages WHERE TicketID=?','i',[$ticketID])->get_result()->fetch_assoc()['n']===$messageCount,'Web cannot append to closed thread either');
ad_submit('support','support_status',$ticketID,['status'=>'New'],$jar,$csrf);
ad_expect(201,ad_http('POST','/api/v1/support-requests/'.$ticketID.'/messages',['message'=>'Reopened reply'],null,$ct),'Explicit admin reopening restores replies');
$beforeMessages=(int)clevo_stmt($db,'SELECT COUNT(*) n FROM support_messages WHERE TicketID=?','i',[$ticketID])->get_result()->fetch_assoc()['n'];
$key=ad_key();$input=['id'=>$ticketID,'message'=>'Idempotent service reply'];
for($i=0;$i<2;$i++)clevo_db_idempotent($db,$admin,'admin.support_reply',$key,$input,fn()=>clevo_admin_command($db,$admin,'support_reply',$ticketID,['message'=>$input['message']]));
check((int)clevo_stmt($db,'SELECT COUNT(*) n FROM support_messages WHERE TicketID=?','i',[$ticketID])->get_result()->fetch_assoc()['n']===$beforeMessages+1,'Admin reply retry creates exactly one message');
// API write routes enforce role checks independently of browser middleware.
foreach([$ct,$ot,$dt] as $token){
    ad_expect(403,ad_http('PATCH','/api/v1/admin/users/'.$admin.'/status',['status'=>'rejected'],null,$token),'Non-admin cannot call admin status API');
    ad_expect(403,ad_http('GET','/api/v1/admin/audit-logs',null,null,$token),'Non-admin cannot read audit API');
}
ad_expect(422,ad_http('PATCH','/api/v1/admin/users/'.$customer.'/status',['status'=>'active','Role'=>'Admin'],null,$at),'API rejects role mass assignment');
ad_expect(200,ad_http('GET','/api/v1/admin/services',null,null,$at),'Admin service API shares repository');
ad_expect(200,ad_http('GET','/api/v1/admin/drivers',null,null,$at),'Admin driver API shares records');
ad_expect(200,ad_http('PATCH','/api/v1/admin/services/'.$service,['price'=>'13.25'],null,$at),'Admin API service edit uses shared business command');
check(clevo_stmt($db,'SELECT Price FROM services WHERE ServiceID=?','i',[$service])->get_result()->fetch_assoc()['Price']==='13.25','Admin API service edit reaches canonical record');
ad_expect(200,ad_http('PATCH','/api/v1/admin/users/'.$driver.'/status',['status'=>'rejected'],null,$at),'Admin restriction applies to driver account');
ad_expect(409,ad_http('PATCH','/api/v1/owner/drivers/'.$driver,['active'=>true],null,$ot),'Owner cannot undo an administrative driver restriction');
ad_expect(200,ad_http('PATCH','/api/v1/admin/drivers/'.$driver,['active'=>true],null,$at),'Admin can restore driver through shared driver command');
ad_expect(401,ad_http('GET','/api/v1/driver/jobs',null,null,$dt),'Driver restoration does not revive old credentials');
$statistics=ad_expect(200,ad_http('GET','/api/v1/admin/dashboard-statistics',null,null,$at),'Dashboard statistics API reads database');
check(($statistics['statistics']??[])===clevo_admin_dashboard_stats($db),'Web and API share identical dashboard statistics');
$audit=ad_expect(200,ad_http('GET','/api/v1/admin/audit-logs',null,null,$at),'Admin audit API is readable');
check(str_contains(json_encode($audit),'support.replied'),'Audit API includes administrative action');
check((int)clevo_stmt($db,'SELECT COUNT(*) n FROM audit_logs WHERE ActorID=?','i',[$admin])->get_result()->fetch_assoc()['n']>=8,'Administrative mutations and document reads retain actor audit trails');
$preserved=ad_booking($customer,$station,$service);
clevo_admin_command($db,$admin,'user_status',$owner,['status'=>'pending']);
check(clevo_stmt($db,'SELECT Status FROM stations WHERE StationID=?','i',[$station])->get_result()->fetch_assoc()['Status']==='Closed','Owner restriction closes station availability');
ad_expect(409,ad_http('POST','/api/v1/bookings',['station_id'=>$station,'service_ids'=>[$service],'service_location'=>'station','booking_date'=>(new DateTimeImmutable('+900 days'))->format('Y-m-d'),'booking_time'=>'11:00'],null,$ct),'Restricted owner cannot receive new mobile bookings');
$oldBooking=ad_expect(200,ad_http('GET','/api/v1/bookings/'.$preserved,null,null,$ct),'Existing customer booking remains readable after owner restriction');
check(($oldBooking['booking']['Status']??'')==='Pending','Owner restriction does not rewrite existing booking history');
clevo_admin_command($db,$admin,'user_status',$owner,['status'=>'active']);
check(clevo_stmt($db,'SELECT Status FROM stations WHERE StationID=?','i',[$station])->get_result()->fetch_assoc()['Status']==='Closed','Restoring owner does not silently reopen station availability');
finish_tests('Admin security and cross-application workflow suite');
