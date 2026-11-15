<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (clevo_env('APP_ENV') !== 'testing' || clevo_env('DB_HOST') !== '127.0.0.1'
    || !preg_match('/^clevo_security_test_[a-f0-9]{12}$/D', clevo_env('DB_DATABASE', '') ?? '')) {
    fwrite(STDERR, "Refusing integration tests outside the isolated test database. Run tests/run-isolated.ps1.\n"); exit(2);
}
clevo_validate_environment();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = clevo_env('DB_DATABASE');
$setup = new mysqli(clevo_env('DB_HOST'), clevo_env('DB_USERNAME'), clevo_env('DB_PASSWORD', ''), '', (int) clevo_env('DB_PORT'));
$setup->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
$conn = clevo_connect_database();
$schema = file_get_contents(__DIR__ . '/../migrations/000_legacy_schema.sql');
preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?[\s\S]*?;/i', $schema, $tables);
foreach ($tables[0] as $statement) $conn->query($statement);
$conn->query('ALTER TABLE `user` ADD CONSTRAINT user_station_fk FOREIGN KEY (StationID) REFERENCES stations (StationID) ON DELETE SET NULL');

function command(array $args): array
{
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    return [$exit, $stdout, $stderr];
}
[$code, $output] = command([PHP_BINARY, __DIR__ . '/../scripts/migrate-security.php']);
check($code === 0 && str_contains($output, 'applied'), 'Additive security migration applies to base schema');
[$code, $output] = command([PHP_BINARY, __DIR__ . '/../scripts/migrate-security.php']);
check($code === 0 && str_contains($output, 'already applied'), 'Migration rerun is idempotent');
if ($code !== 0) finish_tests('Security integration suite');
[$dbCode, $dbOutput] = command([PHP_BINARY, __DIR__ . '/../scripts/migrate-database.php', '--apply', '--maintenance']);
check($dbCode === 0, 'Database integrity migration applies before compatibility checks');
$runtimeUser = clevo_env('TEST_RUNTIME_USERNAME', '') ?? '';
$runtimePassword = clevo_env('TEST_RUNTIME_PASSWORD', '') ?? '';
if (!preg_match('/^clevo_rt_[a-f0-9]{12}$/D', $runtimeUser) || !preg_match('/^[a-f0-9]{64}$/D', $runtimePassword)) throw new RuntimeException('Invalid isolated runtime credentials.');
$setup->query("CREATE USER '$runtimeUser'@'127.0.0.1' IDENTIFIED BY '$runtimePassword'");
foreach ($conn->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as [$table]) {
    if (in_array($table,['security_migration_steps','database_migration_steps'],true)) continue;
    $permissions=$table==='security_schema_migrations'?'SELECT':($table==='audit_logs'?'INSERT':($table==='wallet_transactions'?'SELECT, INSERT':'SELECT, INSERT, UPDATE'));
    $setup->query("GRANT $permissions ON `$database`.`$table` TO '$runtimeUser'@'127.0.0.1'");
}
$setup->query("GRANT DELETE ON `$database`.blacklist TO '$runtimeUser'@'127.0.0.1'");
$limited = new mysqli('127.0.0.1', $runtimeUser, $runtimePassword, $database, (int) clevo_env('DB_PORT'));
require_once __DIR__ . '/../includes/app-helpers.php';
require_once __DIR__ . '/../includes/booking-helpers.php';
require_once __DIR__ . '/../includes/support-helpers.php';
foreach (['clevoEnsureTrackingColumns','clevoEnsureNotificationsTargetColumn','clevoEnsureServiceLanguageColumns','clevoEnsureReviewUniqueBooking','clevoEnsureOwnerPayoutTables',
    'clevoEnsureBookingServicesTable','clevoEnsureBookingPaymentColumns','clevoEnsureBookingWorkflowColumns','clevoEnsurePlanColumns','clevoEnsureSupportTicketsGuestEmailColumn','clevoEnsureSupportMessagesTable'] as $helper) {
    $helper($limited); check(true, 'Runtime helper needs no DDL privilege: ' . $helper);
}
rejects(fn() => $limited->query('CREATE TABLE should_not_be_created (id INT)'), 'Runtime database principal has no CREATE privilege');
rejects(fn() => $limited->query('DELETE FROM booking WHERE BookingID=-1'), 'Runtime principal cannot delete booking history');
rejects(fn() => $limited->query('UPDATE audit_logs SET Action=\'changed\' WHERE AuditID=0'), 'Runtime principal cannot rewrite audit records');

