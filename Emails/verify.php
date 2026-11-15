<?php
require_once __DIR__ . '/../includes/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    clevo_auth_throttle($conn, 'verify');
    clevo_verify_email($conn, clevo_clean_string($_POST['token'] ?? '', 130));
    clevo_json_response('success');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET, POST'); http_response_code(405); exit; }
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CLEVO verification</title><body><p id="status">جارٍ التحقق من البريد / Verifying email…</p>
<script>
const token = new URLSearchParams(location.hash.slice(1)).get('token') || '';
history.replaceState(null, '', location.pathname);
fetch(location.pathname, {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({token,csrf_token:<?= clevo_js(generate_csrf_token()) ?>})})
 .then(r=>r.json()).then(d=>{if(d.status==='success') location.replace('verify_success.php'); else document.getElementById('status').textContent='الرابط غير صالح أو انتهت صلاحيته / Invalid or expired link';})
 .catch(()=>{document.getElementById('status').textContent='تعذّر التحقق / Verification unavailable';});
</script></body></html>
