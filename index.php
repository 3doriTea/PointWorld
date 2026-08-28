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

        $stmt = $db->prepare("
            SELECT u.*, t.id as token_id 
            FROM pp_user_tokens_tbl t
            JOIN pp_user_tbl u ON t.user_id = u.id
            WHERE t.token_hash = ? AND t.expires_at > NOW()
        ");
        $stmt->execute([$token_hash]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['user'] = [
                'id'         => $user['id'],
                'username'   => $user['username'],
                'email'      => $user['email'],
                'avatar_url' => $user['avatar_url']
            ];
            header('Location: dashboard.php');
            exit;
        } else {
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
// OAuth コールバック共通処理関数
// ----------------------------------------------------
function handleOAuthLogin($provider_name, $provider_user_id, $name, $email, $avatar, $state_data = []) {
    $db = getDB();
    if (!$db) {
        return 'データベース接続に失敗しました。DB設定を確認してください。';
    }

    // 1. プロバイダ情報（provider_name + provider_user_id）で検索
    $stmt = $db->prepare("
        SELECT u.* 
        FROM pp_user_tbl u 
        JOIN pp_user_providers_tbl up ON u.id = up.user_id 
        WHERE up.provider_name = ? AND up.provider_user_id = ?
    ");
    $stmt->execute([$provider_name, $provider_user_id]);
    $user = $stmt->fetch();

    // 2. 未連携の場合、メールアドレスで既存アカウントを探す（統合処理）
    if (!$user && !empty($email)) {
        $stmt_email = $db->prepare("SELECT * FROM pp_user_tbl WHERE email = ?");
        $stmt_email->execute([$email]);
        $existing_user = $stmt_email->fetch();

        if ($existing_user) {
            // 既存アカウントのアバターをそのまま維持（上書きしない）
            $user_id = $existing_user['id'];

            // 既存アカウントにプロバイダ連携情報を追加
            $stmt_prov = $db->prepare("INSERT INTO pp_user_providers_tbl (user_id, provider_name, provider_user_id) VALUES (?, ?, ?)");
            $stmt_prov->execute([$user_id, $provider_name, $provider_user_id]);

            $user = $existing_user;
        }
    }

    // 3. 既存アカウントも無い完全新規ユーザーの場合のみ登録
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

    // セッション保存
    $_SESSION['user'] = $user;

    // state パラメータ（Remember Me & 戻り先URL）の判定
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

// ----------------------------------------------------
// OAuth コールバック判定 & パラメータ処理
// ----------------------------------------------------
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $proxy_url = defined('HTTP_PROXY') ? HTTP_PROXY : '';

    // stateパラメータを復元してプロバイダ判定
    $state_data = [];
    if (!empty($_GET['state'])) {
        $state_data = json_decode(base64_decode($_GET['state']), true) ?? [];
    }

    $provider = $state_data['provider'] ?? $_GET['provider'] ?? ''; // stateから優先取得

    // A. GitHub OAuth の処理
    if ($provider === 'github') {
        // 1. トークン取得
        $ch = curl_init('https://github.com/login/oauth/access_token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code'          => $code,
                'client_id'     => GITHUB_CLIENT_ID,
                'client_secret' => GITHUB_CLIENT_SECRET,
                'redirect_uri'  => GITHUB_REDIRECT_URI
            ]),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'PointWorld-App',
            CURLOPT_TIMEOUT => 15
        ]);
        if (!empty($proxy_url)) curl_setopt($ch, CURLOPT_PROXY, $proxy_url);

        $token_data = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($token_data['access_token'])) {
            $access_token = $token_data['access_token'];

            // 2. ユーザー情報取得
            $ch = curl_init('https://api.github.com/user');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token, 'Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'PointWorld-App',
                CURLOPT_TIMEOUT => 15
            ]);
            if (!empty($proxy_url)) curl_setopt($ch, CURLOPT_PROXY, $proxy_url);

            $user_info = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (isset($user_info['id'])) {
                $github_id = (string)$user_info['id'];
                $name      = $user_info['name'] ?? $user_info['login'] ?? 'PointWorld User';
                $avatar    = $user_info['avatar_url'] ?? '';
                $email     = $user_info['email'] ?? '';

                // メールが非公開の場合は追加取得
                if (empty($email)) {
                    $ch = curl_init('https://api.github.com/user/emails');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token, 'Accept: application/json'],
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_USERAGENT => 'PointWorld-App',
                        CURLOPT_TIMEOUT => 15
                    ]);
                    if (!empty($proxy_url)) curl_setopt($ch, CURLOPT_PROXY, $proxy_url);

                    $emails_data = json_decode(curl_exec($ch), true);
                    curl_close($ch);

                    if (is_array($emails_data)) {
                        foreach ($emails_data as $e_obj) {
                            if (!empty($e_obj['primary'])) {
                                $email = $e_obj['email'];
                                break;
                            }
                        }
                    }
                }

                $error_message = handleOAuthLogin('github', $github_id, $name, $email, $avatar, $state_data);
            }
        } else {
            $error_message = 'GitHubアクセストークンの取得に失敗しました。';
        }
    } 
    // B. Google OAuth の処理
    else {
        // 1. トークン取得
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code'          => $code,
                'client_id'     => GOOGLE_CLIENT_ID,
                'client_secret' => GOOGLE_CLIENT_SECRET,
                'redirect_uri'  => GOOGLE_REDIRECT_URI,
                'grant_type'    => 'authorization_code'
            ]),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'PointWorld-App',
            CURLOPT_TIMEOUT => 15
        ]);
        if (!empty($proxy_url)) curl_setopt($ch, CURLOPT_PROXY, $proxy_url);

        $token_data = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($token_data['access_token'])) {
            // 2. ユーザー情報取得
            $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . $token_data['access_token']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'PointWorld-App',
                CURLOPT_TIMEOUT => 15
            ]);
            if (!empty($proxy_url)) curl_setopt($ch, CURLOPT_PROXY, $proxy_url);

            $user_info = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (isset($user_info['sub'])) {
                $google_id = (string)$user_info['sub'];
                $email     = $user_info['email'] ?? '';
                $name      = $user_info['name'] ?? 'PointWorld User';
                $avatar    = $user_info['picture'] ?? '';

                $error_message = handleOAuthLogin('google', $google_id, $name, $email, $avatar, $state_data);
            }
        } else {
            $error_message = 'Googleアクセストークンの取得に失敗しました。';
        }
    }
}