$password = 'Aa!' . bin2hex(random_bytes(12));
function user_fixture(string $role, int $number, string $password): int
{
    global $conn;
    clevo_stmt($conn, "INSERT INTO user (UserName, Email, PhoneNumber, Password, Role, AccountStatus, EmailVerifiedAt) VALUES (?, ?, ?, ?, ?, 'active', UTC_TIMESTAMP())", 'sssss',
        ['Test ' . $role . $number, $role . $number . '@example.test', '079' . str_pad((string) $number, 7, '0', STR_PAD_LEFT), password_hash($password, PASSWORD_DEFAULT), $role]);
    return (int) $conn->insert_id;
}
$customer = user_fixture('customer', 1, $password); $other = user_fixture('customer', 2, $password);
$owner = user_fixture('owner', 3, $password); $otherOwner = user_fixture('owner', 4, $password);
$driver = user_fixture('driver', 5, $password); $otherDriver = user_fixture('driver', 6, $password);
$admin = user_fixture('Admin', 7, $password); $admin2 = user_fixture('Admin', 8, $password);
$legacy = user_fixture('User', 9, $password);
foreach ([$owner, $otherOwner] as $who) clevo_stmt($conn, "INSERT INTO stations (UserID, Name, admin_status, Status) VALUES (?, 'Test station', 'approved', 'Active')", 'i', [$who]);
$station = (int) $conn->query('SELECT MIN(StationID) AS id FROM stations')->fetch_assoc()['id']; $station2 = $station + 1;
clevo_stmt($conn, 'UPDATE user SET StationID = ? WHERE UserID IN (?, ?)', 'iii', [$station, $owner, $driver]);
clevo_stmt($conn, 'UPDATE user SET StationID = ? WHERE UserID IN (?, ?)', 'iii', [$station2, $otherOwner, $otherDriver]);
foreach ([$station, $station2] as $where) clevo_stmt($conn, "INSERT INTO services (StationID, Name, Name_ar, Name_en, Price, is_station, is_pickup) VALUES (?, 'Test service', 'Test', 'Test', 10, 1, 1)", 'i', [$where]);
$service = (int) $conn->query('SELECT MIN(ServiceID) AS id FROM services')->fetch_assoc()['id'];
foreach ([[$customer, $station, $driver], [$other, $station2, $otherDriver]] as [$who, $where, $whoDriver]) {
    clevo_stmt($conn, "INSERT INTO booking (UserID, StationID, ServiceID, DriverID, Service_Location, Status, Total_Price, Admin_Commission, Payment_Method) VALUES (?, ?, ?, ?, 'delivery', 'Accepted', 10, 1, 'wallet')", 'iiii', [$who, $where, $where === $station ? $service : $service + 1, $whoDriver]);
}
$booking = (int) $conn->query('SELECT MIN(BookingID) AS id FROM booking')->fetch_assoc()['id']; $booking2 = $booking + 1;
clevo_stmt($conn, 'INSERT INTO wallet (UserID, Balance) VALUES (?, 20), (?, 99)', 'ii', [$customer, $other]);
clevo_stmt($conn, "INSERT INTO notifications (user_id, title, body) VALUES (?, 'Own notification', 'own'), (?, 'Other notification', 'private')", 'ii', [$customer, $other]);
$notification = (int) $conn->query('SELECT MIN(id) AS id FROM notifications')->fetch_assoc()['id']; $notification2 = $notification + 1;
$conn->query("INSERT INTO notifications (target_role, title, body) VALUES ('Admin', 'Broadcast', 'role event')"); $broadcast = (int) $conn->insert_id;
$conn->query("INSERT INTO reviews (BookingID, Rating, Comment) VALUES ($booking, 5, 'Test review')"); $review = (int) $conn->insert_id;

$base = rtrim(clevo_env('APP_URL'), '/');
function http(string $method, string $path, ?array $data = null, ?string $token = null, ?string $jar = null, array $extra = [], bool $form = false): array
{
    global $base;
    $curl = curl_init($base . $path); $headers = $extra;
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($data !== null) $headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
    if ($data !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $form ? http_build_query($data) : json_encode($data));
    if ($jar) { curl_setopt($curl, CURLOPT_COOKIEFILE, $jar); curl_setopt($curl, CURLOPT_COOKIEJAR, $jar); }
    $receivedHeaders = '';
    curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use (&$receivedHeaders): int { $receivedHeaders .= $line; return strlen($line); });
    $body = curl_exec($curl); $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    return [$code, is_string($body) ? json_decode($body, true) : null, (string) $body, $receivedHeaders];
}
function login_api(string $login, string $password): array
{
    [$code, $json] = http('POST', '/api/v1/auth/login', ['login' => $login, 'password' => $password]);
    check($code === 200 && isset($json['data']['access_token'], $json['data']['refresh_token']), 'API login issues tokens for ' . preg_replace('/\d+.*/', '', $login));
    return $json['data'] ?? [];
}
function csrf(string $jar): string
{
    [$code, , $body] = http('GET', '/general/login.php', null, null, $jar);
    check($code === 200, 'Web login GET stays available');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $body, $match);
    return $match[1] ?? '';
}
function inline_js_valid(string $html): bool
{
    $process = proc_open(['node', __DIR__ . '/check-inline-js.js'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, dirname(__DIR__));
    fwrite($pipes[0], $html); fclose($pipes[0]); $result = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); return proc_close($process) === 0 && str_starts_with($result, 'valid_');
}

