<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendResetOTP($toEmail, $userName, $otp)
{
    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        $mail->Username = 'support.clevo@gmail.com';
        $mail->Password = 'aessfjspgpicvcje';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('support.clevo@gmail.com', 'Clevo');

        $mail->addAddress($toEmail, $userName);

        $mail->isHTML(true);

        $mail->Subject = 'Password Reset OTP';
        $mail->Body = "
       <div style='background:linear-gradient(135deg,#007bff,#2563eb);padding:10px;text-align:center;'>

    <div style='width:70px;
            height:70px;
            background:#ffffff;
            border-radius:50%;
            margin:0 auto 15px;
            text-align:center;
            font-size:36px;
            line-height:70px;'>
🔒
</div>

    <h1 style='color:#ffffff;
               margin:0;
               font-size:30px;
               font-weight:800;'>

        Password Reset

    </h1>

    <p style='color:#dbeafe;
              font-size:15px;
              margin:8px 0 0 0;'>

        Verification Code

    </p>

</div>

        <div style='padding:25px 35px 35px;text-align:center;color:#111827;'>

            <h2 style='margin-bottom:15px;font-size:26px;'>
                Hello, {$userName}
            </h2>

            <p style='font-size:17px;line-height:1.9;color:#4b5563;margin-bottom:20px;'>
                Use the following OTP code to reset your password securely.
            </p>

            <div style='background:#eff6ff;
                        border:2px dashed #2563eb;
                        border-radius:20px;
                        margin:30px auto;
                        max-width:240px;
                        padding:18px;'>

                <div style='font-size:36px;
                            letter-spacing:10px;
                            font-weight:bold;
                            color:#2563eb;'>

                    {$otp}

                </div>
            </div>

            <p style='font-size:15px;color:#6b7280;margin-top:20px;'>
                This code will expire in 5 minutes.
            </p>

        </div>

        <div style='background:#f8fafc;
                    padding:25px;
                    text-align:center;
                    border-top:1px solid #e5e7eb;'>

            <p style='margin:0;color:#94a3b8;font-size:14px;'>
                Clevo System © 2026
            </p>

            <p style='margin-top:8px;color:#94a3b8;font-size:13px;'>
                Smart Car Wash Management Platform
            </p>

        </div>

    </div>
</div>
";

        return $mail->send();
    } catch (Exception $e) {

        return false;
    }
}
