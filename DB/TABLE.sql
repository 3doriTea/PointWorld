-- ----------------------------------------------------
-- 1. ユーザー基本情報テーブル
-- ----------------------------------------------------
CREATE TABLE IF NOT EXISTS pp_user_tbl (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    avatar_url TEXT DEFAULT NULL, -- 長い画像URLに対応するためTEXT型へ変更
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- 2. 外部OAuth認証情報統合テーブル（Google, Discord等）
-- ----------------------------------------------------
CREATE TABLE IF NOT EXISTS pp_user_providers_tbl (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider_name VARCHAR(50) NOT NULL, -- 'google', 'discord' など
    provider_user_id VARCHAR(255) NOT NULL, -- 各サービスの固有ID (Googleの sub や Discordの id)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES pp_user_tbl(id) ON DELETE CASCADE,
    UNIQUE KEY unique_provider (provider_name, provider_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- 3. ポイント保有量管理テーブル
-- ----------------------------------------------------
CREATE TABLE IF NOT EXISTS pp_point_tbl (
    user_id INT PRIMARY KEY,
    current_points INT NOT NULL DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES pp_user_tbl(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- 4. ポイント増減履歴テーブル
-- ----------------------------------------------------
CREATE TABLE IF NOT EXISTS pp_point_history_tbl (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    point_change INT NOT NULL, -- 増減値（例: +100 や -50）
    reason VARCHAR(255) NOT NULL, -- 理由（例: "新規登録特典", "ログインボーナス"）
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES pp_user_tbl(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------
-- 5. リメンバートークン用
-- ----------------------------------------------------
CREATE TABLE IF NOT EXISTS pp_user_tokens_tbl (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL, -- SHA-256でハッシュ化したトークン
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES pp_user_tbl(id) ON DELETE CASCADE,
    KEY idx_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
