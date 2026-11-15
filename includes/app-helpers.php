<?php
require_once __DIR__."/database.php";
function clevoEnsureTrackingColumns(mysqli $conn): void
{
    clevo_require_schema($conn, 'booking', ['Driver_Lat','Driver_Lon','DriverAccessCode','DriverAccessCodeHash','DriverAccessCodeExpiresAt','DriverAccessCodeUsedAt']);
}

function clevoEnsureNotificationsTargetColumn(mysqli $conn): void
{
    clevo_require_schema($conn, 'notifications', ['target_role','target_url','title_en','body_en']);
}

function clevoEnsureServiceLanguageColumns(mysqli $conn): void
{
    clevo_require_schema($conn, 'services', ['Name_ar','Name_en','Description_ar','Description_en']);
}

function clevoStationImageUrl(?string $image): string
{
    $image = trim((string) $image);
    if (str_starts_with($image, 'private:')) return clevo_media_url($image);
    if ($image === '' || $image === 'default_station.png') {
        return '../assets/Images/stations/clevo-station-exterior.png';
    }
    if (preg_match('/^https?:\/\//i', $image)) {
        return $image;
    }

    $generatedStationImages = [
        'wash.jpg' => 'clevo-station-exterior.png',
        'Car-Wash.jpg' => 'clevo-station-tunnel.png',
        'Outside.jpg' => 'clevo-station-exterior.png',
        'Inside.jpg' => 'clevo-station-detailing.png',
        'AtHome.jpg' => 'clevo-station-home-service.png',
        'Delivary.jpg' => 'clevo-station-home-service.png',
        'OilChanges.jpg' => 'clevo-station-detailing.png',
        'carwash.png' => 'clevo-station-tunnel.png',
    ];
    if (isset($generatedStationImages[$image])) {
        return '../assets/Images/stations/' . rawurlencode($generatedStationImages[$image]);
    }

    if (str_contains($image, '/')) {
        return '../' . ltrim($image, '/');
    }
    if (is_file(__DIR__ . '/../uploads/stations/' . $image)) {
        return '../uploads/stations/' . rawurlencode($image);
    }
    if (is_file(__DIR__ . '/../assets/Images/stations/' . $image)) {
        return '../assets/Images/stations/' . rawurlencode($image);
    }
    if (is_file(__DIR__ . '/../assets/Images/' . $image)) {
        return '../assets/Images/' . rawurlencode($image);
    }
    return '../assets/uploads/' . rawurlencode($image);
}

function clevoEnsureWallet(mysqli $conn, int $userID): array
{
    // uq_wallet_user serializes first creation; no SELECT/INSERT race.
    clevo_stmt($conn,'INSERT INTO wallet (UserID,Balance) VALUES (?,0) ON DUPLICATE KEY UPDATE UserID=VALUES(UserID)','i',[$userID]);
    return clevo_stmt($conn,'SELECT WalletID,Balance FROM wallet WHERE UserID=?','i',[$userID])->get_result()->fetch_assoc();
}

function clevoGetLoyaltyPoints(mysqli $conn, int $userID): float
{
    $stmt = $conn->prepare("SELECT COALESCE(SUM(PointsBalance), 0) AS PointsBalance FROM loyalty WHERE UserID = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (float) ($row['PointsBalance'] ?? 0.0);
}

function clevoAddLoyaltyPoints(mysqli $conn, int $userID, float $points): void
{
    if ($points <= 0.0) {
        return;
    }

    $stmt = $conn->prepare("SELECT LoyaltyID FROM loyalty WHERE UserID = ? ORDER BY LoyaltyID ASC LIMIT 1");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        $update = $conn->prepare("UPDATE loyalty SET PointsBalance = PointsBalance + ? WHERE LoyaltyID = ?");
        $update->bind_param("di", $points, $row['LoyaltyID']);
        $update->execute();
        return;
    }

    $insert = $conn->prepare("INSERT INTO loyalty (UserID, PointsBalance) VALUES (?, ?)");
    $insert->bind_param("id", $userID, $points);
    $insert->execute();
}

