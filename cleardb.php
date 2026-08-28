<?php
// cleardb.php
session_start();
require_once __DIR__ . '/ENV.php';

// ----------------------------------------------------
// 1. ログインチェック & 管理者権限チェック
// ----------------------------------------------------
// 未ログイン、または ADMIN_EMAIL が設定されていない場合は index.php へリダイレクト
if (!isset($_SESSION['user']) || !defined('ADMIN_EMAIL')) {
    header('Location: index.php');
    exit;
}

$current_user_email = $_SESSION['user']['email'] ?? '';

// ログイン中ユーザーのメールアドレスが .env の ADMIN_EMAIL と一致しない場合は拒否
if (empty($current_user_email) || $current_user_email !== ADMIN_EMAIL) {
    header('Location: index.php');
    exit;
}

// ----------------------------------------------------
// DB接続関数
// ----------------------------------------------------
function getDB() {
    try {
        return new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    } catch (PDOException $e) {
        return null;
    }
}

$message = '';
$is_error = false;

// ----------------------------------------------------
// 2. リセット実行処理 (POSTリクエスト時)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_db') {
    $db = getDB();
    if ($db) {
        try {
            // 外部キー制約チェックを一時的に無効化して全テーブルのデータを削除（TRUNCATE）
            $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
            
            $db->exec("TRUNCATE TABLE pp_user_tokens_tbl;");
            $db->exec("TRUNCATE TABLE pp_point_history_tbl;");
            $db->exec("TRUNCATE TABLE pp_point_tbl;");
            $db->exec("TRUNCATE TABLE pp_user_providers_tbl;");
            $db->exec("TRUNCATE TABLE pp_user_tbl;");

            $db->exec("SET FOREIGN_KEY_CHECKS = 1;");

            // リセット完了後、全セッションおよび自動ログインCookieをクリア
            $_SESSION = [];
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            if (isset($_COOKIE['remember_token'])) {
                setcookie('remember_token', '', time() - 3600, '/');
            }
            session_destroy();

            // 成功メッセージ表示のためリダイレクトせず完了画面を表示（3秒後にindex.phpへ自動遷移）
            $message = 'データベースのすべてのデータ（全テーブル）を正常にリセットしました。ログアウトされたため、トップページへ移動します。';
            header("refresh:3;url=index.php");
        } catch (PDOException $e) {
            $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
            $is_error = true;
            $message = 'データベースのリセット中にエラーが発生しました: ' . $e->getMessage();
        }
    } else {
        $is_error = true;
        $message = 'データベースへの接続に失敗しました。';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データベースリセット - 管理者専用</title>
    <link rel="stylesheet" href="cleardb.css">
</head>
<body>

<div class="card">
    <h1>⚠️ データベースリセット (管理者専用)</h1>

    <?php if (!empty($message)): ?>
        <div class="alert <?= $is_error ? 'alert-error' : 'alert-success' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (empty($message) || $is_error): ?>
        <p>
            現在ログイン中の管理者: <strong><?= htmlspecialchars($current_user_email, ENT_QUOTES, 'UTF-8') ?></strong>
        </p>
        <p class="warning-text">
            この操作を実行すると、<strong>すべてのユーザーデータ、OAuth連携情報、ポイント保有数、履歴、ログインセッションが削除されます。</strong><br>
            この操作は取り消すことができません。本当に実行しますか？
        </p>

        <form method="POST" onsubmit="return confirm('本当にすべてのデータベース項目を初期化してよろしいですか？');">
            <input type="hidden" name="action" value="reset_db">
            <button type="submit" class="btn-danger">すべてのデータを削除・初期化する</button>
        </form>

        <a href="dashboard.php" class="btn-secondary">ダッシュボードへ戻る</a>
    <?php endif; ?>
</div>

</body>
</html>
