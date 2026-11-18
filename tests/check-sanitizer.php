<?php
require __DIR__.'/../src/PayloadSanitizer.php';
$s = new DF\HttpLogger\PayloadSanitizer();
$data = $s->redact(['nested' => ['PASSWORD' => 'secret'], 'name' => 'Alice'], ['password']);
if ($data['nested']['PASSWORD'] !== '[REDACTED]' || $data['name'] !== 'Alice') {
    throw new RuntimeException('Redaction failed');
}
if ($s->limit(['data' => str_repeat('a', 100)], 32) !== ['omitted' => 'size_limit']) {
    throw new RuntimeException('Size limit failed');
}
echo "Sanitizer checks passed\n";
