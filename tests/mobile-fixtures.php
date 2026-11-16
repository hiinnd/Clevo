<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../includes/marketplace.php';
if (clevo_env('APP_ENV') !== 'testing' || clevo_env('DB_HOST') !== '127.0.0.1' || clevo_env('DB_PORT') !== '13379' || !preg_match('/^clevo_security_test_[a-f0-9]{12}$/D', clevo_env('DB_DATABASE', '') ?? '')) exit(2);
$db = clevo_connect_database();
if (strcasecmp(realpath($db->query('SELECT @@datadir d')->fetch_assoc()['d']) ?: '', realpath(__DIR__.'/../.security-test/mysql') ?: '!') !== 0) exit(2);
if (rtrim(clevo_env('APP_URL'), '/') !== 'http://127.0.0.1:18089') exit(2);
$db->query('DELETE FROM security_rate_limits');
$password = 'Mobile!'.bin2hex(random_bytes(20));
$fixture = ['api_url'=>'http://127.0.0.1:18089/api/v1/', 'password'=>$password];
foreach (['customer','other','owner','driver'] as $name) {
    $email = bin2hex(random_bytes(10)).'@mobile.invalid';
    clevo_stmt($db,"INSERT INTO user (UserName,Email,PhoneNumber,Password,Role,AccountStatus,EmailVerifiedAt) VALUES (?,?,?,?,?,'active',UTC_TIMESTAMP())",'sssss',[
        'Mobile '.$name,$email,'079'.random_int(1000000,9999999),password_hash($password,PASSWORD_DEFAULT),$name==='other'?'customer':$name
    ]);
    $fixture[$name] = ['id'=>(int)$db->insert_id,'login'=>$email];
}
clevo_stmt($db,"INSERT INTO stations (UserID,Name,Location,Lat,Lon,admin_status,Status,Has_At_Station,Has_Home_Service,Has_Delivery,BookingCapacity,HomeCapacity,OpeningTime,ClosingTime,DefaultServiceMinutes) VALUES (?,'Mobile integration','Amman',31.95,35.91,'approved','Active',1,1,1,10,10,'00:00','00:00',30)",'i',[$fixture['owner']['id']]);
$station = (int)$db->insert_id;
clevo_stmt($db,'UPDATE user SET StationID=? WHERE UserID=?','ii',[$station,$fixture['driver']['id']]);
$service = clevo_market_service($db,$fixture['owner']['id'],$station,0,['name_ar'=>'غسيل اختبار','name_en'=>'Integration wash','price'=>'2.00','duration'=>30,'is_station'=>true,'is_home'=>true,'is_pickup'=>true]);
clevo_stmt($db,'INSERT INTO wallet (UserID,Balance) VALUES (?,100),(?,0)','ii',[$fixture['customer']['id'],$fixture['owner']['id']]);
$delivery = clevo_create_booking($db,$fixture['customer']['id'],$station,['service_ids'=>[$service],'service_location'=>'delivery','booking_date'=>gmdate('Y-m-d',time()+86400*21),'booking_time'=>'10:00','latitude'=>31.95,'longitude'=>35.91,'address_details'=>'Synthetic test address'],bin2hex(random_bytes(16)))['booking_id'];
clevo_market_assign($db,$fixture['owner']['id'],$delivery,$fixture['driver']['id']);
$fixture += ['station_id'=>$station,'service_id'=>$service,'service_price'=>'2.00','booking_date'=>gmdate('Y-m-d',time()+86400*22),'driver_booking_id'=>$delivery];
$path = __DIR__.'/../.security-test/private/mobile-fixtures.json';
file_put_contents($path,json_encode($fixture,JSON_THROW_ON_ERROR));
echo "Disposable mobile fixtures prepared; credentials are stored only in ignored private test storage.\n";