$conn->query("UPDATE security_schema_migrations SET Version='003_missing_simulation' WHERE Version='003_required_relationships'");
[$status]=http('GET','/general/login.php');check($status===503,'Application refuses traffic when database expansion journal is incomplete');
$conn->query("UPDATE security_schema_migrations SET Version='003_required_relationships' WHERE Version='003_missing_simulation'");
$pair = login_api('customer1@example.test', $password); $access = $pair['access_token'] ?? '';
$pairOther = login_api('customer2@example.test', $password);
$ownerPair = login_api('owner3@example.test', $password); $driverPair = login_api('driver5@example.test', $password);
$adminPair = login_api('Admin7@example.test', $password); login_api('User9@example.test', $password);
[$code] = http('GET', '/api/v1/me', null, $access); check($code === 200, 'Access JWT authorizes current account');
[$code] = http('GET', '/api/v1/me', null, 'bad.token.value'); check($code === 401, 'Invalid explicit bearer rejected');
[$code] = http('GET', '/api/v1/me'); check($code === 401, 'API never authenticates with absent bearer');
[$code] = http('POST', '/api/v1/me', [], $access); check($code === 405, 'Known protected resource enforces HTTP method');
[$code, $json] = http('GET', '/api/v1/bookings/' . $booking, null, $access);
check($code === 200 && !isset($json['data']['booking']['DriverAccessCodeHash'], $json['data']['booking']['UserID']), 'Booking DTO omits internal credentials/identity metadata');
[$code] = http('GET', '/api/v1/bookings/' . $booking2, null, $access); check($code === 404, 'Customer cannot read another booking');
[$code, $json] = http('GET', '/api/v1/me/wallet?user_id=' . $other, null, $access);
check($code === 200 && (float) $json['data']['wallet']['Balance'] === 20.0, 'Wallet ignores arbitrary user selector');
[$code, $json] = http('GET', '/api/v1/notifications?user_id=' . $other, null, $access);
check($code === 200 && count($json['data']['notifications']) === 1 && $json['data']['notifications'][0]['title'] === 'Own notification', 'Notifications scoped to current user');
[$code] = http('PATCH', '/api/v1/notifications/' . $notification2 . '/read', [], $access); check($code === 404, 'Other notification cannot be marked read');
[$code] = http('GET', '/api/v1/owner/stations/' . $station . '/services', null, $ownerPair['access_token']); check($code === 200, 'Owner reads own services');
[$code] = http('GET', '/api/v1/owner/stations/' . $station2 . '/services', null, $ownerPair['access_token']); check($code === 404, 'Owner cannot read another station services');
[$code] = http('GET', '/api/v1/owner/stations/' . $station . '/services', null, $access); check($code === 403, 'Customer denied owner endpoint');
[$code] = http('GET', '/api/v1/driver/tasks/' . $booking, null, $driverPair['access_token']); check($code === 200, 'Driver reads assigned job');
[$code] = http('GET', '/api/v1/driver/tasks/' . $booking2, null, $driverPair['access_token']); check($code === 404, 'Driver denied unassigned job');
[$code] = http('GET', '/api/v1/admin/security-status', null, $access); check($code === 403, 'Customer denied admin endpoint');
[$code] = http('GET', '/api/v1/admin/security-status', null, $adminPair['access_token']); check($code === 200, 'Admin role preserved');

$commerceBody=['station_id'=>$station,'service_ids'=>[$service],'service_location'=>'station','booking_date'=>(new DateTimeImmutable('+2 days'))->format('Y-m-d'),'booking_time'=>'10:00'];
$commerceKey=bin2hex(random_bytes(16));
[$code,$json]=http('POST','/api/v1/bookings',$commerceBody,$access,null,['Idempotency-Key: '.$commerceKey]);
$commerceBooking=(int)($json['data']['booking_id']??0);check($code===200&&$commerceBooking>0,'Bearer booking API uses shared transactional service');
[$code,$json]=http('POST','/api/v1/bookings',$commerceBody,$access,null,['Idempotency-Key: '.$commerceKey]);
check($code===200&&(int)($json['data']['booking_id']??0)===$commerceBooking,'HTTP booking retry replays committed result');
[$code]=http('POST','/api/v1/bookings',array_replace($commerceBody,['booking_time'=>'11:00']),$access,null,['Idempotency-Key: '.$commerceKey]);check($code===409,'HTTP reused key with changed payload conflicts');
[$code]=http('POST','/api/v1/bookings',$commerceBody,$ownerPair['access_token'],null,['Idempotency-Key: '.bin2hex(random_bytes(16))]);check($code===403,'Owner cannot create customer booking through mobile API');
$conn->query("UPDATE booking SET Status='Accepted' WHERE BookingID=$commerceBooking");
[$code]=http('POST',"/api/v1/bookings/$commerceBooking/payments",['method'=>'wallet'],$pairOther['access_token'],null,['Idempotency-Key: '.bin2hex(random_bytes(16))]);check($code===404,'Payment API denies another customer booking');
$paymentKey=bin2hex(random_bytes(16));
[$code]=http('POST',"/api/v1/bookings/$commerceBooking/payments",['method'=>'wallet'],$access,null,['Idempotency-Key: '.$paymentKey]);check($code===200,'Mobile wallet payment commits through runtime DML principal');
[$code]=http('POST',"/api/v1/bookings/$commerceBooking/payments",['method'=>'wallet'],$access,null,['Idempotency-Key: '.$paymentKey]);
check($code===200&&(float)$conn->query("SELECT Balance FROM wallet WHERE UserID=$customer")->fetch_assoc()['Balance']===10.0,'HTTP payment replay does not debit twice');

[$code] = http('OPTIONS', '/api/v1/auth/login', null, null, null, ['Origin: https://app.example.test']); check($code === 204, 'Approved CORS preflight');
[$code] = http('OPTIONS', '/api/v1/auth/login', null, null, null, ['Origin: https://attacker.example.test']); check($code === 403, 'Unapproved CORS preflight denied');
[$code] = http('GET', '/api/v1/me', null, null, null, ['Origin: https://app.example.test']); check($code === 401, 'Allowed CORS origin does not authenticate');
[$code] = http('POST', '/api/v1/auth/login', ['login' => 'customer1@example.test', 'password' => $password], null, null, [], true); check($code === 415, 'Mobile auth requires JSON');

