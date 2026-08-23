<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/helpers/chat_crypto.php';

loadEnvFile(__DIR__ . '/../.env');

$plain = "Hello chat encryption test @ " . date('c');

$enc = encryptChatMessage($plain);
$dec = decryptChatMessage($enc);

echo "plaintext:  $plain\n";
echo "encrypted:  $enc\n";
echo "decrypted:  $dec\n";

echo "uses:      " . (str_starts_with($enc, 'v1:') ? 'v1(sodium)' : (str_starts_with($enc, 'v2:') ? 'v2(openssl)' : 'plaintext')) . "\n";

if ($dec !== $plain) {
    fwrite(STDERR, "FAIL: decrypted text does not match\n");
    exit(1);
}

echo "OK\n";

