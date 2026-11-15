<?php
include_once __DIR__ . '/../includes/config.php';

clevo_require_role('Admin', '../general/login.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_id'])) {
    clevo_require_csrf_token();
    $user_id = intval($_POST['user_id']); 
    
    
    $stmt = $conn->prepare("UPDATE user SET AccountStatus = 'active' WHERE UserID = ?");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        
        
        
        $del_stmt = $conn->prepare("DELETE FROM blacklist WHERE user_id = ?");
        $del_stmt->bind_param("i", $user_id);
        $del_stmt->execute();

        
        header("Location: security-center.php?success=1");
        exit;
        
    } else {
        echo "Error Updating User Status: " . 'request_failed';
    }
}
?>