[$code, $json] = http('POST', '/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']]);
$rotated = $json['data'] ?? [];
check($code === 200 && ($rotated['refresh_token'] ?? '') !== $pair['refresh_token'], 'Refresh rotates one-time token');
[$code] = http('POST', '/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']]); check($code === 401, 'Refresh replay rejected');
[$code] = http('GET', '/api/v1/me', null, $rotated['access_token'] ?? ''); check($code === 401, 'Replay revokes access tokens in the family');
[$code] = http('POST', '/api/v1/auth/refresh', ['refresh_token' => $rotated['refresh_token'] ?? '']); check($code === 401, 'Replay revokes successor refresh token');
$pair = login_api('customer1@example.test', $password); $access = $pair['access_token'];
[$code] = http('POST', '/api/v1/auth/logout', [], $access); check($code === 200, 'API logout succeeds');
[$code] = http('GET', '/api/v1/me', null, $access); check($code === 401, 'Logout revokes access immediately');
[$code] = http('POST', '/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']]); check($code === 401, 'Logout revokes refresh');
$hashRow = $conn->query('SELECT TokenHash FROM auth_refresh_tokens LIMIT 1')->fetch_assoc();
check(strlen($hashRow['TokenHash']) === 64 && $hashRow['TokenHash'] !== $pair['refresh_token'], 'Database stores refresh hashes only');

$jar = __DIR__ . '/../.security-test/customer.cookies';
$csrf = csrf($jar);
$beforeCookie = file_get_contents($jar);
[$code] = http('POST', '/general/login.php', ['login_action' => 1, 'login_input' => 'customer1@example.test', 'password' => $password], null, $jar, [], true);
check($code === 403, 'Legacy login requires CSRF');
[$code] = http('POST', '/general/login.php', ['login_action' => 1, 'login_input' => 'customer1@example.test', 'password' => $password, 'csrf_token' => $csrf], null, $jar, [], true);
check($code === 302, 'Existing PHP login redirects successfully');
check(file_get_contents($jar) !== $beforeCookie, 'Web login rotates its session identifier');
[$code, , $body] = http('GET', '/user/profile.php', null, null, $jar); check($code === 200, 'Existing customer session reads profile');
foreach(['/user/home.php','/user/search-stations.php','/user/booking-history.php','/user/customer-plans.php','/user/user-wallet.php','/user/station-details.php?id='.$station] as $page){
    [$status,,$html]=http('GET',$page,null,null,$jar);check($status===200&&inline_js_valid($html),'Migrated customer page and JavaScript remain compatible: '.$page);
}
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $body, $m); $customerCsrf = $m[1] ?? '';
$webBody=['action'=>'create_booking','service_ids'=>json_encode([$service]),'service_location'=>'station','booking_date'=>(new DateTimeImmutable('+2 days'))->format('Y-m-d'),'booking_time'=>'12:00','csrf_token'=>$customerCsrf,'idempotency_key'=>bin2hex(random_bytes(16))];
[$status,$json]=http('POST','/user/station-details.php?id='.$station,$webBody,null,$jar,[],true);$webBooking=(int)($json['booking_id']??0);
check($status===200&&$webBooking>0,'Existing PHP booking form submits transactionally with CSRF and request key');
[$status,$json]=http('POST','/user/station-details.php?id='.$station,$webBody,null,$jar,[],true);check($status===200&&(int)($json['booking_id']??0)===$webBooking,'Existing PHP booking form retry does not duplicate booking');
clevo_accept_owner_booking($conn,$owner,$webBooking);
[$status,,$html]=http('GET','/includes/payment-method.php?id='.$webBooking,null,null,$jar);check($status===200&&inline_js_valid($html),'Existing payment page and generated request key JavaScript compile');
$webPayment=['action'=>'set_payment','method'=>'wallet','use_points'=>'0','csrf_token'=>$customerCsrf,'idempotency_key'=>bin2hex(random_bytes(16))];
[$status,$json]=http('POST','/includes/payment-method.php?id='.$webBooking,$webPayment,null,$jar,[],true);check($status===200&&($json['status']??'')==='success','Existing PHP wallet payment form commits');
[$status,$json]=http('POST','/includes/payment-method.php?id='.$webBooking,$webPayment,null,$jar,[],true);check($status===200&&(float)$conn->query("SELECT Balance FROM wallet WHERE UserID=$customer")->fetch_assoc()['Balance']===0.0,'PHP payment form retry debits once');
[$status]=http('POST','/includes/payment-gateway.php?id='.$webBooking,['action'=>'mark_visa_paid','csrf_token'=>$customerCsrf],null,$jar,[],true);check($status===503,'Browser card acknowledgement cannot manufacture funds in testing or production');
[$code] = http('GET', '/admin/admin-dashboard.php', null, null, $jar); check($code === 403, 'Web customer cannot enter admin area');
[$code] = http('POST', '/user/save-location.php', ['latitude' => 31, 'longitude' => 35], null, $jar, [], true); check($code === 403, 'Location write requires CSRF');
[$code] = http('GET', '/user/order-details.php?id=' . $booking2, null, null, $jar); check($code === 302, 'Web cross-customer order lookup denied');
[$code] = http('POST', '/includes/process-reviews.php', ['action' => 'submit_reply', 'review_id' => $review, 'reply_text' => 'not authorized', 'csrf_token' => $customerCsrf], null, $jar, [], true);
check($code === 403, 'Legacy insecure review route denies customer');
[$code] = http('POST', '/general/logout.php', ['csrf_token' => $customerCsrf], null, $jar, [], true); check($code === 302, 'Existing logout button POST works');

