<?php
session_start();
include_once(__DIR__ . "/../includes/config.php");
include_once(__DIR__ . "/../includes/app-helpers.php");


$userID = clevo_require_role(['customer', 'user'], '../general/login.php');
$error = "";
$success = false;


$userReferralCode = "CLEVO-USER-" . $userID;


$earned_stmt = $conn->prepare("SELECT COALESCE(SUM(Total_Price), 0) AS earned_points FROM booking WHERE UserID = ? AND Status = 'Completed'");
$earned_stmt->bind_param("i", $userID);
$earned_stmt->execute();
$earnedPoints = (int) floor((float) ($earned_stmt->get_result()->fetch_assoc()['earned_points'] ?? 0));

$loyalty_row = $conn->prepare("SELECT LoyaltyID FROM loyalty WHERE UserID = ? ORDER BY LoyaltyID ASC LIMIT 1");
$loyalty_row->bind_param("i", $userID);
$loyalty_row->execute();
$loyaltyMeta = $loyalty_row->get_result()->fetch_assoc();
if ($loyaltyMeta) {
    
} else {
    $sync_stmt = $conn->prepare("INSERT INTO loyalty (UserID, PointsBalance) VALUES (?, ?)");
    $sync_stmt->bind_param("ii", $userID, $earnedPoints);
    $sync_stmt->execute();
}


