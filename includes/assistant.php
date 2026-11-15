<?php
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); clevo_json_response('error', [], 'method_not_allowed', 405); }
clevo_rate_limit($conn, 'assistant:ip', $_SERVER['REMOTE_ADDR'] ?? '', 15, 60);
$input = clevo_read_json();
$message = clevo_clean_string($input['message'] ?? '', 2000);
$url = clevo_env('LAMEA_WEBHOOK_URL', '') ?? '';
if ($message === '' || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') clevo_json_response('error', [], 'assistant_unavailable', 503);
$curl = curl_init($url);
$headers = ['Content-Type: application/json'];
if (clevo_env('LAMEA_WEBHOOK_TOKEN', '') !== '') $headers[] = 'Authorization: Bearer ' . clevo_env('LAMEA_WEBHOOK_TOKEN');
$body = json_encode(['message' => $message, 'session' => hash_hmac('sha256', session_id(), clevo_secret('APP_KEY'))]);
curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
$result = curl_exec($curl); $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
$response = is_string($result) && strlen($result) <= 65536 ? json_decode($result, true) : null;
if ($code !== 200 || !is_array($response)) clevo_json_response('error', [], 'assistant_unavailable', 503);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['reply' => clevo_clean_string($response['reply'] ?? $response['output'] ?? '', 10000)], JSON_UNESCAPED_UNICODE);