// Reset bypass, OTP attempt budget, single-use grant and revocation.
$resetJar = __DIR__ . '/../.security-test/reset.cookies'; $resetCsrf = csrf($resetJar);
[$code] = http('POST', '/general/send-reset-otp.php', ['email' => 'customer2@example.test', 'csrf_token' => $resetCsrf], null, $resetJar, [], true); check($code === 200, 'Legacy reset request succeeds generically');
[$code] = http('POST', '/general/login.php', ['reset_password_action' => 1, 'password' => $password, 'csrf_token' => $resetCsrf], null, $resetJar, [], true); check($code === 422, 'Cannot reset merely by requesting OTP');
$mail = clevo_decrypt_mail($conn->query('SELECT PayloadCipher FROM security_mail_queue ORDER BY ID DESC LIMIT 1')->fetch_assoc()['PayloadCipher']);
preg_match('/<strong>(\d{6})<\/strong>/', $mail['html'], $m); $otp = $m[1] ?? '';
check($conn->query("SELECT reset_otp FROM user WHERE UserID = $other")->fetch_assoc()['reset_otp'] === null, 'Legacy plaintext OTP field unused');
[$code] = http('POST', '/general/verify-reset-otp.php', ['otp' => $otp, 'csrf_token' => $resetCsrf], null, $resetJar, [], true); check($code === 200, 'Correct web OTP grants reset');
$newPassword = 'Bb!' . bin2hex(random_bytes(12));
[$code] = http('POST', '/general/login.php', ['reset_password_action' => 1, 'password' => $newPassword, 'csrf_token' => $resetCsrf], null, $resetJar, [], true); check($code === 200, 'Verified web reset succeeds');
[$code] = http('POST', '/general/login.php', ['reset_password_action' => 1, 'password' => $newPassword, 'csrf_token' => $resetCsrf], null, $resetJar, [], true); check($code === 422, 'Web reset grant cannot replay');
[$code] = http('GET', '/api/v1/me', null, $pairOther['access_token']); check($code === 401, 'Password reset revokes pre-existing API access');
check(clevo_verify_credentials($conn, 'customer2@example.test', $password) === null && clevo_verify_credentials($conn, 'customer2@example.test', $newPassword) !== null, 'Reset replaces modern password hash');
$challenge = clevo_forgot_password($conn, 'customer1@example.test');
$mail = clevo_decrypt_mail($conn->query('SELECT PayloadCipher FROM security_mail_queue ORDER BY ID DESC LIMIT 1')->fetch_assoc()['PayloadCipher']); preg_match('/<strong>(\d{6})<\/strong>/', $mail['html'], $m);
$realOtp = $m[1]; $wrong = $realOtp === '100000' ? '100001' : '100000';
for ($i = 0; $i < 5; $i++) rejects(fn() => clevo_verify_reset_otp($conn, $challenge, $wrong), 'Wrong OTP attempt rejected', 'invalid_challenge');
rejects(fn() => clevo_verify_reset_otp($conn, $challenge, $realOtp), 'OTP attempt budget locks challenge', 'invalid_challenge');
$challenge = clevo_new_challenge($conn, $customer, 'reset_otp', $realOtp, -1);
rejects(fn() => clevo_verify_reset_otp($conn, $challenge, $realOtp), 'Expired OTP rejected', 'invalid_challenge');

// Registration cannot choose privileged roles. Email verification is expiring/single-use.
foreach (['Admin', 'admin', 'driver', 'unknown'] as $badRole) {
    [$code] = http('POST', '/api/v1/auth/register', ['role' => $badRole, 'full_name' => 'Test', 'phone' => '0799999900', 'email' => 'public@example.test', 'password' => $password]);
    check($code === 422, 'Public API role injection denied: ' . $badRole);
}
[$code] = http('POST', '/api/v1/auth/register', ['role' => 'customer', 'full_name' => 'New customer', 'phone' => '0799999901', 'email' => 'new@example.test', 'password' => $password]); check($code === 201, 'Public customer registration preserved');
$mail = clevo_decrypt_mail($conn->query('SELECT PayloadCipher FROM security_mail_queue ORDER BY ID DESC LIMIT 1')->fetch_assoc()['PayloadCipher']);
preg_match('/#token=([a-f0-9.]+)/', $mail['html'], $m); $verify = $m[1] ?? '';
check($verify !== '' && !str_contains($mail['html'], '?token='), 'Verification secret uses fragment rather than logged URL query');
[$code] = http('POST', '/api/v1/auth/email/verify', ['token' => $verify]); check($code === 200, 'Email verification activates customer');
[$code] = http('POST', '/api/v1/auth/email/verify', ['token' => $verify]); check($code === 422, 'Email verification is single-use');

// Single-use driver code is hashed, expires, and is bound to exactly one assignment.
$code = clevo_issue_driver_code($conn, $owner, $booking);
$row = clevo_stmt($conn, 'SELECT DriverAccessCode, DriverAccessCodeHash FROM booking WHERE BookingID = ?', 'i', [$booking])->get_result()->fetch_assoc();
check($row['DriverAccessCode'] === null && password_verify($code, $row['DriverAccessCodeHash']), 'Driver code stored only as password hash');
[$status, $json] = http('POST', '/api/v1/auth/driver-exchanges', ['login' => 'driver5@example.test', 'code' => $code]); $driverCodePair = $json['data'] ?? [];
check($status === 200, 'Driver code exchange works');
clevo_stmt($conn, "INSERT INTO booking (UserID, StationID, ServiceID, DriverID, Service_Location, Status, Total_Price, Payment_Method) VALUES (?, ?, ?, ?, 'delivery', 'Accepted', 10, 'wallet')", 'iiii', [$customer, $station, $service, $driver]);
$extraDriverJob = (int) $conn->insert_id;
[$status] = http('GET', '/api/v1/driver/tasks/' . $extraDriverJob, null, $driverCodePair['access_token']);
check($status === 404, 'Task-code session cannot read a different job assigned to the same driver');
[$status] = http('POST', '/api/v1/auth/driver-exchanges', ['login' => 'driver5@example.test', 'code' => $code]); check($status === 401, 'Driver code replay rejected');
rejects(fn() => clevo_issue_driver_code($conn, $otherOwner, $booking), 'Other owner cannot issue task grant', 'not_found');
clevo_stmt($conn, "UPDATE user SET AccountStatus = 'rejected' WHERE UserID = ?", 'i', [$driver]);
[$status] = http('GET', '/api/v1/driver/tasks/' . $booking, null, $driverCodePair['access_token']); check($status === 401, 'Account restriction immediately blocks assigned-job token');
clevo_stmt($conn, "UPDATE user SET AccountStatus = 'active' WHERE UserID = ?", 'i', [$driver]); clevo_revoke_user($conn, $driver);

