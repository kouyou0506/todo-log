<?php
require_once 'includes/functions.php';

$errorMsg = '';

// すでにログイン中ならメイン画面へ
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 緑のゲストボタンが押された場合（一発ログイン＆自動アカウント作成）
    if (isset($_POST['guest_login'])) {
        $db = getDbConnection();
        $guestEmail = 'guest@example.com';

        // ゲストユーザーが既に存在するか確認
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$guestEmail]);
        $guestUser = $stmt->fetch();

        if (!$guestUser) {
            // いなければその場でDBに作成
            $hashedPassword = password_hash('guest1234', PASSWORD_DEFAULT);
            $insertStmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            $insertStmt->execute(['ゲストユーザー', $guestEmail, $hashedPassword]);
            $_SESSION['user_id'] = $db->lastInsertId();
        } else {
            // いればそのIDでセッションを開始
            $_SESSION['user_id'] = $guestUser['id'];
        }

        header('Location: index.php');
        exit();
    }

    // 通常のログイン処理
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errorMsg = 'メールアドレスとパスワードを入力してください。';
    } else {
        $result = loginUserAccount($email, $password);
        if ($result['success']) {
            header('Location: index.php');
            exit();
        } else {
            $errorMsg = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ログイン - ToDo & Growth Log</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="auth-container">
        <h1>ログイン</h1>
        <p>おかえりなさい！冒険の続きをはじめよう。</p>

        <?php if ($errorMsg !== ''): ?>
            <div class="error-msg"><?php echo h($errorMsg); ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <div class="form-group">
                <label>メールアドレス</label>
                <input type="email" name="email" required placeholder="example@mail.com">
            </div>
            <div class="form-group">
                <label>パスワード</label>
                <input type="password" name="password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn-primary">ログイン</button>
        </form>

        <div style="margin-top: 20px; text-align: center; border-top: 1px solid #333; padding-top: 15px;">
            <p style="font-size: 0.85rem; color: #aaa; margin-bottom: 8px;">採用担当者様・お試しの方へ</p>
            <form action="login.php" method="POST">
                <input type="hidden" name="guest_login" value="1">
                <button type="submit" style="background-color: #2ea44f; color: white; padding: 10px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%;">
                    👀 ワンクリックでお試しログイン
                </button>
            </form>
        </div>

        <p class="auth-link">アカウントをお持ちでない方は <a href="register.php">新規登録</a></p>
    </div>
</body>
</html>