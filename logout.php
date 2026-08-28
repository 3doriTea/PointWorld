<?php
require_once(__DIR__ . '/functions.php');

// ユーザーをログアウトさせる！！！
logoutUser();

header('Location: index.php');
exit;