// Actual multipart upload and object-protected private document download.
$ownerJar = __DIR__ . '/../.security-test/owner.cookies'; $ownerCsrf = csrf($ownerJar);
[$status] = http('POST', '/general/login.php', ['login_action' => 1, 'login_input' => 'owner3@example.test', 'password' => $password, 'csrf_token' => $ownerCsrf], null, $ownerJar, [], true); check($status === 302, 'Existing owner login preserved');
[$status, , $body] = http('GET', '/owner/add-station.php', null, null, $ownerJar); check($status === 200, 'Owner station page loads');
check(inline_js_valid($body), 'Rendered owner station JavaScript compiles');
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $body, $m); $ownerCsrf = $m[1] ?? '';
$png = __DIR__ . '/../.security-test/upload.png'; file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9a0AAAAASUVORK5CYII='));
$curl = curl_init($base . '/owner/add-station.php'); curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $ownerJar, CURLOPT_POSTFIELDS => [
    'ajax_save' => 1, 'csrf_token' => $ownerCsrf, 'stName' => 'Test station', 'stLocation' => 'Test', 'stLat' => '31', 'stLon' => '35', 'stLocationUrl' => '', 'openTime' => '08:00', 'closeTime' => '22:00', 'stImage' => new CURLFile($png, 'image/png', 'photo.png')]]);
$uploadResult = curl_exec($curl); $uploadStatus = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
check($uploadStatus === 200 && (json_decode($uploadResult, true)['status'] ?? '') === 'success', 'Validated multipart station upload preserves web flow');
$image = clevo_stmt($conn, 'SELECT Image FROM stations WHERE StationID = ?', 'i', [$station])->get_result()->fetch_assoc()['Image'];
check(str_starts_with($image, 'private:stations:'), 'New images stored outside public upload paths');
[$status] = http('GET', '/includes/media.php?ref=' . rawurlencode($image)); check($status === 200, 'Validated public station image served safely');
$privateName = bin2hex(random_bytes(24)) . '.png'; $privateDir = clevo_env('PRIVATE_STORAGE_PATH') . '/verification'; if (!is_dir($privateDir)) mkdir($privateDir, 0700, true); copy($png, $privateDir . '/' . $privateName);
$reference = 'private:verification:' . $privateName; clevo_stmt($conn, 'UPDATE user SET VerificationFile = ? WHERE UserID = ?', 'si', [$reference, $owner]);
[$status] = http('GET', '/includes/media.php?ref=' . rawurlencode($reference)); check($status === 302, 'Private document requires login');
[$status] = http('GET', '/includes/media.php?user_id=' . $owner, null, null, $ownerJar); check($status === 403, 'Document admin selector cannot be used by owner');
[$status] = http('GET', '/.env'); check($status === 403, 'Test deployment denies environment download');
[$status] = http('GET', '/clevo.sql'); check($status === 403, 'Test deployment denies SQL dump download');