// ----------------------------------------------------
// OAuth ベースURLの生成
// ----------------------------------------------------
$google_oauth_base_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'prompt'        => 'select_account'
]);

$github_oauth_base_url = 'https://github.com/login/oauth/authorize?' . http_build_query([
    'client_id'    => GITHUB_CLIENT_ID,
    'redirect_uri' => GITHUB_REDIRECT_URI,
    'scope'        => 'read:user user:email'
]);

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
            <span>ログイン状態を保持する (30日間)</span>
        </label>
    </div>

    <!-- ログインボタンエリア -->
    <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
        <!-- Google ログインボタン -->
        <a href="#" id="btn-google-login" class="btn-google" style="display:inline-flex; align-items:center; justify-content:center; gap:10px; padding:12px 24px; border-radius:6px; text-decoration:none; font-weight:bold;">
            <svg viewBox="0 0 24 24" width="20" height="20">
                <path d="M12.545,10.239v3.821h5.445c-0.712,2.315-2.647,3.972-5.445,3.972c-3.332,0-6.033-2.701-6.033-6.032s2.701-6.032,6.033-6.032c1.498,0,2.866,0.549,3.921,1.453l2.814-2.814C17.503,2.988,15.139,2,12.545,2C7.021,2,2.543,6.477,2.543,12s4.478,10,10.002,10c8.396,0,10.249-7.85,9.426-11.761H12.545z"/>
            </svg>
            Googleアカウントでログイン
        </a>

        <!-- GitHub ログインボタン -->
        <a href="#" id="btn-github-login" class="btn-github" style="display:inline-flex; align-items:center; justify-content:center; gap:10px; background:#24292e; color:#fff; padding:12px 24px; border-radius:6px; text-decoration:none; font-weight:bold;">
            <svg height="20" width="20" viewBox="0 0 16 16" fill="white">
                <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.28.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"></path>
            </svg>
            GitHubアカウントでログイン
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const googleBtn = document.getElementById('btn-google-login');
    const githubBtn = document.getElementById('btn-github-login');
    const rememberCheckbox = document.getElementById('remember_me');

    const googleBaseUrl = <?= json_encode($google_oauth_base_url) ?>;
    const githubBaseUrl = <?= json_encode($github_oauth_base_url) ?>;
    const refererUrl = <?= json_encode($referer_url) ?>;

    function buildState(providerName) {
        const stateData = {
            provider: providerName,
            remember: rememberCheckbox.checked,
            return_url: refererUrl || 'dashboard.php'
        };
        return encodeURIComponent(btoa(JSON.stringify(stateData)));
    }

    // Google ログイン
    googleBtn.addEventListener('click', (e) => {
        e.preventDefault();
        window.location.href = googleBaseUrl + '&state=' + buildState('google');
    });

    // GitHub ログイン
    githubBtn.addEventListener('click', (e) => {
        e.preventDefault();
        window.location.href = githubBaseUrl + '&state=' + buildState('github');
    });
});
</script>

</body>
</html>
