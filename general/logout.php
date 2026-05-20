<?php
session_start();
// 1. مسح كافة متغيرات الجلسة من الذاكرة
$_SESSION = array();

// 2. حذف الكوكي الخاص بالجلسة من متصفح المستخدم لزيادة الأمان
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}
// 3. حذف توكن الـ JWT من الكوكي (لأننا استخدمناه في صفحة الـ login)
if (isset($_COOKIE['access_token'])) {
    setcookie("access_token", "", [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
// 4. تدمير الجلسة نهائياً من السيرفر
session_destroy();

// 5. التوجيه لصفحة تسجيل الدخول الموجودة معك في نفس مجلد general
// المسار المباشر "login.php" سيعمل دائماً لأن التوجيه (Header Location) 
// يتم بالنسبة لموقع ملف الـ logout نفسه
header("Location: login.php");
exit();
?>
