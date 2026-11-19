<?php

declare(strict_types=1);

// 通过真实 multipart HTTP 请求验证 Workerman 文件解析与 Laravel UploadedFile 转换。
$baseUrl = getenv('ACCEPTANCE_BASE_URL') ?: 'http://127.0.0.1:18768';
$payload = "Laravel Workerman upload probe\n" . bin2hex(random_bytes(16));
$filePath = tempnam(sys_get_temp_dir(), 'workerman-upload-');
if ($filePath === false) {
    throw new RuntimeException('Could not create upload fixture');
}
file_put_contents($filePath, $payload);

try {
    $handle = curl_init($baseUrl . '/acceptance/upload');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($filePath, 'text/plain', '验收-upload.txt'),
            'marker' => 'multipart-probe',
        ],
    ]);
    $body = curl_exec($handle);
    if ($body === false) {
        throw new RuntimeException('Upload transport failed: ' . curl_error($handle));
    }
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);

    if ($status !== 200) {
        throw new RuntimeException('Upload endpoint returned HTTP ' . $status . ': ' . $body);
    }

    $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $expected = [
        'name' => '验收-upload.txt',
        'size' => strlen($payload),
        'sha256' => hash('sha256', $payload),
        'valid' => true,
    ];
    if ($result !== $expected) {
        throw new RuntimeException('Uploaded file metadata mismatch: ' . $body);
    }

    echo "PASS upload: multipart file parsed, converted, and content verified\n";
} finally {
    unlink($filePath);
}
