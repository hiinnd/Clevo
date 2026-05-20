<?php

include(__DIR__ . "/../includes/config.php");

if (isset($_GET['token']) && !empty(trim($_GET['token']))) {

    $token = trim($_GET['token']);

    $update = $conn->prepare(
        "UPDATE `user` SET AccountStatus = 'active', VerificationToken = NULL WHERE VerificationToken = ? AND AccountStatus <> 'rejected'"
    );

    $update->bind_param("s", $token);

    $update->execute();

    if ($update->affected_rows > 0) {

        header("Location: verify_success.php");
        exit();

    } else {

        echo "Verification failed or this account is already verified.";

    }

} else {

    echo "No token found.";

}
?>
