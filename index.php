<?php
session_start();
require_once(__DIR__ . '/functions.php');

$error_message = '';

// ----------------------------------------------------
// 0. 自動ログイン（Remember Me）チェック処理
// ----------------------------------------------------
if (!isset($_SESSION['user']) && isset($_COOKIE['remember_token'])) {
    $db = getDB();
    if ($db) {
        $token_hash = hash('sha256', $_COOKIE['remember_token']);
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

if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

// ----------------------------------------------------
// OAuth コールバック判定 & パラメータ処理
// ----------------------------------------------------
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $state_data = !empty($_GET['state']) ? json_decode(base64_decode($_GET['state']), true) : [];
    $provider = $state_data['provider'] ?? $_GET['provider'] ?? '';

    if ($provider === 'github') {
        // GitHub トークン取得
        $token_data = sendHttpRequest('https://github.com/login/oauth/access_token', [
            'code'          => $code,
            'client_id'     => GITHUB_CLIENT_ID,
            'client_secret' => GITHUB_CLIENT_SECRET,
            'redirect_uri'  => GITHUB_REDIRECT_URI
        ], ['Accept: application/json'], true);

        if (isset($token_data['access_token'])) {
            $access_token = $token_data['access_token'];
            $auth_header = ['Authorization: Bearer ' . $access_token, 'Accept: application/json'];

            $user_info = sendHttpRequest('https://api.github.com/user', [], $auth_header);

            if (isset($user_info['id'])) {
                $github_id = (string)$user_info['id'];
                $name      = $user_info['name'] ?? $user_info['login'] ?? 'PointWorld User';
                $avatar    = $user_info['avatar_url'] ?? '';
                $email     = $user_info['email'] ?? '';

                if (empty($email)) {
                    $emails_data = sendHttpRequest('https://api.github.com/user/emails', [], $auth_header);
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
    } else {
        // Google トークン取得
        $token_data = sendHttpRequest('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code'
        ], [], true);

        if (isset($token_data['access_token'])) {
            $user_info = sendHttpRequest('https://www.googleapis.com/oauth2/v3/userinfo', ['access_token' => $token_data['access_token']]);

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

// OAuth URL生成
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
        <div class="alert-error"><?= h($error_message) ?></div>
    <?php endif; ?>

    <div class="login-form-group">
        <label class="remember-me-label">
            <input type="checkbox" id="remember_me" checked>
            <span>ログイン状態を保持する (30日間)</span>
        </label>
    </div>

    <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 15px;">
        <a href="#" id="btn-google-login" class="btn-google" style="display:inline-flex; align-items:center; justify-content:center; gap:10px; padding:12px 24px; border-radius:6px; text-decoration:none; font-weight:bold;">
            <svg viewBox="0 0 24 24" width="20" height="20">
                <path d="M12.545,10.239v3.821h5.445c-0.712,2.315-2.647,3.972-5.445,3.972c-3.332,0-6.033-2.701-6.033-6.032s2.701-6.032,6.033-6.032c1.498,0,2.866,0.549,3.921,1.453l2.814-2.814C17.503,2.988,15.139,2,12.545,2C7.021,2,2.543,6.477,2.543,12s4.478,10,10.002,10c8.396,0,10.249-7.85,9.426-11.761H12.545z"/>
            </svg>
            Googleアカウントでログイン
        </a>

        <a href="#" id="btn-github-login" class="btn-github" style="display:inline-flex; align-items:center; justify-content:center; gap:10px; background:#24292e; color:#fff; padding:12px 24px; border-radius:6px; text-decoration:none; font-weight:bold;">
            <svg height="20" width="20" viewBox="0 0 16 16" fill="white">
                <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.28.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"></path>
            </svg>
            GitHubアカウントでログイン
        </a>
    </div>
</div>

<script>
//  REF: https://developer.mozilla.org/ja/docs/Web/API/Document/DOMContentLoaded_event
// MEMO: HTMLが完全に読み込まれた後に呼ばれるコールバックイベント
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

    googleBtn.addEventListener('click', (e) => {
        e.preventDefault();
        window.location.href = googleBaseUrl + '&state=' + buildState('google');
    });

    githubBtn.addEventListener('click', (e) => {
        e.preventDefault();
        window.location.href = githubBaseUrl + '&state=' + buildState('github');
    });
});
</script>

</body>
</html>
