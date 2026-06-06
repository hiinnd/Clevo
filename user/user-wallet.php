<?php
session_start();
include_once(__DIR__ . "/../includes/config.php");


$userID = clevo_require_role(['customer', 'user'], '../general/login.php');


$stmt = $conn->prepare("SELECT Balance, WalletID FROM `wallet` WHERE UserID = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
$currentBalance = $wallet ? number_format($wallet['Balance'], 2) : "0.00";
$walletID = $wallet['WalletID'] ?? 0;


$trans_stmt = $conn->prepare("SELECT * FROM `wallet_transactions` WHERE WalletID = ? ORDER BY CreatedAt DESC LIMIT 10");
$trans_stmt->bind_param("i", $walletID);
$trans_stmt->execute();
$transactions = $trans_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="wallet_page_title">محفظتي | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { 
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.7);
            --text: #0369a1; --accent: #0ea5e9; --border: rgba(255, 255, 255, 0.1);
        }
        [data-theme="dark"] { 
            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --glass: rgba(30, 41, 59, 0.5);
            --text: #f1f5f9; --accent: #38bdf8; --border: rgba(255, 255, 255, 0.05);
        }
        
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 40px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; }

        .wallet-header { 
            padding: 20px; display: flex; align-items: center; justify-content: space-between; 
            position: relative; height: 180px; border-radius: 0 0 40px 40px; 
            background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 60px; 
        }
        
        .back-circle-btn { 
            width: 40px; height: 40px; border-radius: 50%; display: flex; 
            align-items: center; justify-content: center; text-decoration: none; 
            background: rgba(255,255,255,0.2); color: #fff; transition: 0.3s; z-index: 10;
        }


        .spacer-div { width: 40px; }
        [dir="rtl"] .back-circle-btn { order: 1; } 
        [dir="rtl"] .spacer-div { order: 3; }
        [dir="rtl"] #backIcon { transform: rotate(0deg); }

        [dir="ltr"] .back-circle-btn { order: 1; }
        [dir="ltr"] .spacer-div { order: 3; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }

        h4.text-white { order: 2; flex-grow: 1; text-align: center; margin: 0; }

        .balance-card { padding: 30px; margin-top: -80px; position: relative; z-index: 20; text-align: center; }
        .balance-amount { font-size: 2.8rem; font-weight: 900; color: #22c55e; margin: 10px 0; }
        
        .transaction-item { padding: 15px; border-radius: 20px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--border); }
        .plus { border-inline-start: 6px solid #22c55e; }
        .minus { border-inline-start: 6px solid #ef4444; }

        .form-control-custom { background: var(--glass) !important; border: 1px solid var(--border); color: var(--text) !important; border-radius: 15px; padding: 12px; font-weight: 900; text-align: center; width: 100%; outline: none; }
        .btn-charge { background: #7597ef; color: #000; border: none; border-radius: 50px; padding: 14px; font-weight: 900; width: 100%; margin-top: 15px; transition: 0.3s; }
    </style>
</head>
<body>
    <div class="wallet-header shadow">

        <div class="spacer-div"></div>
        
        <h4 class="text-white fw-black m-0" data-i18n="wallet_header">محفظتي</h4>
        
        <a href="home.php" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="container">
        <div class="glass balance-card shadow-lg mx-2">
            <small class="opacity-75 fw-bold" data-i18n="avail_balance_label">الرصيد المتاح</small>
            <div class="balance-amount"><?= $currentBalance ?> <small class="fs-6">JOD</small></div>

            <div class="mt-4">
                <input type="number" id="amt" class="form-control-custom" value="10" placeholder="0.00">
                <button onclick="charge()" class="btn-charge shadow" data-i18n="btn_charge_wallet">شحن الرصيد الآن +</button>
            </div>
        </div>

        <h6 class="mt-5 px-3 fw-black mb-3"><i class="fas fa-history me-2 text-primary"></i> <span data-i18n="recent_ops_label">آخر العمليات</span></h6>
        
        <div class="px-2">
            <?php if ($transactions->num_rows > 0): ?>
                <?php while($row = $transactions->fetch_assoc()): 
                    $isCredit = ($row['TransactionType'] == 'credit');
                    $class = $isCredit ? 'plus' : 'minus';
                    $symbol = $isCredit ? '+' : '-';
                    $color = $isCredit ? 'text-success' : 'text-danger';
                ?>
                    <div class="glass transaction-item <?= $class ?> shadow-sm">
                        <div>
                            <h6 class="mb-0 fw-bold small">
                                <span class="lang-ar"><?= $row['Description_ar'] ?></span>
                                <span class="lang-en d-none"><?= $row['Description_en'] ?></span>
                            </h6>
                            <small class="opacity-50" style="font-size: 0.7rem;"><?= date('d M Y', strtotime($row['CreatedAt'])) ?></small>
                        </div>
                        <div class="<?= $color ?> fw-black"><?= $symbol . number_format($row['Amount'], 2) ?> JOD</div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="text-center opacity-50 py-4" data-i18n="no_trans">لا توجد عمليات سابقة</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="loadM" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content glass p-4 text-center border-0">
                <i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i>
                <h6 class="fw-bold" data-i18n="redirecting_t">جاري التحويل...</h6>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                wallet_page_title: "محفظتي | Clevo", wallet_header: "محفظة Clevo", avail_balance_label: "الرصيد المتاح حالياً", btn_charge_wallet: "شحن رصيد المحفظة +", recent_ops_label: "آخر العمليات", redirecting_t: "جاري التحويل لبوابة الدفع...", no_trans: "لا توجد عمليات سابقة"
            },
            en: {
                wallet_page_title: "My Wallet | Clevo", wallet_header: "Clevo Wallet", avail_balance_label: "Current Available Balance", btn_charge_wallet: "Top Up Wallet +", recent_ops_label: "Recent Transactions", redirecting_t: "Redirecting to gateway...", no_trans: "No transactions found"
            }
        };

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            
            if(lang === 'en') {
                document.querySelectorAll('.lang-ar').forEach(el => el.classList.add('d-none'));
                document.querySelectorAll('.lang-en').forEach(el => el.classList.remove('d-none'));
            }

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });
        };

        function charge() {
            const val = document.getElementById('amt').value;
            if (val <= 0) return;
            
            const loader = new bootstrap.Modal(document.getElementById('loadM'));
            loader.show();

            setTimeout(() => {
                localStorage.setItem('finalPrice', val);
                location.href = '../includes/payment-gateway.php';
            }, 1500);
        }
    </script>
    <script src="../assets/lamea.js?v=20260605b"></script>
</body>
</html>
