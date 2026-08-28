<?php
require_once(__DIR__ . '/functions.php');

// NOTE: アドミン必須ページ
$user = requireAdmin();

$message = '';
$error = '';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_points') {
    $target_user_id = intval($_POST['target_user_id'] ?? 0);
    $point_change   = intval($_POST['point_change'] ?? 0);
    $reason         = trim($_POST['reason'] ?? '');

    if ($target_user_id <= 0 || $point_change === 0 || empty($reason)) {
        $error = 'ユーザー、ポイント変動値、理由は必須入力です。';
    } else {
        if (updateUserPoints($target_user_id, $point_change, $reason)) {
            $message = 'ポイントを正常に更新しました。';
        } else {
            $error = 'ポイントの更新に失敗しました。';
        }
    }
}

// ユーザー一覧取得
$users = [];
if ($db) {
    $stmt_users = $db->query("
        SELECT u.id, u.username, u.email, COALESCE(p.current_points, 0) as current_points 
        FROM pp_user_tbl u
        LEFT JOIN pp_point_tbl p ON u.id = p.user_id
        ORDER BY u.id DESC
    ");
    $users = $stmt_users->fetchAll();
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
            <div class="alert-success"><?= h($message) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= h($error) ?></div>
        <?php endif; ?>

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
                                <?= h($u['username']) ?> (<?= h($u['email']) ?>) - 現在: <?= number_format($u['current_points']) ?> pt
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="point_change">増減ポイント（減算の場合はマイナス指定できるよ）</label>
                    <input type="number" name="point_change" id="point_change" placeholder="例: 100 または -50" required>
                </div>

                <div class="form-group">
                    <label for="reason">変更理由</label>
                    <input type="text" name="reason" id="reason" placeholder="例: イベント配布, 店舗利用" required>
                </div>

                <button type="submit" class="btn-submit">ポイントを変更する</button>
            </form>
        </section>

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
                                <td><?= h($u['username']) ?></td>
                                <td><?= h($u['email']) ?></td>
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
