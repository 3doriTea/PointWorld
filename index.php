<?php
// index.php
session_start();
require_once __DIR__ . '/ENV.php';

$error_message = '';

// DB接続関数
function getDB() {
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
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

// ----------------------------------------------------
// 0. 自動ログイン（Remember Me）チェック処理
// ----------------------------------------------------
if (!isset($_SESSION['user']) && isset($_COOKIE['remember_token'])) {
    $db = getDB();
    if ($db) {
        $token = $_COOKIE['remember_token'];
        $token_hash = hash('sha256', $token);

        // 有効期限内でトークンハッシュが一致するレコードとユーザー情報を取得
        $stmt = $db->prepare("
            SELECT u.*, t.id as token_id 
            FROM pp_user_tokens_tbl t
            JOIN pp_user_tbl u ON t.user_id = u.id
            WHERE t.token_hash = ? AND t.expires_at > NOW()
        ");
        $stmt->execute([$token_hash]);
        $user = $stmt->fetch();

        if ($user) {
            // 自動ログイン成功
            $_SESSION['user'] = [
                'id'         => $user['id'],
                'username'   => $user['username'],
                'email'      => $user['email'],
                'avatar_url' => $user['avatar_url']
            ];
            header('Location: dashboard.php');
            exit;
        } else {
            // 無効または期限切れのCookieを削除
            setcookie('remember_token', '', time() - 3600, '/', '', false, true);
        }
    }
}

// すでにセッションログイン済みの場合はダッシュボードへ直行
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

// ----------------------------------------------------
// GitHub OAuth2 コールバック・認証処理（cURL使用版）
// ----------------------------------------------------
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $proxy_url = defined('HTTP_PROXY') ? HTTP_PROXY : ''; 

    // 1. アクセストークンの取得
    $token_url = 'https://github.com/login/oauth/access_token';
    $post_fields = http_build_query([
        'code'          => $code,
        'client_id'     => GITHUB_CLIENT_ID,
        'client_secret' => GITHUB_CLIENT_SECRET,
        'redirect_uri'  => GITHUB_REDIRECT_URI
    ]);

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PointWorld-App');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if (!empty($proxy_url)) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy_url);
    }

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $token_data = json_decode($response, true);

    if (!isset($token_data['access_token'])) {
        echo '<div style="background:#2b2d31; color:#fa7070; padding:20px; font-family:sans-serif;">';
        echo '<h3>GitHub API エラー詳細 (cURL)</h3>';
        if ($curl_error) {
            echo '<p><b>cURL Error:</b> ' . htmlspecialchars($curl_error) . '</p>';
        }
        echo '<pre>';
        print_r($token_data);
        echo '</pre>';
        echo '<p><b>送信した redirect_uri:</b> ' . htmlspecialchars(GITHUB_REDIRECT_URI) . '</p>';
        echo '</div>';
        exit;
    }

    $access_token = $token_data['access_token'];

    // 2. ユーザー基本情報の取得
    $user_url = 'https://api.github.com/user';
    
    $ch = curl_init($user_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PointWorld-App');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if (!empty($proxy_url)) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy_url);
    }

    $user_info_response = curl_exec($ch);
    curl_close($ch);

    $user_info = json_decode($user_info_response, true);

    if (isset($user_info['id'])) {
        $github_id = (string)$user_info['id'];
        $name      = $user_info['name'] ?? $user_info['login'] ?? 'PointWorld User';
        $avatar    = $user_info['avatar_url'] ?? '';
        $email     = $user_info['email'] ?? '';

        // GitHubでメールアドレスが非公開(null)の場合はメール取得APIを追加実行
        if (empty($email)) {
            $emails_url = 'https://api.github.com/user/emails';
            $ch = curl_init($emails_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $access_token,
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'PointWorld-App');
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);

            if (!empty($proxy_url)) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy_url);
            }

            $emails_response = curl_exec($ch);
            curl_close($ch);

            $emails_data = json_decode($emails_response, true);
            if (is_array($emails_data)) {
                foreach ($emails_data as $email_obj) {
                    if (!empty($email_obj['primary'])) {
                        $email = $email_obj['email'];
                        break;
                    }
                }
            }
        }

        $db = getDB();
        if ($db) {
            // provider_name = 'github' で照会
            $stmt = $db->prepare("SELECT u.* FROM pp_user_tbl u JOIN pp_user_providers_tbl up ON u.id = up.user_id WHERE up.provider_name = 'github' AND up.provider_user_id = ?");
            $stmt->execute([$github_id]);
            $user = $stmt->fetch();

            if (!$user) {
                // 新規ユーザー作成
                $stmt_user = $db->prepare("INSERT INTO pp_user_tbl (username, email, avatar_url) VALUES (?, ?, ?)");
                $stmt_user->execute([$name, $email, $avatar]);
                $user_id = $db->lastInsertId();

                $stmt_prov = $db->prepare("INSERT INTO pp_user_providers_tbl (user_id, provider_name, provider_user_id) VALUES (?, 'github', ?)");
                $stmt_prov->execute([$user_id, $github_id]);

                $stmt_point = $db->prepare("INSERT INTO pp_point_tbl (user_id, current_points) VALUES (?, 0)");
                $stmt_point->execute([$user_id]);

                $user = [
                    'id'         => $user_id,
                    'username'   => $name,
                    'email'      => $email,
                    'avatar_url' => $avatar
                ];
            }

            // セッション保存
            $_SESSION['user'] = $user;

            // ----------------------------------------------------
            // state パラメータの復元と判定 (Remember Me & 戻り先URL)
            // ----------------------------------------------------
            $redirect_target = 'dashboard.php'; // デフォルト転送先
            
            if (!empty($_GET['state'])) {
                $state_decoded = json_decode(base64_decode($_GET['state']), true);

                // 1. Remember Me の処理
                if (!empty($state_decoded['remember'])) {
                    $remember_token = bin2hex(random_bytes(32));
                    $token_hash     = hash('sha256', $remember_token);
                    $expires_at     = date('Y-m-d H:i:s', time() + (86400 * 30));

                    $stmt_token = $db->prepare("INSERT INTO pp_user_tokens_tbl (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
                    $stmt_token->execute([$user['id'], $token_hash, $expires_at]);

                    setcookie('remember_token', $remember_token, time() + (86400 * 30), '/', '', false, true);
                }

                // 2. 戻り先URL（return_url）の検証とセット（オープンリダイレクト対策）
                if (!empty($state_decoded['return_url'])) {
                    $raw_target = $state_decoded['return_url'];
                    // 自サイト内（BASE_URL配下または相対パス）のURLのみ許可
                    if (defined('BASE_URL') && strpos($raw_target, BASE_URL) === 0) {
                        $redirect_target = $raw_target;
                    } elseif (preg_match('/^\/[^\/]/', $raw_target)) {
                        $redirect_target = $raw_target;
                    }
                }
            }

            header('Location: ' . $redirect_target);
            exit;
        } else {
            $error_message = 'データベース接続に失敗しました。DB設定を確認してください。';
        }
    } else {
        $error_message = 'GitHubからのユーザー情報取得に失敗しました。';
    }
}

