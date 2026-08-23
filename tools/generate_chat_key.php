<?php

declare(strict_types=1);

// Generates a base64-encoded 32-byte key suitable for CHAT_ENCRYPTION_KEY.
// Usage (Windows/XAMPP):
//   C:\xampp\php\php.exe tools\generate_chat_key.php

$key = random_bytes(32);
echo base64_encode($key) . PHP_EOL;