function clevoSpendLoyaltyPoints(mysqli $conn, int $userID, float $points): bool
{
    if ($points<=0) return false;
    return clevo_db_atomic($conn,function()use($conn,$userID,$points):bool {
        $rows=clevo_stmt($conn,'SELECT LoyaltyID,PointsBalance FROM loyalty WHERE UserID=? ORDER BY LoyaltyID FOR UPDATE','i',[$userID])->get_result()->fetch_all(MYSQLI_ASSOC);
        $remaining=clevo_db_money($points);
        if (array_sum(array_map(fn($r)=>clevo_db_money($r['PointsBalance']),$rows))<$remaining) return false;
        foreach ($rows as $row) {
            $deduct=min($remaining,clevo_db_money($row['PointsBalance']));
            if ($deduct) clevo_stmt($conn,'UPDATE loyalty SET PointsBalance=PointsBalance-? WHERE LoyaltyID=? AND PointsBalance>=?','sis',[clevo_db_decimal($deduct),(int)$row['LoyaltyID'],clevo_db_decimal($deduct)]);
            $remaining-=$deduct;
            if (!$remaining) break;
        }
        return true;
    });
}

function clevoCalculateAdminCommission(float $totalPrice): float
{
    return round(max(0, $totalPrice) * 0.10, 2);
}

function clevoCalculateOwnerPayout(float $totalPrice, float $adminCommission): float
{
    return max(0, round($totalPrice - $adminCommission, 2));
}

function clevoCreditWallet(mysqli $conn, int $walletID, float $amount): bool
{
    if ($walletID <= 0 || $amount <= 0) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE wallet SET Balance = Balance + ? WHERE WalletID = ?");
    $stmt->bind_param("di", $amount, $walletID);
    return $stmt->execute();
}

function clevoDebitWallet(mysqli $conn, int $walletID, float $amount): bool
{
    if ($walletID <= 0 || $amount <= 0) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE wallet SET Balance = Balance - ? WHERE WalletID = ? AND Balance >= ?");
    $stmt->bind_param("did", $amount, $walletID, $amount);
    $stmt->execute();
    return $stmt->affected_rows > 0;
}

