<?php
include __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']); // تأمين الرقم لضمان أنه صحيح
    
    // 1. تحديث الـ Role للمستخدم في جدول الـ user
    $stmt = $conn->prepare("UPDATE user SET Role = 'User' WHERE UserID = ?");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        
        // 2. سطر الإضافة: حذف السجل من جدول الـ blacklist لفك حظر الجهاز
        // نستخدم user_id لأننا ربطناه بعلاقة Foreign Key في الداتا بيس
        $del_stmt = $conn->prepare("DELETE FROM blacklist WHERE user_id = ?");
        $del_stmt->bind_param("i", $user_id);
        $del_stmt->execute();

        // التوجيه لصفحة مركز الأمان مع رسالة نجاح
        header("Location: security-center.php?success=1");
        exit;
        
    } else {
        echo "Error Updating User Role: " . $conn->error;
    }
}
?>