// ----------------------------------------------------
// GitHubログイン用ベースURLの生成
// ----------------------------------------------------
$github_oauth_base_url = 'https://github.com/login/oauth/authorize?' . http_build_query([
    'client_id'    => GITHUB_CLIENT_ID,
    'redirect_uri' => GITHUB_REDIRECT_URI,
    'scope'        => 'read:user user:email'
]);

// ログインボタンを押した時点の直前ページ（リファラ）を取得
$referer_url = $_SERVER['HTTP_REFERER'] ?? '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PointWorld - ログイン</title>
    <link rel="stylesheet" href="index.css">
</head>
<body>

<div class="container">
    <h1 class="title">ぽいんとわーるど</h1>
    <p class="subtitle">PointWorld へようこそ！</p>

    <?php if (!empty($error_message)): ?>
        <div class="alert-error">
            <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="login-form-group">
        <label class="remember-me-label">
            <input type="checkbox" id="remember_me" checked>
            <span>ログイン状態を保持する（30日間）</span>
        </label>
    </div>

    <a href="#" id="btn-github-login" class="btn-github" style="display:inline-flex; align-items:center; justify-content:center; gap:10px; background:#24292e; color:#fff; padding:12px 24px; border-radius:6px; text-decoration:none; font-weight:bold;">
        <svg height="20" width="20" viewBox="0 0 16 16" fill="white">
            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.28.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"></path>
        </svg>
        GitHubアカウントと連携してログイン
    </a>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const githubBtn = document.getElementById('btn-github-login');
    const rememberCheckbox = document.getElementById('remember_me');
    const oauthBaseUrl = <?= json_encode($github_oauth_base_url) ?>;
    const refererUrl = <?= json_encode($referer_url) ?>;

    githubBtn.addEventListener('click', (e) => {
        e.preventDefault();
        
        // state オブジェクトにログイン保持フラグとリダイレクト先を格納
        const stateData = {
            remember: rememberCheckbox.checked,
            return_url: refererUrl || 'dashboard.php'
        };

        // JSON化して Base64 エンコード
        const encodedState = btoa(JSON.stringify(stateData));
        
        window.location.href = oauthBaseUrl + '&state=' + encodeURIComponent(encodedState);
    });
});
</script>

</body>
</html>