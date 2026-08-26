<?php
// ENV.php
$envFilePath = __DIR__ . '/.env';

if (file_exists($envFilePath)) {
    $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $envVars = [];

    // 1st pass: .env のキーと値を読み込み＆引用符（"や'）のトリム処理
    foreach ($lines as $line) {
        $line = trim($line);
        // コメント行や空行をスキップ
        if (strpos($line, '#') === 0 || empty($line)) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // 前後のダブルクォーテーション・シングルクォーテーションを除去
            $value = preg_replace('/^["\'](.*)["\']$/s', '$1', $value);

            $envVars[$key] = $value;
        }
    }

    // 2nd pass: ${VAR_NAME} の変数展開と define()
    foreach ($envVars as $key => $value) {
        // ${...} を対応する値に置換
        $value = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/i', function ($matches) use ($envVars) {
            $refKey = $matches[1];
            return isset($envVars[$refKey]) ? $envVars[$refKey] : '';
        }, $value);

        if (!defined($key)) {
            define($key, $value);
        }
    }
}