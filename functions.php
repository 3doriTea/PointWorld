<?php
require_once(__DIR__ . '/ENV.php');

/**
 * DB接続の取得
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
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
        } catch (PDOException $e) {
            return null;
        }
    }
    return $pdo;
}

/**
 * HTMLエスケープヘルパー
 */
function h($string) {
    // NOTE: コードをテキストとして表示するようにするやつ
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * ログインチェック (未ログイン時はindex.phpへ)
 */
function requireLogin() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user'])) {
        // ログインしてないならindex.phpへ！
        header('Location: index.php');
        exit;
    }
    return $_SESSION['user'];
}

/**
 * 管理者権限チェック
 * -> 管理者権限を持っている true / false
 */
function isAdmin($user = null) {
    if (!$user) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $user = $_SESSION['user'] ?? null;
    }
    return isset($user['email']) && defined('ADMIN_EMAIL') && ($user['email'] === ADMIN_EMAIL);
}

/**
 * 管理者限定ページのアクセス制御
 */
function requireAdmin() {
    $user = requireLogin();
    if (!isAdmin($user)) {
        header('Location: dashboard.php');
        exit;
    }
    return $user;
}

/**
 * cURLリクエスト共通関数
 */
function sendHttpRequest($url, $params = [], $headers = [], $is_post = false) {
    $ch = curl_init();
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'PointWorld-App',
        CURLOPT_TIMEOUT        => 15
    ];

    if ($is_post) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = is_array($params) ? http_build_query($params) : $params;
    } elseif (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $options[CURLOPT_URL] = $url;

    if (!empty($headers)) {
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    if (defined('HTTP_PROXY') && !empty(HTTP_PROXY)) {
        $options[CURLOPT_PROXY] = HTTP_PROXY;
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

/**
 * ログインセッションおよびRemember Tokenの削除処理
 */
function logoutUser() {
    $db = getDB();
    if ($db && isset($_COOKIE['remember_token'])) {
        $token_hash = hash('sha256', $_COOKIE['remember_token']);
        try {
            $stmt = $db->prepare("DELETE FROM pp_user_tokens_tbl WHERE token_hash = ?");
            $stmt->execute([$token_hash]);
        } catch (PDOException $e) {}
        setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    }

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();  // セッションもさらば
}

/**
 * OAuthコールバック時のユーザーログイン・登録処理
 */
function handleOAuthLogin($provider_name, $provider_user_id, $name, $email, $avatar, $state_data = []) {
    $db = getDB();
    if (!$db) {
        return 'データベース接続に失敗しました。DB設定を確認してください。';
    }

    // MEMO: 既にアカウントがあるかどうかをフィルタリングしていく

    // 1. プロバイダ情報で検索
    $stmt = $db->prepare("
        SELECT u.* 
        FROM pp_user_tbl u 
        JOIN pp_user_providers_tbl up ON u.id = up.user_id 
        WHERE up.provider_name = ? AND up.provider_user_id = ?
    ");
    $stmt->execute([$provider_name, $provider_user_id]);
    $user = $stmt->fetch();

    // 2. 未連携の場合、メールで検索して統合
    if (!$user && !empty($email)) {
        $stmt_email = $db->prepare("SELECT * FROM pp_user_tbl WHERE email = ?");
        $stmt_email->execute([$email]);
        $existing_user = $stmt_email->fetch();

        if ($existing_user) {
            $user_id = $existing_user['id'];
            $stmt_prov = $db->prepare("INSERT INTO pp_user_providers_tbl (user_id, provider_name, provider_user_id) VALUES (?, ?, ?)");
            $stmt_prov->execute([$user_id, $provider_name, $provider_user_id]);
            $user = $existing_user;
        }
    }

    // 3. 新規ユーザー登録
    if (!$user) {
        $stmt_user = $db->prepare("INSERT INTO pp_user_tbl (username, email, avatar_url) VALUES (?, ?, ?)");
        $stmt_user->execute([$name, $email, $avatar]);
        $user_id = $db->lastInsertId();

        $stmt_prov = $db->prepare("INSERT INTO pp_user_providers_tbl (user_id, provider_name, provider_user_id) VALUES (?, ?, ?)");
        $stmt_prov->execute([$user_id, $provider_name, $provider_user_id]);

        $stmt_point = $db->prepare("INSERT INTO pp_point_tbl (user_id, current_points) VALUES (?, 0)");
        $stmt_point->execute([$user_id]);

        $user = [
            'id'         => $user_id,
            'username'   => $name,
            'email'      => $email,
            'avatar_url' => $avatar
        ];
    }

    $_SESSION['user'] = $user;

    // Remember Me処理 & リダイレクト
    $redirect_target = 'dashboard.php';
    if (!empty($state_data)) {
        if (!empty($state_data['remember'])) {
            $remember_token = bin2hex(random_bytes(32));
            $token_hash     = hash('sha256', $remember_token);
            $expires_at     = date('Y-m-d H:i:s', time() + (86400 * 30));

            $stmt_token = $db->prepare("INSERT INTO pp_user_tokens_tbl (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
            $stmt_token->execute([$user['id'], $token_hash, $expires_at]);

            setcookie('remember_token', $remember_token, time() + (86400 * 30), '/', '', false, true);
        }

        if (!empty($state_data['return_url'])) {
            $raw_target = $state_data['return_url'];
            if (defined('BASE_URL') && strpos($raw_target, BASE_URL) === 0) {
                $redirect_target = $raw_target;
            } elseif (preg_match('/^\/[^\/]/', $raw_target)) {
                $redirect_target = $raw_target;
            }
        }
    }

    header('Location: ' . $redirect_target);
    exit;
}

/**
 * ユーザーの所有ポイントおよび世界所有率を取得
 */
function getUserPointData($user_id) {
    $db = getDB();
    $current_points = 0;
    $total_world_points = 0;
    $percentage = 0.0;

    if (!$db) {
        return ['current' => 0, 'total' => 0, 'percentage' => 0.0];
    }

    // ユーザーポイント取得
    $stmt = $db->prepare("SELECT current_points FROM pp_point_tbl WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $data = $stmt->fetch();
    if ($data) {
        $current_points = (int)$data['current_points'];
    }

    // 全体合計ポイント取得
    $stmt_total = $db->query("SELECT SUM(current_points) AS total_points FROM pp_point_tbl");
    $total_data = $stmt_total->fetch();
    if ($total_data && $total_data['total_points'] > 0) {
        $total_world_points = (int)$total_data['total_points'];
        $percentage = ($current_points / $total_world_points) * 100;
    }

    return [
        'current'    => $current_points,
        'total'      => $total_world_points,
        'percentage' => $percentage
    ];
}

/**
 * ポイント変更処理（トランザクション保護）
 * -> 処理成功 true / false
 */
function updateUserPoints($target_user_id, $point_change, $reason) {
    $db = getDB();
    if (!$db) return false;

    try {
        // NOTE: 一連の処理 = トランザクション処理にしちゃう！
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO pp_point_tbl (user_id, current_points) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE current_points = current_points + VALUES(current_points)
        ");
        $stmt->execute([$target_user_id, $point_change]);

        $stmt_history = $db->prepare("
            INSERT INTO pp_point_history_tbl (user_id, point_change, reason) 
            VALUES (?, ?, ?)
        ");
        $stmt_history->execute([$target_user_id, $point_change, $reason]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return false;
    }
}
