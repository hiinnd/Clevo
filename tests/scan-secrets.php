<?php
declare(strict_types=1);
// Prints rule/location only; never print the matched text, credentials or removed Git lines.
$root = dirname(__DIR__); $findings = []; $checked = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('~^(?:\.git|vendor|\.security-test|tests|docs)/~', $relative) || !in_array($file->getExtension(), ['php', 'js', 'sql'], true)) continue;
    $checked++; $source = file_get_contents($file->getPathname());
    if ($file->getExtension() === 'php') {
        $tokens = array_values(array_filter(token_get_all($source), static fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) continue;
            $sensitive = $token[0] === T_VARIABLE && preg_match('/^\$(?:jwt_secret|password|smtp_password|api_key|apiKey|default_pass)$/i', $token[1]);
            $sensitive = $sensitive || ($token[0] === T_STRING && in_array(strtolower($token[1]), ['password','username'], true)
                && is_array($tokens[$i-1] ?? null) && $tokens[$i-1][0] === T_OBJECT_OPERATOR);
            $literal = $tokens[$i+2] ?? null;
            if ($sensitive && ($tokens[$i+1] ?? null) === '=' && is_array($literal) && $literal[0] === T_CONSTANT_ENCAPSED_STRING
                && strlen($literal[1]) > 2 && ($tokens[$i+3] ?? null) === ';') {
                $findings[] = $relative . ':' . $token[2] . ' literal credential assignment';
            }
        }
    }
    foreach (explode("\n", $source) as $line => $text) {
        if (preg_match('/\b(?:API_KEY|apiKey|JWT_SECRET|SMTP_PASSWORD)\s*=\s*[\x22\x27][^\x22\x27]+[\x22\x27]/', $text)
            || ($file->getExtension() === 'sql' && preg_match('/\$2[aby]\$\d{2}\$/', $text))) {
            $findings[] = $relative . ':' . ($line + 1) . ' embedded credential material';
        }
    }
}
foreach (array_unique($findings) as $finding) echo 'FAIL: ' . $finding . "\n";
echo "Credential source scan: $checked files checked; " . count(array_unique($findings)) . " findings.\n";
exit($findings ? 1 : 0);
