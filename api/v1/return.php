<?php
declare(strict_types=1);
// Browser navigation is deliberately not payment evidence. No session, query token,
// provider form fields or supplied status is used to mutate business/financial records.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');
?><!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>CLEVO payment status</title><h1>تحقق من حالة الدفع داخل CLEVO</h1><p>العودة من صفحة الدفع لا تعني نجاح العملية. افتح حجزك أو اشتراكك للاطلاع على الحالة المؤكدة.</p><p lang="en" dir="ltr">Returning from checkout does not confirm payment. Open your CLEVO booking or subscription to check its verified status.</p></html>
