<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/app-helpers.php';
require_once __DIR__.'/../includes/admin-queries.php';
$actor=clevo_current_user_id();
$views=['bookings'=>['الحجوزات','Bookings'],'people'=>['المستخدمون والملاك والسائقون','Users, owners and drivers'],
    'services'=>['الخدمات','Services'],'subscriptions'=>['الاشتراكات','Subscriptions'],'refunds'=>['طلبات الاسترجاع','Refund requests'],
    'provider_refunds'=>['استرجاعات مزود الدفع','Provider refunds'],'support'=>['تذاكر الدعم','Support tickets'],'disputes'=>['النزاعات','Disputes'],
    'earnings'=>['أرباح الملاك','Owner earnings'],'settlements'=>['التسويات','Settlements'],'audit'=>['سجل الإدارة','Admin audit log']];
$view=clevo_api_enum('view',array_keys($views),'bookings');$page=clevo_api_integer('page',1,1,100000);
$query=clevo_api_query('query','');$status=clevo_api_query('status','');
if($_SERVER['REQUEST_METHOD']==='POST'){
    // HTML forms submit scalar strings: reject arrays and lossy numeric coercion.
    foreach($_POST as $value)if(!is_string($value))throw new ClevoSecurityException('invalid_input',422);
    foreach(['id','station_id','owner_id','duration'] as $field)if(isset($_POST[$field])&&filter_var($_POST[$field],FILTER_VALIDATE_INT)===false)throw new ClevoSecurityException('invalid_input',422);
    $action=$_POST['action']??'';$id=(int)($_POST['id']??0);$input=[];
    switch($action){
        case 'booking_cancel':$input=['reason'=>$_POST['reason']??''];break;
        case 'user_status':case 'subscription_status':case 'support_status':$input=['status'=>$_POST['status']??''];break;
        case 'refund_decision':$input=['status'=>$_POST['status']??'','note'=>$_POST['note']??''];break;
        case 'service_create':case 'service_update':
            $input=['name_ar'=>trim($_POST['name_ar']??''),'name_en'=>trim($_POST['name_en']??''),'price'=>$_POST['price']??'',
                'duration'=>(int)($_POST['duration']??0),'is_station'=>isset($_POST['is_station']),'is_home'=>isset($_POST['is_home']),'is_pickup'=>isset($_POST['is_pickup'])];break;
        case 'driver_create':$input=['station_id'=>(int)($_POST['station_id']??0),'name'=>trim($_POST['name']??''),'phone'=>$_POST['phone']??''];if(trim($_POST['email']??'')!=='')$input['email']=trim($_POST['email']);break;
        case 'driver_update':$input=['name'=>trim($_POST['name']??''),'phone'=>$_POST['phone']??'','active'=>isset($_POST['active'])];break;
        case 'settlement_request':$input=['owner_id'=>(int)($_POST['owner_id']??0),'amount'=>$_POST['amount']??''];break;
        case 'service_archive':case 'provider_refund_submit':break;
        default:throw new ClevoSecurityException('invalid_admin_action',422);
    }
    try{
        $key=is_string($_POST['_key']??null)?$_POST['_key']:'';
        if($action==='provider_refund_submit'){
            clevo_admin_actor($conn,$actor);
            clevo_db_idempotent($conn,$actor,'admin.provider_refund_submit',$key,[$id],fn()=>['refund_id'=>$id]);
            clevo_payment_refund_submit($conn,$actor,$id);
        }else clevo_db_idempotent($conn,$actor,'admin.'.$action,$key,['id'=>$id]+$input,fn()=>clevo_admin_command($conn,$actor,$action,$id,$input));
        $_SESSION['admin_saved']=true;
    }catch(ClevoSecurityException $error){$_SESSION['admin_error']=$error->reason;}
    header('Location: management.php?'.http_build_query(['view'=>$view,'page'=>$page,'query'=>$query,'status'=>$status]),true,303);exit;
}
$result=clevo_admin_rows($conn,$actor,$view,$page,$query,$status);
$stations=clevo_stmt($conn,"SELECT StationID,Name FROM stations WHERE admin_status='approved' AND ArchivedAt IS NULL ORDER BY Name,StationID LIMIT 500")->get_result()->fetch_all(MYSQLI_ASSOC);
function label(string $ar,string $en):string{return '<span data-admin-ar="'.e($ar).'" data-admin-en="'.e($en).'">'.e($ar).'</span>';}
function admin_state(?string $state):string{
    $state??='';
    $ar=['Pending'=>'بانتظار القبول','Accepted'=>'مقبول','Completed'=>'مكتمل','Cancelled'=>'ملغى','Rejected'=>'مرفوض','Cancelled_Timeout'=>'ملغى لانتهاء المهلة',
        'active'=>'نشط','pending'=>'قيد الانتظار','rejected'=>'مرفوض','expired'=>'منتهي','cancelled'=>'ملغى','blocked'=>'محجوب','verified'=>'موثق',
        'Admin'=>'مسؤول','admin'=>'مسؤول','owner'=>'مالك','driver'=>'سائق','customer'=>'عميل','User'=>'عميل',
        'New'=>'جديدة','Replied'=>'تم الرد','Closed'=>'مغلقة','approved'=>'موافق عليه','requested'=>'مطلوب','submitting'=>'قيد الإرسال',
        'succeeded'=>'مؤكد','failed'=>'فشل','unknown'=>'تحتاج مراجعة','unverified'=>'غير موثق','cash_attested'=>'إقرار نقدي من المالك',
        'verified_sandbox'=>'دفع تجريبي موثق','refunded_sandbox'=>'استرجاع تجريبي موثق','refund_due_sandbox'=>'دفع تجريبي يتطلب استرجاعاً','verified_subscription_sandbox'=>'اشتراك تجريبي موثق'];
    $en=['cash_attested'=>'Owner cash attestation','verified_sandbox'=>'Verified sandbox payment','refunded_sandbox'=>'Verified sandbox refund',
        'refund_due_sandbox'=>'Sandbox refund due','verified_subscription_sandbox'=>'Verified sandbox subscription','unverified'=>'Unverified'];
    return label($ar[$state]??$state,$en[$state]??$state);
}
function admin_form(string $action,int $id=0):void{echo '<form method="POST" class="d-flex gap-2 flex-wrap align-items-center my-1">'.csrf_input().'<input type="hidden" name="_key" value="'.bin2hex(random_bytes(24)).'"><input type="hidden" name="action" value="'.e($action).'"><input type="hidden" name="id" value="'.$id.'">';}
function station_options(array $stations):void{foreach($stations as $s)echo '<option value="'.(int)$s['StationID'].'">'.e($s['Name']).' #'.(int)$s['StationID'].'</option>';}
function service_fields(array $r=[]):void{ ?>
    <label><?= label('الاسم العربي','Arabic name') ?><input class="form-control" name="name_ar" value="<?= e($r['Name_ar']??'') ?>" maxlength="100" required></label>
    <label><?= label('الاسم الإنجليزي','English name') ?><input class="form-control" name="name_en" value="<?= e($r['Name_en']??'') ?>" maxlength="100" required></label>
    <label><?= label('السعر JOD','Price JOD') ?><input class="form-control" name="price" type="number" min="0" max="99999999.99" step="0.01" value="<?= e($r['Price']??'') ?>" required></label>
    <label><?= label('الدقائق','Minutes') ?><input class="form-control" name="duration" type="number" min="1" max="1440" value="<?= (int)($r['Duration']??30) ?>" required></label>
    <?php foreach(['is_station'=>['المحطة','Station'],'is_home'=>['المنزل','Home'],'is_pickup'=>['التوصيل','Pickup']] as $field=>$names): ?>
    <label><input type="checkbox" name="<?= e($field) ?>" <?= ($r[$field]??($field==='is_station'))?'checked':'' ?>> <?= label(...$names) ?></label>
    <?php endforeach;
}
$headers=match($view){
    'bookings'=>[['الطلب','Booking'],['العميل والمحطة','Customer / station'],['الموعد','Appointment'],['الحالة','Status'],['دليل الدفع','Payment evidence'],['إجراء','Action']],
    'people'=>[['الحساب','Account'],['الدور والمحطة','Role / station'],['التحقق','Verification'],['الحالة','Status'],['إجراء','Action']],
    'services'=>[['الخدمة والمحطة','Service / station'],['الأسعار والتوفر','Price and availability'],['إجراء','Action']],
    'subscriptions'=>[['العميل والباقة','Customer / plan'],['الصلاحية','Validity'],['الغسلات المتبقية','Remaining washes'],['الحالة','Status'],['إجراء','Action']],
    'refunds','provider_refunds'=>[['المرجع','Reference'],['السبب','Reason'],['الحالة','Status'],['إجراء','Action']],
    'support','disputes'=>[['التذكرة','Ticket'],['العميل والحجز','Customer / booking'],['الحالة','Status'],['إجراء','Action']],
    'earnings'=>[['المالك','Owner'],['المستحق التجريبي JOD','Sandbox payable JOD'],['عمولة النقد المستحقة JOD','Cash commission due JOD'],['إجراء','Action']],
    'settlements'=>[['التسوية والمالك','Settlement / owner'],['المبلغ JOD','Amount JOD'],['الحالة','Status'],['التاريخ','Created']],
    'audit'=>[['السجل','Record'],['المسؤول','Actor'],['الإجراء','Action'],['العنصر','Entity'],['UTC','UTC']],
};
?>
<!DOCTYPE html><html lang="ar" dir="rtl" data-theme="dark"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CLEVO Admin</title><link id="bootstrap-css" rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../assets/css/admin-management.css">
</head><body>
<?php include __DIR__.'/../includes/shared_admin_sidebar.php'; ?>
<main class="admin-main-content p-3 p-lg-4">
<header class="d-flex gap-3 justify-content-between align-items-center mb-3"><h1 class="h4">CLEVO · <?= label(...$views[$view]) ?></h1><div class="d-flex gap-2"><button class="btn btn-outline-primary" id="language">العربية / English</button><button class="btn btn-outline-primary" id="theme"><?= label('المظهر','Theme') ?></button></div></header>
<nav class="d-flex gap-2 flex-wrap mb-3" aria-label="Management">
<?php foreach($views as $key=>$names): ?><a class="btn btn-sm <?= $view===$key?'btn-primary':'btn-outline-primary' ?>" href="?view=<?= e($key) ?>"><?= label(...$names) ?></a><?php endforeach; ?>
<a class="btn btn-sm btn-outline-primary" href="manage-stations.php"><?= label('المحطات','Stations') ?></a><a class="btn btn-sm btn-outline-primary" href="manage-plans.php"><?= label('الباقات','Plans') ?></a><a class="btn btn-sm btn-outline-primary" href="financial-reports.php"><?= label('التقارير','Reports') ?></a>
</nav>
<?php if(isset($_SESSION['admin_saved'])):unset($_SESSION['admin_saved']); ?><p role="status" class="alert alert-success"><?= label('تمت معالجة الطلب. راجع الحالة الحالية.','Request processed. Review the current state.') ?></p><?php endif; ?>
<form class="d-flex gap-2 mb-3 flex-wrap" method="GET"><input type="hidden" name="view" value="<?= e($view) ?>"><label><?= label('بحث','Search') ?><input class="form-control" name="query" maxlength="100" value="<?= e($query) ?>"></label><label><?= label('الحالة (القيمة المسجلة)','Status (stored value)') ?><input class="form-control" name="status" maxlength="40" value="<?= e($status) ?>"></label><button class="btn btn-primary align-self-end"><?= label('بحث','Search') ?></button></form>
<?php if($view==='people'): ?><details class="card p-3 mb-3"><summary><?= label('إضافة سائق','Add driver') ?></summary><?php admin_form('driver_create'); ?><label><?= label('المحطة','Station') ?><select class="form-select" name="station_id" required><?php station_options($stations); ?></select></label><label><?= label('الاسم','Name') ?><input class="form-control" name="name" maxlength="100" required></label><label><?= label('الهاتف','Phone') ?><input class="form-control" name="phone" pattern="07[0-9]{8}" required></label><label><?= label('البريد لدعوة التحقق','Email for verification invitation') ?><input class="form-control" name="email" type="email" maxlength="100"></label><button class="btn btn-primary"><?= label('إضافة','Add') ?></button></form></details><?php endif; ?>
<?php if($view==='services'): ?><details class="card p-3 mb-3"><summary><?= label('إضافة خدمة','Add service') ?></summary><?php admin_form('service_create'); ?><label><?= label('المحطة','Station') ?><select class="form-select" name="id" required><?php station_options($stations); ?></select></label><?php service_fields(); ?><button class="btn btn-primary"><?= label('إضافة','Add') ?></button></form></details><?php endif; ?>
<?php if(in_array($view,['earnings','settlements'],true)): ?><p class="alert alert-info"><?= label('الأرباح التجريبية والنقد منفصلان. طلب التسوية يبقى محجوباً حتى اعتماد عقد المزود وتوثيق التحويل البنكي.','Sandbox payables and cash dues are separate. Settlement requests remain blocked pending provider approval and bank evidence.') ?></p><?php endif; ?>
<div class="card p-3"><div class="table-responsive"><table class="table align-middle"><thead><tr><?php foreach($headers as $names): ?><th><?= label(...$names) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($result['rows'] as $r): ?><tr>
<?php switch($view): case 'bookings': ?>
<td>#<?= (int)$r['BookingID'] ?><br><?= e($r['Order_Number']) ?></td><td><?= e($r['customer']) ?><br><?= e($r['station_name']) ?></td><td><?= e($r['Booking_Date'].' '.$r['Booking_Time']) ?></td><td><?= admin_state($r['Status']) ?></td><td><?= admin_state($r['PaymentEvidence']) ?></td><td><?php if(in_array($r['Status'],['Pending','Accepted'],true)){admin_form('booking_cancel',(int)$r['BookingID']); ?><label><?= label('سبب الإلغاء','Cancellation reason') ?><input class="form-control" name="reason" maxlength="500" required></label><button class="btn btn-sm btn-outline-danger"><?= label('إلغاء','Cancel') ?></button></form><?php } ?></td>
<?php break; case 'people': ?>
<td>#<?= (int)$r['UserID'] ?> <?= e($r['UserName']) ?><br><?= e($r['Email']) ?><br><?= e($r['PhoneNumber']) ?></td><td><?= admin_state($r['Role']) ?> / <?= (int)$r['StationID'] ?></td><td><?= $r['EmailVerifiedAt']?label('البريد موثق','Email verified'):label('البريد غير موثق','Email unverified') ?><?php if(clevo_role($r['Role'])==='owner'): ?><br><a href="../includes/media.php?user_id=<?= (int)$r['UserID'] ?>" target="_blank" rel="noopener"><?= label('ملف التحقق','Verification document') ?></a><?php endif; ?></td><td><?= admin_state($r['AccountStatus']) ?></td><td><?php if((int)$r['UserID']!==$actor){admin_form('user_status',(int)$r['UserID']); ?><select class="form-select" name="status" aria-label="Account status"><?php foreach(['active','pending','rejected'] as $s): ?><option value="<?= e($s) ?>" <?= $s===$r['AccountStatus']?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-primary"><?= label('حفظ','Save') ?></button></form><?php } ?><?php if(clevo_role($r['Role'])==='driver'): ?><details><summary><?= label('تعديل السائق','Edit driver') ?></summary><?php admin_form('driver_update',(int)$r['UserID']); ?><input name="name" aria-label="Driver name" class="form-control" value="<?= e($r['UserName']) ?>" required><input name="phone" aria-label="Driver phone" class="form-control" value="<?= e($r['PhoneNumber']) ?>" pattern="07[0-9]{8}" required><label><input type="checkbox" name="active" <?= $r['AccountStatus']==='active'?'checked':'' ?>><?= label('نشط','Active') ?></label><button class="btn btn-sm btn-primary"><?= label('حفظ','Save') ?></button></form></details><?php endif; ?></td>
<?php break; case 'services': ?>
<td>#<?= (int)$r['ServiceID'] ?> <?= e($r['station_name']) ?></td><td><?php admin_form('service_update',(int)$r['ServiceID']);service_fields($r); ?><button class="btn btn-sm btn-primary"><?= label('حفظ','Save') ?></button></form></td><td><?php admin_form('service_archive',(int)$r['ServiceID']); ?><button class="btn btn-sm btn-outline-danger"><?= label('أرشفة','Archive') ?></button></form></td>
<?php break; case 'subscriptions': ?>
<td>#<?= (int)$r['id'] ?> <?= e($r['UserName']) ?><br><?= e($r['PlanNameSnapshot']) ?></td><td><?= e($r['start_date'].' / '.$r['end_date']) ?></td><td><?= (int)$r['remaining_washes'] ?></td><td><?= admin_state($r['status']) ?></td><td><?php if($r['status']==='active'){admin_form('subscription_status',(int)$r['id']); ?><input type="hidden" name="status" value="cancelled"><button class="btn btn-sm btn-outline-danger"><?= label('إيقاف الاشتراك دون استرجاع مالي','Cancel entitlement without refund') ?></button></form><?php } ?></td>
<?php break; case 'refunds': ?>
<td>#<?= (int)$r['RefundID'] ?> / <?= label('الحجز','Booking') ?> #<?= (int)$r['BookingID'] ?></td><td><?= e($r['Reason']) ?><br><?= e($r['DecisionNote']) ?></td><td><?= admin_state($r['Status']) ?></td><td><?php if($r['Status']==='pending'){admin_form('refund_decision',(int)$r['RefundID']); ?><select class="form-select" name="status" aria-label="Refund decision"><option value="rejected">Rejected / رفض</option><option value="approved">Approved / موافقة</option></select><label><?= label('سبب القرار','Decision note') ?><input class="form-control" name="note" maxlength="2000" required></label><button class="btn btn-sm btn-primary"><?= label('مراجعة','Review') ?></button></form><?php } ?></td>
<?php break; case 'provider_refunds': ?>
<td>#<?= (int)$r['RefundID'] ?><br><?= e($r['OrderID']) ?><br><?= number_format($r['AmountMinor']/1000,3) ?> JOD</td><td><?= e($r['Reason']) ?></td><td><?= admin_state($r['State']) ?></td><td><?php if($r['State']==='requested'){admin_form('provider_refund_submit',(int)$r['RefundID']); ?><button class="btn btn-sm btn-outline-primary"><?= label('إرسال للمزود للتحقق','Submit for provider verification') ?></button></form><?php } ?></td>
<?php break; case 'support':case 'disputes': ?>
<td>#<?= (int)$r['id'] ?> <a href="support-chat.php?ticket_id=<?= (int)$r['id'] ?>"><?= e($r['subject']) ?></a></td><td><?= e($r['user_name']) ?> / #<?= (int)$r['BookingID'] ?></td><td><?= admin_state($r['status']) ?></td><td><?php admin_form('support_status',(int)$r['id']); ?><select class="form-select" name="status" aria-label="Ticket status"><?php foreach(['New'=>'جديدة','Replied'=>'تم الرد','Closed'=>'مغلقة'] as $s=>$statusLabel): ?><option value="<?= e($s) ?>" data-admin-ar="<?= e($statusLabel) ?>" data-admin-en="<?= e($s) ?>" <?= $s===$r['status']?'selected':'' ?>><?= e($statusLabel) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary"><?= label('حفظ','Save') ?></button></form></td>
<?php break; case 'earnings': ?>
<td>#<?= (int)$r['UserID'] ?> <?= e($r['UserName']) ?></td><td><?= number_format($r['sandbox_payable_minor']/1000,3) ?></td><td><?= number_format($r['cash_commission_due_minor']/1000,3) ?></td><td><?php if($r['sandbox_payable_minor']>0){admin_form('settlement_request'); ?><input type="hidden" name="owner_id" value="<?= (int)$r['UserID'] ?>"><label><?= label('المبلغ JOD','Amount JOD') ?><input class="form-control" name="amount" type="number" min="0.001" step="0.001" max="<?= e(clevo_finance_decimal((int)$r['sandbox_payable_minor'])) ?>" required></label><button class="btn btn-sm btn-outline-primary"><?= label('تسجيل طلب تسوية','Record settlement request') ?></button></form><?php } ?></td>
<?php break; case 'settlements': ?>
<td>#<?= (int)$r['SettlementID'] ?> <?= e($r['UserName']) ?></td><td><?= number_format($r['AmountMinor']/1000,3) ?></td><td><?= admin_state($r['State']) ?></td><td><?= e($r['CreatedAt']) ?></td>
<?php break; case 'audit': ?>
<td><?= (int)$r['AuditID'] ?></td><td><?= (int)$r['ActorID'] ?></td><td><?= e($r['Action']) ?></td><td><?= e($r['EntityType']) ?> #<?= (int)$r['EntityID'] ?></td><td><?= e($r['CreatedAt']) ?></td>
<?php break; endswitch; ?></tr><?php endforeach; ?>
<?php if(!$result['rows']): ?><tr><td colspan="<?= count($headers) ?>"><?= label('لا توجد سجلات','No records') ?></td></tr><?php endif; ?>
</tbody></table></div></div><nav class="d-flex gap-3 my-3">
<?php if($page>1): ?><a href="?<?= e(http_build_query(['view'=>$view,'page'=>$page-1,'query'=>$query,'status'=>$status])) ?>"><?= label('السابق','Previous') ?></a><?php endif; ?>
<span><?= $page ?></span><?php if($result['has_more']): ?><a href="?<?= e(http_build_query(['view'=>$view,'page'=>$page+1,'query'=>$query,'status'=>$status])) ?>"><?= label('التالي','Next') ?></a><?php endif; ?></nav>
</main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><script src="../assets/js/admin-management.js"></script>
</body></html>
