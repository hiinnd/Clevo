<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/config.php';
$ar=($_GET['lang']??'ar')!=='en';$t=static fn($a,$b)=>$ar?$a:$b;$message='';$failed=false;$user=clevo_current_user_id();
if(!empty($_SESSION['driver_booking_id']))throw new ClevoSecurityException('task_scope_restricted',403);
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        foreach($_POST as $v)if(!is_string($v))throw new ClevoSecurityException('invalid_input',422);
        clevo_rate_limit($conn,'privacy:web:ip',$_SERVER['REMOTE_ADDR']??'',10,900);
        $password=$_POST['password']??'';
        if(!$user){
            $login=$_POST['login']??'';
            if(strlen($login)>100||strlen($password)>72)throw new ClevoSecurityException('reauthentication_failed',401);
            clevo_rate_limit($conn,'privacy:web:identity',strtolower(trim($login)),5,900);
            $a=clevo_stmt($conn,'SELECT UserID FROM user WHERE Email=? OR PhoneNumber=? LIMIT 1','ss',[$login,$login])->get_result()->fetch_assoc();
            if(!$a)throw new ClevoSecurityException('reauthentication_failed',401);$user=(int)$a['UserID'];
        }
        if(($_POST['confirm']??'')!=='yes')throw new ClevoSecurityException('confirmation_required',422);
        $cancel=($_POST['action']??'')==='cancel';
        $r=clevo_db_idempotent($conn,$user,'privacy.web.'.($cancel?'cancel':'request'),$_POST['_key']??'',['password'=>$password],fn()=>clevo_privacy_request($conn,$user,$password,$cancel));
        $message=$t('حالة الطلب: ','Request state: ').($r['State']??'').' · #'.(int)($r['RequestID']??0);
    }catch(ClevoSecurityException $error){$failed=true;$message=$t('تعذر إرسال الطلب. تحقق من بياناتك وتوفر الخدمة؛ لا يُعد أي حذف مكتملاً.','Request unavailable. Check your credentials and service availability; no deletion is confirmed.');http_response_code($error->httpStatus);}
}
?>
<!doctype html><html lang="<?= $ar?'ar':'en' ?>" dir="<?= $ar?'rtl':'ltr' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CLEVO · <?= $t('حذف الحساب','Account deletion') ?></title><style>body{font:1rem/1.8 system-ui;background:#f0f9ff;color:#0f172a}main{max-width:650px;margin:2rem auto;padding:2rem;border-radius:24px;background:white}input{display:block;width:95%;padding:.7rem;margin:.5rem 0}input[type=checkbox]{display:inline;width:auto}button{padding:.7rem;background:#1d4ed8;color:white;border:0;border-radius:12px}a{color:#0756b9}html[data-theme=dark] body{background:#020617;color:white}html[data-theme=dark] main{background:#1e293b}html[data-theme=dark] a{color:#93c5fd}</style></head><body><main>
<a href="?lang=<?= $ar?'en':'ar' ?>">العربية / English</a><h1>CLEVO · <?= $t('طلب حذف الحساب','Request account deletion') ?></h1>
<p><?= $t('يمكنك تقديم الطلب دون تثبيت التطبيق. الطلب لا يعني أن المحو قد اكتمل؛ تبقى الالتزامات المفتوحة خاضعة للمراجعة وتُحفظ السجلات التي تغطيها سياسة الاحتفاظ المعتمدة. يمكن إلغاء الطلب قبل بدء المعالجة.','Request without installing the app. Submission is not completed erasure; open obligations are reviewed and approved retention applies. Cancel before processing begins.') ?></p>
<p><a href="privacy.php?lang=<?= $ar?'ar':'en' ?>"><?= $t('سياسة الخصوصية والاحتفاظ ومدة المعالجة والتواصل','Privacy, retention, processing time and contact') ?></a></p>
<?php if($message): ?><p role="status"><?= e($message) ?></p><?php endif; ?>
<form method="POST"><?= csrf_input() ?><input type="hidden" name="_key" value="<?= bin2hex(random_bytes(24)) ?>">
<?php if(!clevo_current_user_id()): ?><label><?= $t('البريد أو الهاتف','Email or phone') ?><input name="login" type="text" maxlength="100" autocomplete="username" required></label><?php endif; ?>
<label><?= $t('كلمة المرور الحالية','Current password') ?><input name="password" type="password" maxlength="72" autocomplete="current-password" required></label>
<label><input type="checkbox" name="confirm" value="yes" required><?= $t('أؤكد أنني صاحب الحساب وأفهم سياسة المحو والاحتفاظ.','I own this account and understand the erasure/retention policy.') ?></label>
<p><button name="action" value="request"><?= $t('طلب حذف الحساب','Request account deletion') ?></button> <button name="action" value="cancel"><?= $t('إلغاء الطلب السابق','Cancel pending request') ?></button></p></form>
</main><script>document.documentElement.dataset.theme=localStorage.getItem('global_theme')==='dark'?'dark':'light';</script></body></html>