$stmt = $conn->prepare("SELECT PointsBalance FROM `loyalty` WHERE UserID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result();
$loyalty = $result->fetch_assoc();
$currentPoints = clevoGetLoyaltyPoints($conn, (int)$userID);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_points'])) {
    clevo_require_csrf_token();
    $requiredPoints = intval($_POST['points_to_deduct']);
    $rewardValue = $requiredPoints / 40; 

    if ($currentPoints >= $requiredPoints) {
        $conn->begin_transaction();
        try {
            $wallet = clevoEnsureWallet($conn, $userID);
            if (!clevoSpendLoyaltyPoints($conn, $userID, $requiredPoints)) {
                throw new RuntimeException('not_enough_points');
            }

            
            $upd_w = $conn->prepare("UPDATE `wallet` SET Balance = Balance + ? WHERE WalletID = ?");
            $walletID = (int) $wallet['WalletID'];
            $upd_w->bind_param("di", $rewardValue, $walletID);
            $upd_w->execute();

            
            $desc_ar = "استبدال " . $requiredPoints . " نقطة مكافآت";
            $desc_en = "Redeemed " . $requiredPoints . " loyalty points";
            $ins_trans = $conn->prepare("INSERT INTO `wallet_transactions` (WalletID, Amount, TransactionType, Description_ar, Description_en) 
                                        VALUES (?, ?, 'credit', ?, ?)");
            $ins_trans->bind_param("idss", $walletID, $rewardValue, $desc_ar, $desc_en);
            $ins_trans->execute();

            $conn->commit();
            $success = true;
            $currentPoints -= $requiredPoints; 
        } catch (Exception $e) {
            $conn->rollback();
            $error = "system_error";
        }
    } else {
        $error = "not_enough_points";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="loyalty_page_title">نظام المكافآت | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.85);
            --text: #0369a1;
            --text-muted: #475569;
            --accent: #0ea5e9;
            --border: rgba(3, 105, 161, 0.15);
            --btn-blue: #1d4ed8;
        }

        [data-theme="dark"] {
            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --glass: rgba(30, 41, 59, 0.75);
            --text: #f8fafc;
            --text-muted: #cbd5e1;
            --accent: #38bdf8;
            --border: rgba(255, 255, 255, 0.1);
            --btn-blue: #1d58f3;
        }

        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 40px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; }
        .loyalty-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; position: relative; height: 160px; border-radius: 0 0 40px 40px; background: linear-gradient(135deg, #3b82f6, #1e3a8a); margin-bottom: 60px; }
        .back-circle-btn { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; background: rgba(255, 255, 255, 0.2); color: #fff; transition: 0.3s; z-index: 10; }
        
        h4.text-white { order: 2; flex-grow: 1; text-align: center; }
        .points-card { padding: 30px; margin-top: -80px; position: relative; z-index: 20; text-align: center; }
        .points-number { font-size: 3.5rem; font-weight: 900; color: var(--text); text-shadow: 0 5px 15px rgba(59, 130, 246, 0.3); }
        
        .reward-item, .info-item { padding: 20px; border-radius: 20px; margin-bottom: 16px; display: flex; align-items: center; border: 1px solid var(--border); transition: 0.3s; }
        .reward-icon, .info-icon { width: 52px; height: 52px; background: rgba(59, 130, 246, 0.12); color: #3b82f6; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-inline-end: 20px; flex-shrink: 0; }
        [data-theme="dark"] .reward-icon, [data-theme="dark"] .info-icon { color: #38bdf8; background: rgba(56, 189, 248, 0.15); }
        
        .btn-redeem, .btn-invite { background: var(--btn-blue); color: #fff !important; border: none; border-radius: 50px; padding: 10px 28px; font-weight: 700; font-size: 0.95rem; transition: 0.3s; text-decoration: none; display: inline-block; box-shadow: 0 4px 12px rgba(29, 88, 243, 0.2); }
        .btn-redeem:hover, .btn-invite:hover { opacity: 0.9; transform: scale(1.05); }
        .modal-content { border-radius: 25px; background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); color: var(--text); }
        
        .text-custom-title { color: var(--text) !important; text-align: start; font-size: 1rem; }
        .text-custom-muted { color: var(--text-muted) !important; text-align: start; font-size: 0.85rem; margin-top: 2px; }

        [dir="ltr"] .reward-item, [dir="ltr"] .info-item { text-align: left; }
        [dir="ltr"] .reward-icon, [dir="ltr"] .info-icon { margin-inline-end: 0; margin-right: 20px; }
        [dir="rtl"] .btn-redeem, [dir="rtl"] .btn-invite { margin-inline-end: auto; }
        [dir="ltr"] .btn-redeem, [dir="ltr"] .btn-invite { margin-left: auto; }
        

        [dir="ltr"] #backIcon { transform: scaleX(-1); }
    </style>
</head>

<body>
    <div class="loyalty-header shadow">
        <h4 class="text-white fw-black m-0" data-i18n="loyalty_title">نقاط Clevo</h4>
        <a href="home.php" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="container">
        <div class="glass points-card shadow-lg mx-2">
            <small class="text-custom-muted fw-bold d-block text-center" data-i18n="points_balance">رصيدك الحالي</small>
            <div class="points-number" id="currentPointsDisp"><?= number_format($currentPoints) ?></div>
            <p class="small fw-bold text-primary mb-0 text-center"><i class="fas fa-star me-1 text-warning"></i> <span data-i18n="points_label">Clevo Points</span></p>
        </div>

        <h6 class="mt-4 px-3 fw-black mb-3 text-custom-title" data-i18n="how_to_earn_title">كيف تجمع النقاط؟</h6>
        <div class="px-2">
            <div class="glass info-item shadow-sm">
                <div class="info-icon"><i class="fas fa-hand-holding-dollar"></i></div>
                <div class="flex-grow-1">
                    <h6 class="mb-0 fw-bold text-custom-title" data-i18n="earn_1_t">استخدم خدماتنا</h6>
                    <div class="text-custom-muted" data-i18n="earn_1_d">كل 1 دينار تصرفه = 1 نقطة Clevo</div>
                </div>
            </div>
            
            <div class="glass info-item shadow-sm">
                <div class="info-icon"><i class="fas fa-user-plus"></i></div>
                <div class="flex-grow-1">
                    <h6 class="mb-0 fw-bold text-custom-title" data-i18n="earn_2_t">دعوة الأصدقاء</h6>
                    <div class="text-custom-muted me-2" data-i18n="earn_2_d">احصل على 50 نقطة عند أول حجز لصديقك</div>
                </div>
                <button class="btn-invite shadow-sm" onclick="shareReferral('<?= $userReferralCode ?>')" data-i18n="btn_invite">دعوة</button>
            </div>
        </div>

        <h6 class="mt-4 px-3 fw-black mb-3 text-custom-title" data-i18n="redeem_title">استبدل نقاطك برصيد</h6>
        <div class="px-2">
            <div class="glass reward-item shadow-sm">
                <button class="btn-redeem shadow-sm" onclick="openRedeemModal(40)" data-i18n="btn_redeem">استبدال</button>
                <div class="flex-grow-1 text-end px-3">
                    <h6 class="mb-0 fw-bold text-custom-title" data-i18n="reward_1_t">رصيد 1 دينار بالمحفظة</h6>
                    <div class="text-custom-muted fw-bold">40 <span data-i18n="points_unit">نقطة</span></div>
                </div>
                <div class="reward-icon"><i class="fas fa-wallet"></i></div>
            </div>

            <div class="glass reward-item shadow-sm">
                <button class="btn-redeem shadow-sm" onclick="openRedeemModal(200)" data-i18n="btn_redeem">استبدال</button>
                <div class="flex-grow-1 text-end px-3">
                    <h6 class="mb-0 fw-bold text-custom-title" data-i18n="reward_2_t">رصيد 5 دنانير بالمحفظة</h6>
                    <div class="text-custom-muted fw-bold">200 <span data-i18n="points_unit">نقطة</span></div>
                </div>
                <div class="reward-icon"><i class="fas fa-gift"></i></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <i class="fas fa-coins text-warning fa-4x mb-3"></i>
                <h6 class="fw-bold mb-3 text-custom-title text-center" data-i18n="redeem_confirm_t">تأكيد تحويل النقاط لرصيد؟</h6>
                <form method="POST">
                    <?= csrf_input(); ?>
                    <input type="hidden" name="points_to_deduct" id="modalPoints">
                    <button type="submit" name="redeem_points" class="btn btn-primary w-100 rounded-pill py-2 fw-bold mb-2 text-white" data-i18n="btn_confirm">تأكيد الاستبدال</button>
                    <button type="button" class="btn btn-light w-100 rounded-pill border py-2 fw-bold text-custom-muted text-center" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <i class="fas fa-check-circle text-success fa-4x mb-3"></i>
                <h6 class="fw-bold mb-2 text-custom-title text-center" id="statusModalTitle"></h6>
                <p class="small text-custom-muted text-center mb-0" id="statusModalBody"></p>
                <button type="button" class="btn btn-primary w-100 rounded-pill py-2 fw-bold mt-3 text-white" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                loyalty_page_title: "نظام المكافآت | Clevo", loyalty_title: "نقاط Clevo", points_balance: "رصيدك الحالي", points_label: "Clevo Points",
                redeem_title: "استبدل نقاطك برصيد", how_to_earn_title: "كيف تجمع النقاط؟",
                earn_1_t: "استخدم خدماتنا", earn_1_d: "كل 1 دينار تصرفه = 1 نقطة Clevo",
                earn_2_t: "دعوة الأصدقاء", earn_2_d: "احصل على 50 نقطة عند أول حجز لصديقك",
                reward_1_t: "رصيد 1 دينار بالمحفظة", reward_2_t: "رصيد 5 دنانير بالمحفظة",
                points_unit: "نقطة", btn_redeem: "استبدال", btn_confirm: "تأكيد", btn_cancel: "إلغاء",
                redeem_confirm_t: "تأكيد تحويل النقاط لرصيد؟", redeem_success_msg: "تم تحويل النقاط بنجاح!",
                not_enough_points: "عذراً، رصيدك غير كافٍ للاستبدال.", system_error: "حدث خطأ في النظام، يرجى المحاولة لاحقاً.",
                btn_invite: "دعوة", share_msg: "سجل في تطبيق Clevo واستخدم كود الدعوة الخاص بي للحصول على مكافآت متبادلة عند حجزك الأول! الكود: ", copied_title: "تم النسخ", copied_body: "تم نسخ رابط وكود الدعوة الخاص بك بنجاح!", btn_ok: "موافق"
            },
            en: {
                loyalty_page_title: "Rewards | Clevo", loyalty_title: "Clevo Points", points_balance: "Your Balance", points_label: "Clevo Points",
                redeem_title: "Redeem Points", how_to_earn_title: "How to earn points?",
                earn_1_t: "Use our services", earn_1_d: "Every 1 JOD spent = 1 Clevo point",
                earn_2_t: "Invite Friends", earn_2_d: "Get 50 points on friend's first booking",
                reward_1_t: "1 JOD Wallet Credit", reward_2_t: "5 JOD Wallet Credit",
                points_unit: "Points", btn_redeem: "Redeem", btn_confirm: "Confirm", btn_cancel: "Cancel",
                redeem_confirm_t: "Confirm points conversion?", redeem_success_msg: "Points redeemed successfully!",
                not_enough_points: "Sorry, insufficient points.", system_error: "System error, please try again.",
                btn_invite: "Invite", share_msg: "Register on Clevo using my referral link and enjoy rewards on your first booking! Code: ", copied_title: "Copied", copied_body: "Your referral link and code were copied successfully.", btn_ok: "OK"
            }
        };

        function openRedeemModal(pts) {
            document.getElementById('modalPoints').value = pts;
            new bootstrap.Modal(document.getElementById('confirmModal')).show();
        }

        function showStatusModal(title, body) {
            document.getElementById('statusModalTitle').innerText = title;
            document.getElementById('statusModalBody').innerText = body;
            new bootstrap.Modal(document.getElementById('statusModal')).show();
        }

        function shareReferral(code) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const shareUrl = window.location.origin + "/register.php?ref=" + code;
            const fullMessage = translations[lang].share_msg + code + " \n" + shareUrl;

            if (navigator.share) {
                navigator.share({
                    title: translations[lang].loyalty_title,
                    text: fullMessage,
                    url: shareUrl
                }).catch(err => console.log(err));
            } else {
                navigator.clipboard.writeText(fullMessage).then(() => {
                    showStatusModal(translations[lang].copied_title, translations[lang].copied_body);
                });
            }
        }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            
            const bootstrapLink = document.querySelector('link[href*="bootstrap"]');
            if(lang === 'en') {
                if (bootstrapLink) {
                    bootstrapLink.href = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
                }
                document.querySelectorAll('.reward-item').forEach(item => {
                    const icon = item.querySelector('.reward-icon');
                    const text = item.querySelector('.flex-grow-1');
                    const btn = item.querySelector('.btn-redeem');
                    item.insertBefore(icon, text);
                    item.insertBefore(text, btn);
                    text.style.textAlign = 'left';
                });
            } else {
                if (bootstrapLink) {
                    bootstrapLink.href = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css";
                }
            }

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });

            <?php if ($success): ?>
                showStatusModal(translations[lang].redeem_success_msg, '');
            <?php elseif ($error): ?>
                showStatusModal(lang === 'ar' ? 'تنبيه' : 'Alert', translations[lang][<?= json_encode($error) ?>] || <?= json_encode($error) ?>);
            <?php endif; ?>
        };
    </script>
    <script src="../assets/lamea.js?v=20260605b"></script>
</body>
</html>
