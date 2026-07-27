<?php
require_once 'includes/functions.php';

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if ($name === '' || $email === '' || $password === '') {
        $errorMsg = 'すべての項目を入力してください。';
    } else {
        $result = registerUserAccount($name, $email, $password);
        if ($result['success']) {
            $_SESSION['user_id'] = $result['user_id'];
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
    <title>新規アカウント作成 - ToDo & Growth Log</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="auth-container">
        <h1>新規アカウント登録</h1>
        <p>冒険者アカウントを作成してログを記録しよう！</p>

        <?php if ($errorMsg !== ''): ?>
            <div class="error-msg"><?php echo h($errorMsg); ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <div class="form-group">
                <label>ニックネーム</label>
                <input type="text" name="name" required placeholder="例: さくら">
            </div>
            <div class="form-group">
                <label>メールアドレス</label>
                <input type="email" name="email" required placeholder="example@mail.com">
            </div>
            <div class="form-group">
                <label>パスワード</label>
                <input type="password" name="password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn-primary">アカウントを作成してスタート</button>
        </form>

        <p class="auth-link">すでにアカウントをお持ちの方は <a href="login.php">ログイン</a></p>
    </div>
</body>
</html>