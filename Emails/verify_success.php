<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Account Verified</title>

    <style>
        body {
            margin: 0;
            padding: 0;
            background: #f4f7fe;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            font-family: Arial;
        }

        .box {
            background: white;
            padding: 50px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 400px;
        }

        .box img {
            width: 180px;
            height: auto;
            display: block;
            margin: 0 auto 20px;
        }

        .box h1 {
            color: #007bff;
            margin-bottom: 15px;
        }

        .box p {
            color: #555;
            line-height: 1.8;
            font-size: 16px;
        }

        .loader {
            margin: 25px auto 0;
            border: 5px solid #eee;
            border-top: 5px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            100% {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body>

    <div class="box">

        <img src="../assets/Images/Verify.png" alt="Verified Icon">

        <h1>
            Account Verified
        </h1>

        <p>
            Your account has been activated successfully.<br>
            You can now log in and explore our services.
        </p>
    </div>

</body>

</html>