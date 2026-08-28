<?php
require_once(__DIR__ . '/functions.php');

$user = requireLogin();
$is_admin = isAdmin($user);

// ポイントデータ取得
$point_info = getUserPointData($user['id']);
$current_points   = $point_info['current'];
$point_percentage = $point_info['percentage'];

// ポイント履歴取得
$point_history = [];
$db = getDB();
if ($db) {
    $stmt_history = $db->prepare("
        SELECT point_change, reason, created_at 
        FROM pp_point_history_tbl 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt_history->execute([$user['id']]);
    $point_history = $stmt_history->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PointWorld - ダッシュボード</title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>

<div class="dashboard-container">
    <header class="header">
        <div class="user-profile">
            <img src="<?= h($user['avatar_url'] ?: 'https://via.placeholder.com/80') ?>" alt="アバター" class="avatar">
            <div class="user-info">
                <h2 class="username"><?= h($user['username']) ?></h2>
                <p class="email"><?= h($user['email']) ?></p>
            </div>
        </div>
        <div class="header-actions">
            <?php if ($is_admin): ?>
                <a href="admin.php" class="btn-admin">管理者ページへ</a>
            <?php endif; ?>
            <a href="logout.php" class="btn-logout">ログアウト</a>
        </div>
    </header>

    <main class="main-content">
        <section class="point-card">
            <span class="point-label">現在の所有ポイント</span>
            <div class="point-value">
                <?= number_format($current_points) ?> <span class="point-unit">pt</span>
            </div>
            <div class="point-share" style="margin-top: 10px; font-size: 0.95rem; color: #a0a0a0;">
                あなたは世界のポイントの <strong style="color: #ffd700; font-size: 1.1rem;"><?= number_format($point_percentage, 2) ?>%</strong> を所有しています
            </div>
        </section>

        <section class="history-section">
            <h3 class="section-title">ポイント増減履歴</h3>
            <?php if (empty($point_history)): ?>
                <p class="no-history">履歴はまだありません。</p>
            <?php else: ?>
                <div class="history-list">
                    <?php foreach ($point_history as $item): ?>
                        <div class="history-item">
                            <div class="history-details">
                                <span class="history-reason"><?= h($item['reason']) ?></span>
                                <span class="history-date"><?= date('Y/m/d H:i', strtotime($item['created_at'])) ?></span>
                            </div>
                            <div class="history-change <?= $item['point_change'] >= 0 ? 'plus' : 'minus' ?>">
                                <?= $item['point_change'] >= 0 ? '+' : '' ?><?= number_format($item['point_change']) ?> pt
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

</body>
</html>
