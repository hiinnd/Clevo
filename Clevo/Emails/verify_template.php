<?php
$link = $link ?? '#';
?>

<div style="
    max-width:600px;
    margin:auto;
    background:#f4f7fe;
    padding:40px;
    border-radius:25px;
    font-family:Arial, sans-serif;
    text-align:center;
    border:1px solid #e5e7eb;
">

    <!-- Logo -->
    <img src="https://i.imgur.com/QAKdrNq.jpeg"
         width="120"
         style="margin-bottom:20px;">

    <!-- Brand -->
    <h1 style="
        color:#007bff;
        font-size:42px;
        margin-bottom:10px;
        font-weight:bold;
    ">
        CLEVO
    </h1>

    <!-- Title -->
    <h2 style="
        color:#111827;
        margin-bottom:20px;
        font-size:32px;
    ">
        Verify Your Email
    </h2>

    <!-- Message -->
    <p style="
        color:#555;
        line-height:1.9;
        font-size:17px;
        margin-bottom:30px;
    ">

        Welcome to Clevo 🌟<br><br>

        Thank you for registering with us.<br>

        Please verify your email address
        to activate your account.

    </p>

    <!-- Button -->
    <a href="<?= $link ?>"
       style="
        display:inline-block;
        background:#007bff;
        color:white;
        padding:16px 40px;
        border-radius:50px;
        text-decoration:none;
        font-weight:bold;
        font-size:18px;
        box-shadow:0 5px 15px rgba(0,123,255,0.3);
       ">

        Verify Account

    </a>

    <!-- Divider -->
    <hr style="
        margin:40px 0 25px;
        border:none;
        border-top:1px solid #ddd;
    ">

    <!-- Footer Text -->
    <p style="
        color:#888;
        font-size:14px;
        line-height:1.8;
    ">

        If you did not create this account,<br>
        you can safely ignore this email.

    </p>

    <!-- Social Media -->
    <div style="margin-top:30px;">

        <!-- Facebook -->
        <a href="https://facebook.com/"
           style="margin:0 8px; text-decoration:none;">

            <img src="https://cdn-icons-png.flaticon.com/512/733/733547.png"
                 width="32">

        </a>

        <!-- Instagram -->
        <a href="https://www.instagram.com/clevo.jo?igsh=MWk5MWZnMjFqN2QzMw=="
           style="margin:0 8px; text-decoration:none;">

            <img src="https://cdn-icons-png.flaticon.com/512/2111/2111463.png"
                 width="32">

        </a>

        <!-- WhatsApp -->
        <a href="https://wa.me/9627XXXXXXXX"
           style="margin:0 8px; text-decoration:none;">

            <img src="https://cdn-icons-png.flaticon.com/512/733/733585.png"
                 width="32">

        </a>

    </div>

    <!-- Copyright -->
    <p style="
        margin-top:30px;
        color:#999;
        font-size:13px;
    ">

        © 2026 CLEVO. All rights reserved.

    </p>

</div>