function clevoRecordWalletTransaction(mysqli $conn, int $walletID, float $amount, string $type, string $descriptionAr, string $descriptionEn): bool
{
    $stmt = $conn->prepare("INSERT INTO wallet_transactions (WalletID, Amount, TransactionType, Description_ar, Description_en) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("idsss", $walletID, $amount, $type, $descriptionAr, $descriptionEn);
    return $stmt->execute();
}

function clevoEnsureReviewUniqueBooking(mysqli $conn): void
{
    clevo_require_schema($conn, 'reviews', [], ['uq_reviews_booking']);
}

function clevoEnsureOwnerPayoutTables(mysqli $conn): void
{
    clevo_require_schema($conn, 'owner_bank_accounts', []);
    clevo_require_schema($conn, 'owner_withdrawal_requests', []);
}

function clevoGetOwnerBankAccount(mysqli $conn, int $ownerID): ?array
{
    clevoEnsureOwnerPayoutTables($conn);
    $stmt = $conn->prepare("SELECT * FROM owner_bank_accounts WHERE owner_id = ? LIMIT 1");
    $stmt->bind_param("i", $ownerID);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    return $account ?: null;
}

function clevoSaveOwnerBankAccount(mysqli $conn, int $ownerID, array $data): bool
{
    clevoEnsureOwnerPayoutTables($conn);
    $holder = clevo_clean_string($data['account_holder_name'] ?? '', 150);
    $bank = clevo_clean_string($data['bank_name'] ?? '', 150);
    $iban = strtoupper(preg_replace('/\s+/', '', clevo_clean_string($data['iban'] ?? '', 34)));
    $accountNumber = clevo_clean_string($data['account_number'] ?? '', 50);
    $swift = strtoupper(clevo_clean_string($data['swift_code'] ?? '', 20));
    $notes = clevo_clean_string($data['notes'] ?? '', 255);

    if ($ownerID <= 0 || $holder === '' || $bank === '' || $iban === '') {
        return false;
    }

    $stmt = $conn->prepare("INSERT INTO owner_bank_accounts
        (owner_id, account_holder_name, bank_name, iban, account_number, swift_code, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            account_holder_name = VALUES(account_holder_name),
            bank_name = VALUES(bank_name),
            iban = VALUES(iban),
            account_number = VALUES(account_number),
            swift_code = VALUES(swift_code),
            notes = VALUES(notes)");
    $stmt->bind_param("issssss", $ownerID, $holder, $bank, $iban, $accountNumber, $swift, $notes);
    return $stmt->execute();
}

function clevoRecordOwnerWithdrawalRequest(mysqli $conn, int $ownerID, int $walletID, float $amount, array $bankAccount): bool
{
    clevoEnsureOwnerPayoutTables($conn);
    if ($ownerID <= 0 || $walletID <= 0 || $amount <= 0 || empty($bankAccount)) {
        return false;
    }

    $snapshot = json_encode([
        'account_holder_name' => $bankAccount['account_holder_name'] ?? '',
        'bank_name' => $bankAccount['bank_name'] ?? '',
        'iban' => $bankAccount['iban'] ?? '',
        'account_number' => $bankAccount['account_number'] ?? '',
        'swift_code' => $bankAccount['swift_code'] ?? '',
        'notes' => $bankAccount['notes'] ?? '',
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("INSERT INTO owner_withdrawal_requests (owner_id, wallet_id, amount, bank_snapshot) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iids", $ownerID, $walletID, $amount, $snapshot);
    return $stmt->execute();
}

function clevoNotificationTextForMatching(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $repaired = function_exists('mb_convert_encoding')
        ? @mb_convert_encoding($text, 'UTF-8', 'Windows-1252')
        : '';

    return $text . "\n" . ($repaired ?: '');
}

function clevoNotificationFallbackEnglish(string $text, bool $isTitle = false): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (preg_match('/^[\x20-\x7E\s]+$/', $text)) {
        return $text;
    }

    $matchText = clevoNotificationTextForMatching($text);
    preg_match('/#[A-Za-z0-9_-]+/', $text, $matches);
    $orderNumber = $matches[0] ?? '';

    $contains = static function (array $needles) use ($matchText): bool {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($matchText, $needle)) {
                return true;
            }
        }
        return false;
    };

    if ($isTitle) {
        if ($contains(['طلب جديد'])) return 'New order';
        if ($contains(['تم قبول الطلب'])) return 'Order accepted';
        if ($contains(['رفض الطلب', 'رفض طلب'])) return 'Order rejected';
        if ($contains(['تم إنهاء الطلب'])) return 'Order completed';
        if ($contains(['تم إلغاء الحجز', 'تم إلغاء الطلب'])) return 'Order cancelled';
        if ($contains(['انتهت مهلة الطلب'])) return 'Request timed out';
        if ($contains(['تم تعديل موعد الحجز'])) return 'Booking rescheduled';
        if ($contains(['مهمة جديدة'])) return 'New task';
        if ($contains(['تم إلغاء المهمة'])) return 'Task cancelled';
        if ($contains(['تم تعديل موعد المهمة'])) return 'Task rescheduled';
        if ($contains(['رد جديد على تقييمك'])) return 'New reply to your review';
        if ($contains(['رد جديد من الدعم الفني', 'رد الدعم'])) return 'New support reply';
        if ($contains(['طلب تسجيل محطة جديدة'])) return 'New station registration request';
        if ($contains(['مستخدم جديد سجل في النظام'])) return 'New user registered in the system';
        if ($contains(['إشعارات قرب انتهاء الباقات'])) return 'Plan expiry notifications';
        if ($contains(['طلب سحب أرباح'])) return 'Withdrawal request';
        if ($contains(['تم إرسال طلب السحب'])) return 'Withdrawal request sent';
        return 'Notification';
    }

    if ($contains(['وصلك طلب'])) return trim("You received a new order $orderNumber.");
    if ($contains(['تم قبول طلبك']) && $contains(['باقتك'])) return trim("Your order $orderNumber was accepted and will be counted from your plan.");
    if ($contains(['تم قبول طلبك']) && $contains(['تعيين السائق'])) return trim("Your order $orderNumber was accepted and a driver was assigned. Please choose a payment method within 10 minutes.");
    if ($contains(['تم قبول طلبك'])) return trim("Your order $orderNumber was accepted. Please choose a payment method within 10 minutes.");
    if ($contains(['تم رفض طلبك', 'نعتذر، تم رفض'])) return trim("Sorry, your order $orderNumber was rejected by the station. You can book another station.");
    if ($contains(['تم إنهاء طلبك', 'يمكنك الآن تقييم الخدمة'])) return trim("Your order $orderNumber was completed. You can now rate the service.");
    if ($contains(['تم إنهاء وتسليم الطلب'])) return trim("Order $orderNumber was completed and delivered.");
    if ($contains(['قام العميل بإلغاء الطلب'])) return trim("The customer cancelled order $orderNumber.");
    if ($contains(['تم إلغاء الطلب', 'من قبل العميل'])) return trim("Order $orderNumber was cancelled by the customer.");
    if ($contains(['تم إلغاء طلبك']) && $contains(['حفظ المبلغ'])) return trim("Your order $orderNumber was cancelled and the due amount or credit was saved to your account.");
    if ($contains(['تم إلغاء طلبك'])) return trim("Your order $orderNumber was cancelled successfully.");
    if ($contains(['لم يتم الرد على طلبك'])) return trim("Your order $orderNumber was not answered within 10 minutes. You can book another station now.");
    if ($contains(['الدفع لم يكتمل', 'لم يكتمل خلال 10 دقائق'])) return trim("Your order $orderNumber was cancelled because payment was not completed within 10 minutes after acceptance.");
    if ($contains(['تم تعديل موعد الطلب'])) return trim("Order $orderNumber was rescheduled. Please review it again.");
    if ($contains(['تم حفظ الموعد الجديد'])) return trim("The new time for order $orderNumber was saved while keeping the payment method and dues.");
    if ($contains(['لديك مهمة جديدة'])) return trim("You have a new task for order $orderNumber.");
    if ($contains(['وصلك تقييم جديد'])) return trim("You received a new review for order $orderNumber.");
    if ($contains(['قام صاحب الخدمة بالرد'])) return 'The service owner replied to your review.';
    if ($contains(['قام المسؤول بالرد'])) return 'Support replied to your ticket.';
    if ($contains(['بقبول الطلب'])) return trim("A station owner accepted order $orderNumber.");
    if ($contains(['برفض الطلب'])) return trim("A station owner rejected order $orderNumber.");
    if ($contains(['تم إرسال', 'إشعار', 'المستخدمين', 'باقاتهم'])) return 'Plan expiry notifications were sent to users.';
    if ($contains(['طلب سحب أرباح'])) return 'A station owner submitted a withdrawal request.';
    if ($contains(['تم إرسال طلب السحب'])) return 'Your withdrawal request was sent and is pending admin review.';

    return '';
}

function clevoCreateNotification(mysqli $conn, int $userID, string $title, string $body, string $type = 'info', ?string $targetUrl = null, ?string $titleEn = null, ?string $bodyEn = null): bool
{
    if ($userID <= 0) {
        return false;
    }

    $allowed = ['info', 'success', 'warning', 'danger'];
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    clevoEnsureNotificationsTargetColumn($conn);
    $titleEn = trim((string) $titleEn) !== '' ? $titleEn : clevoNotificationFallbackEnglish($title, true);
    $bodyEn = trim((string) $bodyEn) !== '' ? $bodyEn : clevoNotificationFallbackEnglish($body, false);

    $depths=&clevo_db_depths();
    if (($depths[$conn]??0)>0) return clevo_queue_notification($conn,$userID,null,$title,$body,$type,$targetUrl,$titleEn,$bodyEn);
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, title_en, body, body_en, type, target_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param("issssss", $userID, $title, $titleEn, $body, $bodyEn, $type, $targetUrl);
    return $stmt->execute();
}

function clevoCreateRoleNotification(mysqli $conn, string $targetRole, string $title, string $body, string $type = 'info', ?string $targetUrl = null, ?string $titleEn = null, ?string $bodyEn = null): bool
{
    $targetRole = trim($targetRole);
    if ($targetRole === '') {
        return false;
    }

    $allowed = ['info', 'success', 'warning', 'danger'];
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    clevoEnsureNotificationsTargetColumn($conn);
    $titleEn = trim((string) $titleEn) !== '' ? $titleEn : clevoNotificationFallbackEnglish($title, true);
    $bodyEn = trim((string) $bodyEn) !== '' ? $bodyEn : clevoNotificationFallbackEnglish($body, false);

    $depths=&clevo_db_depths();
    if (($depths[$conn]??0)>0) return clevo_queue_notification($conn,null,$targetRole,$title,$body,$type,$targetUrl,$titleEn,$bodyEn);
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, target_role, title, title_en, body, body_en, type, target_url) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param("sssssss", $targetRole, $title, $titleEn, $body, $bodyEn, $type, $targetUrl);
    return $stmt->execute();
}

function clevoSaveUploadedProfileImage(array $file, string $prefix, int $userID, int $maxSize = 5242880): ?string
{
    if ($userID !== clevo_current_user_id() || $userID <= 0) throw new ClevoSecurityException('forbidden', 403);
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    return clevo_save_upload($file, 'profiles');
}

function clevoCreditOwnerForBooking(mysqli $conn, int $bookingID): void
{
    clevo_db_atomic($conn,function()use($conn,$bookingID):void {
        $b=clevo_stmt($conn,'SELECT b.*,COALESCE(b.OwnerIDSnapshot,s.UserID) AS OwnerID FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=? FOR UPDATE','i',[$bookingID])->get_result()->fetch_assoc();
        if (!$b || !in_array($b['Payment_Method'],['wallet','points','plan','visa'],true) || in_array($b['Status'],['Cancelled','Rejected','Cancelled_Timeout'],true)) return;
        if ($b['Payment_Method']==='plan' && $b['Status']!=='Completed') return;
        if ($b['Payment_Method']==='plan') clevo_assert_booking_payment($conn,$b);
        if ($b['Payment_Method']!=='plan' && !clevo_stmt($conn,"SELECT PaymentID FROM payments WHERE BookingID=? AND Status='recorded'",'i',[$bookingID])->get_result()->fetch_assoc()) return;
        $wallet=clevoEnsureWallet($conn,(int)$b['OwnerID']); $walletID=(int)$wallet['WalletID'];
        $reference=clevoBookingReference($b,$bookingID);
        if (clevoBookingHasWalletTransaction($conn,$walletID,"Owner booking earning #$reference") || clevoBookingHasWalletTransaction($conn,$walletID,"Owner booking earning #$bookingID")) return;
        if (!clevo_db_effect($conn,$bookingID,'owner_payout')) return;
        $cents=max(0,clevo_db_money($b['Total_Price'])-clevo_db_money($b['Admin_Commission']??'0'));
        clevo_wallet_entry($conn,$walletID,$cents,'credit',"booking:$bookingID:owner_earning",$bookingID,"Owner booking earning #$reference");
    });
}

function clevoProfileImageUrl(?string $image, string $name = 'User'): string
{
    $image = trim((string) $image);
    if (str_starts_with($image, 'private:')) return clevo_media_url($image);
    if ($image === '' || $image === 'default_user.png') {
        return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=0ea5e9&color=fff';
    }
    if (preg_match('/^https?:\/\//i', $image)) {
        return $image;
    }
    if (str_contains($image, '/')) {
        $relativeImage = ltrim($image, '/');
        if (is_file(__DIR__ . '/../' . $relativeImage)) {
            return clevoPublicAssetUrl($relativeImage);
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=0ea5e9&color=fff';
    }
    if (is_file(__DIR__ . '/../uploads/profiles/' . $image)) {
        return clevoPublicAssetUrl('uploads/profiles/' . rawurlencode($image));
    }
    if (is_file(__DIR__ . '/../assets/Images/' . $image)) {
        return clevoPublicAssetUrl('assets/Images/' . rawurlencode($image));
    }

    return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=0ea5e9&color=fff';
}

function clevoPublicAssetUrl(string $path): string
{
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $basePath = preg_replace('#/(admin|driver|owner|user|general|Emails|includes)$#', '', $scriptDir) ?: '';
    $basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');

    return $basePath . '/' . ltrim($path, '/');
}
?>
