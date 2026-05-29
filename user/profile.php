<?php
session_start();
include(__DIR__ . "/../includes/config.php");
include(__DIR__ . "/../includes/app-helpers.php");


$userID = clevo_require_role(['customer', 'user'], '../general/login.php');


$stmt = $conn->prepare("SELECT UserName, PhoneNumber, Email, ProfilePic, CarName FROM `user` WHERE UserID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();


if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_profile']) || isset($_POST['save_profile_image']))) {
    clevo_require_csrf_token();
    $newName = trim($_POST['full_name']);
    $newPhone = trim($_POST['phone']);
    $newEmail = trim($_POST['email']);
    $newCar = trim($_POST['car_name']); 

    
    $upd = $conn->prepare("UPDATE `user` SET UserName = ?, PhoneNumber = ?, Email = ?, CarName = ? WHERE UserID = ?");
    $upd->bind_param("ssssi", $newName, $newPhone, $newEmail, $newCar, $userID);
    
    if ($upd->execute()) {
        $_SESSION['userName'] = $newName;
        
        
        if (isset($_FILES['profile_img']) && $_FILES['profile_img']['error'] === 0) {
            $target_dir = "../uploads/profiles/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $imgName = "user_" . $userID . "_" . time() . "." . pathinfo($_FILES["profile_img"]["name"], PATHINFO_EXTENSION);
            if (move_uploaded_file($_FILES["profile_img"]["tmp_name"], $target_dir . $imgName)) {
                $imgUpd = $conn->prepare("UPDATE `user` SET ProfilePic = ? WHERE UserID = ?");
                $imgUpd->bind_param("si", $imgName, $userID);
                $imgUpd->execute();
            }
        }
        header("Location: profile.php?success=1");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="profile_page_title">الملف الشخصي | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { 
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.85);
            --text: #0369a1; 
            --accent: #0ea5e9; 
            --border: rgba(3, 105, 161, 0.2);
            --input-text: #0f172a;
            --label-text: #0369a1;
        }
        [data-theme="dark"] { 
            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --glass: rgba(30, 41, 59, 0.75);
            --text: #f1f5f9; 
            --accent: #38bdf8; 
            --border: rgba(255, 255, 255, 0.15);
            --input-text: #ffffff;
            --label-text: #38bdf8;
        }
        
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 20px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; }

        .profile-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; position: relative; height: 180px; border-radius: 0 0 40px 40px; background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 60px; }
        .back-circle-btn { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; background: rgba(255,255,255,0.2); color: #fff; transition: 0.3s; z-index: 10; }

        /* تعديلات السهم والهيدر لضمان التوسيط */
        .spacer-div { width: 40px; }
        [dir="rtl"] .back-circle-btn { order: 1; } 
        [dir="rtl"] .spacer-div { order: 3; }
        [dir="rtl"] #backIcon { transform: rotate(0deg); }

        [dir="ltr"] .back-circle-btn { order: 1; }
        [dir="ltr"] .spacer-div { order: 3; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }

        /* استرجاع الـ fw-black الأصلي */
        h4.fw-black { order: 2; flex-grow: 1; text-align: center; margin: 0; color: white !important; }

        .profile-img-container { position: absolute; bottom: -45px; left: 50%; transform: translateX(-50%); text-align: center; width: 100%; }
        .profile-img { width: 100px; height: 100px; border-radius: 50%; border: 4px solid var(--glass); object-fit: cover; background: #eee; }
        
        .card-profile { padding: 25px; margin-bottom: 20px; color: var(--text) !important; }
        .form-control { border-radius: 15px; background: var(--glass) !important; border: 1px solid var(--border); color: var(--input-text) !important; padding: 12px; }
        .form-control::placeholder { color: var(--text); opacity: 0.6; }
        .form-label-custom { color: var(--label-text) !important; }
        
        .btn-save { border-radius: 50px; padding: 15px; font-weight: 700; background: var(--accent); border: none; color: white !important; width: 100%; box-shadow: 0 10px 20px rgba(0,0,0,0.1); transition: 0.3s; }
        .btn-save:active { transform: scale(0.95); }
        .edit-img-btn { position: absolute; bottom: 5px; right: 0; background: #22c55e; color: white; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 2px solid white; }
    </style>
</head>
<body>
    <div class="profile-header shadow">
        <div class="spacer-div"></div>
        <h4 class="text-white fw-black m-0" data-i18n="profile_page_title">المعلومات الشخصية</h4>
        <a href="home.php" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>

        <div class="profile-img-container">
            <div class="position-relative d-inline-block">
                <?php $avatar = clevoProfileImageUrl($userData['ProfilePic'] ?? '', $userData['UserName'] ?? 'User'); ?>
                <img src="<?= $avatar ?>" class="profile-img shadow">
                <form id="imgForm" enctype="multipart/form-data" method="POST">
                    <?= csrf_input(); ?>
                    <input type="hidden" name="save_profile_image" value="1">
                    <input type="hidden" name="full_name" value="<?= htmlspecialchars($userData['UserName']) ?>">
                    <input type="hidden" name="phone" value="<?= htmlspecialchars($userData['PhoneNumber']) ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($userData['Email']) ?>">
                    <input type="hidden" name="car_name" value="<?= htmlspecialchars($userData['CarName'] ?? '') ?>">
                    <label for="imgUpload" class="edit-img-btn"><i class="fas fa-camera fa-xs"></i></label>
                    <input type="file" name="profile_img" id="imgUpload" hidden accept="image/*" onchange="document.getElementById('imgForm').submit();">
                </form>
            </div>
        </div>
    </div>

    <div class="container mt-5 pt-3">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="glass card-profile shadow-sm">
                    <h6 class="mb-4 fw-bold form-label-custom">
                        <i class="fas fa-user-edit me-2"></i><span data-i18n="personal_info_title">المعلومات الشخصية</span>
                    </h6>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <?= csrf_input(); ?>
                        <input type="hidden" name="save_profile" value="1">
                        <div class="row g-3">
                            <div class="col-sm-12">
                                <label class="small mb-1 fw-bold form-label-custom" data-i18n="full_name_label">الاسم الكامل</label>
                                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($userData['UserName']) ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="small mb-1 fw-bold form-label-custom" data-i18n="phone_label">رقم الهاتف</label>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($userData['PhoneNumber']) ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="small mb-1 fw-bold form-label-custom" data-i18n="email_label">البريد الإلكتروني</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($userData['Email']) ?>" data-i18n-placeholder="email_ph">
                            </div>
                            <div class="col-sm-12">
                                <label class="small mb-1 fw-bold form-label-custom" data-i18n="my_cars_title">نوع السيارة</label>
                                <input type="text" name="car_name" class="form-control" value="<?= htmlspecialchars($userData['CarName'] ?? '') ?>" data-i18n-placeholder="car_ph">
                            </div>
                        </div>
                        <button type="submit" id="saveBtn" class="btn-save mt-4">
                            <i class="fas fa-save me-2"></i> <span data-i18n="btn_save_changes">حفظ كافة التعديلات</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const translations = {
            ar: {
                profile_page_title: "المعلومات الشخصية", personal_info_title: "المعلومات الشخصية", full_name_label: "الاسم الكامل", phone_label: "رقم الهاتف", email_label: "البريد الإلكتروني", email_ph: "example@mail.com", btn_save_changes: "حفظ كافة التعديلات", my_cars_title: "نوع السيارة", car_ph: "أدخل موديل سيارتك..."
            },
            en: {
                profile_page_title: "Personal Info", personal_info_title: "Personal Info", full_name_label: "Full Name", phone_label: "Phone Number", email_label: "Email Address", email_ph: "example@mail.com", btn_save_changes: "Save All Changes", my_cars_title: "Car Model", car_ph: "Enter your car model..."
            }
        };

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });

            document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
                const key = el.getAttribute('data-i18n-placeholder');
                if (translations[lang][key]) el.placeholder = translations[lang][key];
            });
        };
    </script>
    <script src="../assets/lamea.js"></script>
</body>
</html>
