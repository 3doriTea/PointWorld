<?php
// admin.php
session_start();
require_once __DIR__ . '/ENV.php';

// 未ログインまたは非管理者アクセスの拒否
if (!isset($_SESSION['user']) || !defined('ADMIN_EMAIL') || $_SESSION['user']['email'] !== ADMIN_EMAIL) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // ポイント操作リクエストの処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_points') {
        $target_user_id = intval($_POST['target_user_id'] ?? 0);
        $point_change   = intval($_POST['point_change'] ?? 0);
        $reason         = trim($_POST['reason'] ?? '');

        if ($target_user_id <= 0 || $point_change === 0 || empty($reason)) {
            $error = 'ユーザー、ポイント変動値、理由は必須入力です。';
        } else {
            $pdo->beginTransaction();

            // 1. 現状のポイント数を更新 (レコードがなければ挿入)
            $stmt = $pdo->prepare("
                INSERT INTO pp_point_tbl (user_id, current_points) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE current_points = current_points + VALUES(current_points)
            ");
            $stmt->execute([$target_user_id, $point_change]);

            // 2. 履歴レコードの記録
            $stmt_history = $pdo->prepare("
                INSERT INTO pp_point_history_tbl (user_id, point_change, reason) 
                VALUES (?, ?, ?)
            ");
            $stmt_history->execute([$target_user_id, $point_change, $reason]);

            $pdo->commit();
            $message = 'ポイントを正常に更新しました。';
        }
    }

    // 全ユーザーリストと現在のポイントを取得
    $stmt_users = $pdo->query("
        SELECT u.id, u.username, u.email, COALESCE(p.current_points, 0) as current_points 
        FROM pp_user_tbl u
        LEFT JOIN pp_point_tbl p ON u.id = p.user_id
        ORDER BY u.id DESC
    ");
    $users = $stmt_users->fetchAll();

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = 'データベースエラー: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PointWorld - 管理者ダッシュボード</title>
    <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet" href="admin.css">
</head>
<body>

<div class="dashboard-container">
    <header class="header">
        <h2>管理者パネル</h2>
        <a href="dashboard.php" class="btn-logout">ダッシュボードへ戻る</a>
    </header>

    <main class="main-content">
        <?php if (!empty($message)): ?>
            <div class="alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- ポイント操作フォーム -->
        <section class="admin-card">
            <h3 class="section-title">ユーザーポイント変更</h3>
            <form action="admin.php" method="POST" class="admin-form">
                <input type="hidden" name="action" value="update_points">
                
                <div class="form-group">
                    <label for="target_user_id">対象ユーザー</label>
                    <select name="target_user_id" id="target_user_id" required>
                        <option value="">ユーザーを選択してください</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>">
                                <?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?> 
                                (<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>) - 現在: <?= number_format($u['current_points']) ?> pt
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="point_change">増減ポイント（減算の場合はマイナス指定）</label>
                    <input type="number" name="point_change" id="point_change" placeholder="例: 100 または -50" required>
                </div>

                <div class="form-group">
                    <label for="reason">変更理由</label>
                    <input type="text" name="reason" id="reason" placeholder="例: イベント配布, 店舗利用" required>
                </div>

                <button type="submit" class="btn-submit">ポイントを変更する</button>
            </form>
        </section>

        <!-- ユーザー一覧テーブル -->
        <section class="admin-card">
            <h3 class="section-title">登録ユーザー一覧</h3>
            <div class="table-wrapper">
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>ユーザー名</th>
                            <th>メールアドレス</th>
                            <th>現在ポイント</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= $u['id'] ?></td>
                                <td><?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><strong><?= number_format($u['current_points']) ?></strong> pt</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

</body>
</html>
