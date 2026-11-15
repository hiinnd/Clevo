<?php
declare(strict_types=1);

function clevo_inspect_upload(array $file, bool $document = false, int $max = 5242880): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
        || !is_file($file['tmp_name']) || is_link($file['tmp_name'])) throw new ClevoSecurityException('invalid_upload', 422);
    $size = filesize($file['tmp_name']);
    if ($size === false || $size < 1 || $size > $max) throw new ClevoSecurityException('upload_size', 422);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if ($document) $allowed['application/pdf'] = 'pdf';
    if (!isset($allowed[$mime])) throw new ClevoSecurityException('upload_type', 422);
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $expected = $allowed[$mime];
    if ($ext !== $expected && !($expected === 'jpg' && $ext === 'jpeg')) throw new ClevoSecurityException('upload_type', 422);
    if (str_starts_with($mime, 'image/')) {
        $dim = @getimagesize($file['tmp_name']);
        if (!$dim || $dim['mime'] !== $mime || $dim[0] > 8000 || $dim[1] > 8000 || $dim[0] * $dim[1] > 24000000) throw new ClevoSecurityException('invalid_image', 422);
    } elseif (file_get_contents($file['tmp_name'], false, null, 0, 5) !== '%PDF-') throw new ClevoSecurityException('upload_type', 422);
    $bytes = file_get_contents($file['tmp_name']);
    if ($bytes === false || preg_match('/<\?(?:php|=)|<script\b/i', $bytes)) throw new ClevoSecurityException('upload_type', 422);
    if ($mime === 'application/pdf' && preg_match('~/\s*(?:JavaScript|JS|Launch|EmbeddedFile|RichMedia)\b~i', $bytes)) throw new ClevoSecurityException('upload_type', 422);
    return ['extension' => $expected, 'mime' => $mime];
}
function clevo_save_upload(array $file, string $kind): string
{
    if (!in_array($kind, ['profiles', 'stations', 'verification'], true)) throw new ClevoSecurityException('invalid_upload', 422);
    $info = clevo_inspect_upload($file, $kind === 'verification');
    if (!is_uploaded_file($file['tmp_name'])) throw new ClevoSecurityException('invalid_upload', 422);
    $dir = rtrim(clevo_env('PRIVATE_STORAGE_PATH', '') ?? '', '/\\') . DIRECTORY_SEPARATOR . $kind;
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Upload storage unavailable.');
    $name = bin2hex(random_bytes(24)) . '.' . $info['extension'];
    if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) throw new RuntimeException('Upload storage unavailable.');
    @chmod($dir . DIRECTORY_SEPARATOR . $name, 0600);
    return 'private:' . $kind . ':' . $name;
}
function clevo_media_url(string $reference): string
{
    return clevo_public_url('includes/media.php?ref=' . rawurlencode($reference));
}