// Other existing PHP roles and object-level mutation boundaries.
$adminJar = __DIR__ . '/../.security-test/admin.cookies'; $adminCsrf = csrf($adminJar);
[$status] = http('POST', '/general/login.php', ['login_action' => 1, 'login_input' => 'Admin7@example.test', 'password' => $password, 'csrf_token' => $adminCsrf], null, $adminJar, [], true);
check($status === 302, 'Existing admin login preserved');
[$status, , $body] = http('GET', '/admin/admin-dashboard.php', null, null, $adminJar);
check($status === 200 && inline_js_valid($body), 'Admin dashboard and inline JavaScript remain valid');
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $body, $m); $adminCsrf = $m[1] ?? '';
[$status] = http('GET', '/includes/media.php?user_id=' . $owner, null, null, $adminJar); check($status === 200, 'Admin can review authorized private verification document');
$driverJar = __DIR__ . '/../.security-test/driver.cookies'; $driverCsrf = csrf($driverJar);
[$status] = http('POST', '/general/login.php', ['login_action' => 1, 'login_input' => 'driver5@example.test', 'password' => $password, 'csrf_token' => $driverCsrf], null, $driverJar, [], true); check($status === 302, 'Existing driver password login preserved');
[$status, , $body] = http('GET', '/driver/driver-dashboard.php', null, null, $driverJar); check($status === 200 && inline_js_valid($body), 'Driver dashboard and inline JavaScript remain valid');
[$status] = http('GET', '/user/get_order_status.php?id=' . $booking, null, null, $driverJar);
check($status === 404, 'Legacy tracking respects the driver session assignment');
preg_match('/const csrfToken = "([a-f0-9]+)"/', $body, $m);
if (!isset($m[1])) preg_match('/csrf_token[^\n]*?"([a-f0-9]{64})"/', $body, $m);
$driverCsrf = $m[1] ?? '';
check($driverCsrf !== '', 'Driver mutation test uses a valid CSRF token');
[$status] = http('POST', '/driver/driver-dashboard.php', ['action' => 'update_status', 'order_id' => $booking2, 'status' => 'Completed', 'csrf_token' => $driverCsrf], null, $driverJar, [], true);
check($status === 404, 'Driver web mutation cannot target another task');
[$status] = http('POST', '/owner/manage-services.php', ['save_service' => 1, 'edit_id' => $service + 1, 'name_ar' => 'Changed', 'name_en' => 'Changed', 'price' => 999, 'csrf_token' => $ownerCsrf], null, $ownerJar, [], true);
check((float) $conn->query('SELECT Price FROM services WHERE ServiceID=' . ($service + 1))->fetch_assoc()['Price'] === 10.0, 'Owner cannot mutate another station service');
foreach (['/owner/incoming-orders.php', '/owner/owner-reviews.php', '/owner/manage-drivers.php','/owner/owner-wallet.php','/owner/manage-services.php'] as $page) {
    [$status, , $html] = http('GET', $page, null, null, $ownerJar);
    check($status === 200 && inline_js_valid($html), 'Owner page/inline scripts valid: ' . $page);
}
[$status, , $html] = http('GET', '/admin/notifications.php', null, null, $adminJar);
check($status === 200 && inline_js_valid($html), 'Admin notification page/inline scripts valid');
$publicJar = __DIR__ . '/../.security-test/public.cookies'; $publicCsrf = csrf($publicJar);
$countBefore = (int) $conn->query('SELECT COUNT(*) AS c FROM user')->fetch_assoc()['c'];
[$status] = http('POST', '/general/register.php', ['register_action' => 1, 'role' => 'Admin', 'full_name' => 'Public attempt', 'email' => 'attempt@example.test', 'phone_suffix' => '99999999', 'password' => $password, 'confirm_password' => $password, 'csrf_token' => $publicCsrf], null, $publicJar, [], true);
check($status === 200 && (int) $conn->query('SELECT COUNT(*) AS c FROM user')->fetch_assoc()['c'] === $countBefore, 'Legacy registration cannot self-provision an administrator');
[$status, , $html, $responseHeaders] = http('GET', '/general/login.php', null, null, __DIR__ . '/../.security-test/cookie-flags-' . $database . '.cookies');
check(str_contains(strtolower($responseHeaders), 'httponly') && str_contains(strtolower($responseHeaders), 'samesite=lax'), 'Web session cookies are HttpOnly and SameSite Lax');
check(str_contains(strtolower($responseHeaders), 'cache-control: no-store'), 'Authentication responses prohibit caching');
check(inline_js_valid($html), 'Existing login and recovery JavaScript compiles');

// Expired grants and independent refresh families.
$expired = clevo_token_pair($conn, clevo_account($conn, $customer));
$expiredHash = hash('sha256', $expired['refresh_token']);
clevo_stmt($conn, 'UPDATE auth_refresh_tokens SET ExpiresAt = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE TokenHash = ?', 's', [$expiredHash]);
rejects(fn() => clevo_rotate_refresh($conn, $expired['refresh_token']), 'Expired refresh rejected', 'invalid_refresh_token');
clevo_issue_verification($conn, clevo_account($conn, $legacy), 'pending-legacy@example.test');
$mail = clevo_decrypt_mail($conn->query('SELECT PayloadCipher FROM security_mail_queue ORDER BY ID DESC LIMIT 1')->fetch_assoc()['PayloadCipher']);
preg_match('/#token=([a-f0-9.]+)/', $mail['html'], $m); $stagedEmailToken = $m[1];
$resetSecret = bin2hex(random_bytes(32)); $resetID = clevo_new_challenge($conn, $legacy, 'reset_grant', $resetSecret, 300);
clevo_reset_password($conn, $resetID . '.' . $resetSecret, 'Cc!' . bin2hex(random_bytes(12)));
rejects(fn() => clevo_verify_email($conn, $stagedEmailToken), 'Password reset invalidates staged recovery-address takeover', 'invalid_challenge');
$grantSecret = bin2hex(random_bytes(32)); $grantID = clevo_new_challenge($conn, $legacy, 'reset_grant', $grantSecret, -1);
rejects(fn() => clevo_reset_password($conn, $grantID . '.' . $grantSecret, $password), 'Expired reset grant rejected', 'invalid_challenge');
$emailSecret = bin2hex(random_bytes(32)); $emailID = clevo_new_challenge($conn, $legacy, 'email', $emailSecret, -1);
rejects(fn() => clevo_verify_email($conn, $emailID . '.' . $emailSecret), 'Expired email verification rejected', 'invalid_challenge');

// Email changes keep the original recovery address until the new address proves ownership.
$beforeEmailPair = clevo_token_pair($conn, clevo_account($conn, $customer));
clevo_issue_verification($conn, clevo_account($conn, $customer), 'changed@example.test');
check(clevo_account($conn, $customer)['Email'] === 'customer1@example.test', 'Email change does not immediately replace recovery identity');
$mail = clevo_decrypt_mail($conn->query('SELECT PayloadCipher FROM security_mail_queue ORDER BY ID DESC LIMIT 1')->fetch_assoc()['PayloadCipher']);
preg_match('/#token=([a-f0-9.]+)/', $mail['html'], $m); clevo_verify_email($conn, $m[1]);
check(clevo_account($conn, $customer)['Email'] === 'changed@example.test', 'Verified new email replaces recovery identity');
check(clevo_authenticated_token($conn, $beforeEmailPair['access_token']) === null, 'Email identity change revokes prior sessions');
$rolePair = clevo_token_pair($conn, clevo_account($conn, $legacy));
clevo_stmt($conn, "UPDATE user SET Role = 'Admin' WHERE UserID = ?", 'i', [$legacy]);
rejects(fn() => clevo_rotate_refresh($conn, $rolePair['refresh_token']), 'Role change cannot upgrade an old refresh session', 'invalid_refresh_token');
clevo_stmt($conn, "UPDATE user SET Role = 'User' WHERE UserID = ?", 'i', [$legacy]);

