<?php
// logout.php
session_start();
require_once __DIR__ . '/ENV.php';

if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $token_hash = hash('sha256', $token);

    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // DBからトークン削除
        $stmt = $pdo->prepare("DELETE FROM pp_user_tokens_tbl WHERE token_hash = ?");
        $stmt->execute([$token_hash]);
    } catch (PDOException $e) {
        // エラーログ出力等の処理
    }

    // Cookie削除
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

// セッション破棄
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: index.php');
exit;
