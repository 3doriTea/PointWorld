<?php
// dashboard.php
session_start();
require_once __DIR__ . '/ENV.php';

// 未ログイン保護
if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['user'];
$current_points = 0;
$total_world_points = 0;
$point_percentage = 0.0;
$point_history = [];

// 管理者判定
$is_admin = defined('ADMIN_EMAIL') && ($user['email'] === ADMIN_EMAIL);

// DB接続
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

    // 1. 最新の所有ポイントを取得
    $stmt_point = $pdo->prepare("SELECT current_points FROM pp_point_tbl WHERE user_id = ?");
    $stmt_point->execute([$user['id']]);
    $point_data = $stmt_point->fetch();
    if ($point_data) {
        $current_points = (int)$point_data['current_points'];
    }

    // 2. 世界全体（全ユーザー）の合計ポイントを取得
    $stmt_total = $pdo->query("SELECT SUM(current_points) AS total_points FROM pp_point_tbl");
    $total_data = $stmt_total->fetch();
    if ($total_data && $total_data['total_points'] > 0) {
        $total_world_points = (int)$total_data['total_points'];
        // 所有割合を計算 (ゼロ除算対策あり)
        $point_percentage = ($current_points / $total_world_points) * 100;
    }

    // 3. ポイント増減履歴を取得（最新20件）
    $stmt_history = $pdo->prepare("
        SELECT point_change, reason, created_at 
        FROM pp_point_history_tbl 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt_history->execute([$user['id']]);
    $point_history = $stmt_history->fetchAll();

} catch (PDOException $e) {
    // DBエラー処理
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
    <!-- ヘッダーエリア -->
    <header class="header">
        <div class="user-profile">
            <img src="<?= htmlspecialchars($user['avatar_url'] ?: 'https://via.placeholder.com/80', ENT_QUOTES, 'UTF-8') ?>" alt="アバター" class="avatar">
            <div class="user-info">
                <h2 class="username"><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="email"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
        <div class="header-actions">
            <?php if ($is_admin): ?>
                <a href="admin.php" class="btn-admin">管理者ページへ</a>
            <?php endif; ?>
            <a href="logout.php" class="btn-logout">ログアウト</a>
        </div>
    </header>

    <!-- メインコンテンツ -->
    <main class="main-content">
        <section class="point-card">
            <span class="point-label">現在の所有ポイント</span>
            <div class="point-value">
                <?= number_format($current_points) ?> <span class="point-unit">pt</span>
            </div>
            <!-- 世界の所有率表示を追加 -->
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
                                <span class="history-reason"><?= htmlspecialchars($item['reason'], ENT_QUOTES, 'UTF-8') ?></span>
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