// Real admin restriction/unrestriction revokes credentials rather than reviving old tokens.
$beforeBan = clevo_token_pair($conn, clevo_account($conn, $other));
[$status] = http('POST', '/admin/manage-users.php', ['toggle_status_id' => $other, 'csrf_token' => $adminCsrf], null, $adminJar, [], true);
check($status === 302 && clevo_authenticated_token($conn, $beforeBan['access_token']) === null, 'Admin account restriction revokes active token');
[$status] = http('POST', '/admin/manage-users.php', ['toggle_status_id' => $other, 'csrf_token' => $adminCsrf], null, $adminJar, [], true);
check($status === 302 && clevo_authenticated_token($conn, $beforeBan['access_token']) === null, 'Unban cannot revive a revoked token');

// Driver expiry/terminal state and serialized completion cannot be bypassed.
$code = clevo_issue_driver_code($conn, $owner, $booking);
clevo_stmt($conn, 'UPDATE booking SET DriverAccessCodeExpiresAt = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE BookingID = ?', 'i', [$booking]);
check(clevo_driver_code($conn, 'driver5@example.test', $code) === null, 'Expired driver access code denied');
require_once __DIR__ . '/../includes/app-helpers.php';
rejects(fn() => clevo_driver_transition($conn, $driver, $booking2, 'Pickup'), 'Driver domain transition denies other assignment', 'invalid_transition');
rejects(fn() => clevo_driver_transition($conn, $driver, $booking, 'Completed'), 'Driver cannot skip lifecycle to completion', 'invalid_transition');
clevo_stmt($conn, "UPDATE booking SET Status='Returning' WHERE BookingID = ?", 'i', [$booking]);
clevo_driver_transition($conn, $driver, $booking, 'Completed');
rejects(fn() => clevo_driver_transition($conn, $driver, $booking, 'Completed'), 'Completed task cannot complete twice', 'invalid_transition');
check((float) clevoGetLoyaltyPoints($conn, $customer) === 10.0, 'Completion awards loyalty once');
rejects(fn() => clevo_driver_transition($conn, $driver, $booking, 'Pickup'), 'Terminal task cannot revive', 'invalid_transition');

// Broadcast read state is per-recipient.
clevo_mark_notification($conn, $admin, 'admin', $broadcast);
check((int) $conn->query("SELECT COUNT(*) AS c FROM notification_reads WHERE NotificationID=$broadcast AND UserID=$admin")->fetch_assoc()['c'] === 1
    && (int) $conn->query("SELECT COUNT(*) AS c FROM notification_reads WHERE NotificationID=$broadcast AND UserID=$admin2")->fetch_assoc()['c'] === 0, 'Admin broadcast read state is isolated');

// Two real PHP processes race the same refresh token while contending on the account lock.
$raceAccount = clevo_account($conn, $customer); $racePair = clevo_token_pair($conn, $raceAccount);
$conn->begin_transaction(); clevo_account($conn, $customer, true); $processes = [];
for ($i = 0; $i < 2; $i++) {
    $process = proc_open([PHP_BINARY, __DIR__ . '/race-refresh.php'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, dirname(__DIR__));
    fwrite($pipes[0], $racePair['refresh_token'] . "\n"); fclose($pipes[0]); $processes[] = [$process, $pipes];
}
usleep(250000); $conn->commit(); $results = [];
foreach ($processes as [$process, $pipes]) { $results[] = trim(stream_get_contents($pipes[1])); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
sort($results);
check($results === ['refresh_replay_detected', 'rotated'], 'Concurrent refresh has one rotation and one replay rejection');
check(clevo_authenticated_token($conn, $racePair['access_token']) === null, 'Concurrent replay revokes entire family durably');

// Fixed-window limiter persists across requests; spoofed forwarding does not change the key.
$limited = false;
for ($i = 0; $i < 12; $i++) { [$status] = http('POST', '/api/v1/auth/login', ['login' => 'absent@example.test', 'password' => $password], null, null, ['X-Forwarded-For: 192.0.2.' . $i]); if ($status === 429) { $limited = true; break; } }
check($limited, 'Login rate limit survives spoofed forwarded IPs');
foreach (['register', 'forgot', 'verify', 'refresh'] as $scope) {
    $limited = false;
    try { for ($i = 0; $i < 150; $i++) clevo_auth_throttle($conn, $scope, 'isolated-limit-' . $scope); }
    catch (ClevoSecurityException $error) { $limited = $error->httpStatus === 429; }
    check($limited, 'Persisted throttle enforced for ' . $scope);
}
[$workerCode] = command([PHP_BINARY, __DIR__ . '/../scripts/security-mail-worker.php']);
check($workerCode === 0, 'Mail worker runs under explicitly configured null transport');
check((int) $conn->query("SELECT COUNT(*) AS c FROM security_mail_queue WHERE SentAt IS NULL OR PayloadCipher <> ''")->fetch_assoc()['c'] === 0,
    'Mail worker clears encrypted payloads after null-transport processing');

// Tests leave the isolated DB for evidence; no production database is imported or modified.
finish_tests('Security integration suite');
