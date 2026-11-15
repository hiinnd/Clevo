<?php
require_once __DIR__ . '/config.php';
clevo_require_role('customer');
clevo_rate_limit($conn, 'weather:user', (string) clevo_current_user_id(), 10, 60);
$key = clevo_env('OPENWEATHER_API_KEY', '') ?? '';
if ($key === '') clevo_json_response('error', [], 'weather_unavailable', 503);
$lat = filter_var($_GET['lat'] ?? 31.9539, FILTER_VALIDATE_FLOAT);
$lon = filter_var($_GET['lon'] ?? 35.9106, FILTER_VALIDATE_FLOAT);
if ($lat === false || $lon === false || abs($lat) > 90 || abs($lon) > 180) throw new ClevoSecurityException('invalid_location', 422);
$lang = ($_GET['lang'] ?? '') === 'en' ? 'en' : 'ar';
$curl = curl_init('https://api.openweathermap.org/data/2.5/weather?' . http_build_query(['lat' => $lat, 'lon' => $lon, 'units' => 'metric', 'lang' => $lang, 'appid' => $key]));
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
$result = curl_exec($curl); $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
if (!$result || strlen($result) > 65536 || $code !== 200 || !is_array(json_decode($result, true))) clevo_json_response('error', [], 'weather_unavailable', 503);
header('Content-Type: application/json; charset=utf-8'); echo $result;
