<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function getDbConnection() {
    // 1. 環境変数から優先的に取得（Render等のDB_HOST/MYSQLHOST両対応）
    $host = getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1'));
    $dbname = getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: (defined('DB_NAME') ? DB_NAME : 'todo_db'));
    $user = getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: (defined('DB_USER') ? DB_USER : 'root'));
    $pass = getenv('DB_PASS') ?: (getenv('MYSQLPASSWORD') ?: (defined('DB_PASS') ? DB_PASS : ''));
    $port = getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: (defined('DB_PORT') ? DB_PORT : '3306'));

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        exit('DB接続エラー: ' . $e->getMessage());
    }
}

function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) return null;
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function registerUserAccount($name, $email, $password) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) return ['success' => false, 'message' => 'このメールアドレスは既に登録されています。'];
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $insertStmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
    $insertStmt->execute([$name, $email, $hashedPassword]);
    return ['success' => true, 'user_id' => $db->lastInsertId()];
}

function loginUserAccount($email, $password) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        return ['success' => true];
    }
    return ['success' => false, 'message' => 'メールアドレスまたはパスワードが正しくありません。'];
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}

function calculateTaskExp($minutes) {
    if (empty($minutes) || !is_numeric($minutes)) return 50;
    return $minutes < 15 ? 20 : min(300, (int)$minutes);
}

function calculateEarlyBonus($dueDate, $completedAt = null) {
    if (empty($dueDate)) return 0;
    $completedDateStr = date('Y-m-d', $completedAt ? strtotime($completedAt) : time());
    $due = new DateTime($dueDate);
    $comp = new DateTime($completedDateStr);
    return ($comp < $due) ? $comp->diff($due)->days * 10 : 0;
}

function getLevelInfo($totalExp) {
    $level = 1; $currentLevelBaseExp = 0;
    while (true) {
        $nextBase = $currentLevelBaseExp + ($level * 100);
        if ($totalExp < $nextBase) break;
        $currentLevelBaseExp = $nextBase;
        $level++;
    }
    $expInCurrentLevel = $totalExp - $currentLevelBaseExp;
    $neededExpForNextLevel = $level * 100;
    
    $avatarData = [
        1 => ['emoji' => '🥚', 'title' => 'タマゴ初心者', 'class' => 'stage-egg'],
        2 => ['emoji' => '🐣', 'title' => 'ヒヨコ冒険者', 'class' => 'stage-chick'],
        3 => ['emoji' => '🛡️', 'title' => '頼れるナイト', 'class' => 'stage-knight'],
        4 => ['emoji' => '👑', 'title' => '伝説の勇者', 'class' => 'stage-hero']
    ];
    $stage = ($level >= 20) ? 4 : (($level >= 10) ? 3 : (($level >= 5) ? 2 : 1));

    return [
        'level' => $level, 'exp_in_level' => $expInCurrentLevel, 'needed_exp' => $neededExpForNextLevel,
        'progress_percent' => min(100, round(($expInCurrentLevel / $neededExpForNextLevel) * 100)),
        'avatar_stage' => $stage, 'avatar_emoji' => $avatarData[$stage]['emoji'],
        'avatar_title' => $avatarData[$stage]['title'], 'avatar_class' => $avatarData[$stage]['class']
    ];
}

function addExperience($userId, $gainedExp) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT exp FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) return;
    $newTotalExp = $user['exp'] + $gainedExp;
    $levelInfo = getLevelInfo($newTotalExp);
    $updateStmt = $db->prepare("UPDATE users SET level = ?, exp = ?, avatar_stage = ? WHERE id = ?");
    $updateStmt->execute([$levelInfo['level'], $newTotalExp, $levelInfo['avatar_stage'], $userId]);
}

function getCompletedLogs($userId) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT id, title, category, due_date, actual_minutes, estimated_minutes, completed_at FROM logs WHERE user_id = ? AND status = 'completed' ORDER BY completed_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function deleteCompletedLog($userId, $logId) {
    $db = getDbConnection();
    $stmt = $db->prepare("DELETE FROM logs WHERE id = ? AND user_id = ? AND status = 'completed'");
    $stmt->execute([$logId, $userId]);
    recalculateUserExp($userId);
}

function clearAllCompletedLogs($userId) {
    $db = getDbConnection();
    $stmt = $db->prepare("DELETE FROM logs WHERE user_id = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    recalculateUserExp($userId);
}

function recalculateUserExp($userId) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT due_date, actual_minutes, estimated_minutes, completed_at FROM logs WHERE user_id = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    $logs = $stmt->fetchAll();
    $totalExp = 0;
    foreach ($logs as $log) {
        $totalExp += (calculateTaskExp($log['actual_minutes'] ?? $log['estimated_minutes']) + calculateEarlyBonus($log['due_date'], $log['completed_at']));
    }
    $levelInfo = getLevelInfo($totalExp);
    $updateStmt = $db->prepare("UPDATE users SET level = ?, exp = ?, avatar_stage = ? WHERE id = ?");
    $updateStmt->execute([$levelInfo['level'], $totalExp, $levelInfo['avatar_stage'], $userId]);
}

function generateGoogleCalendarUrl($title, $category, $dueDate, $estimatedMinutes) {
    $baseUrl = "https://www.google.com/calendar/render?action=TEMPLATE";
    $dates = !empty($dueDate) ? date('Ymd', strtotime($dueDate)) : date('Ymd');
    return $baseUrl . "&text=" . urlencode("【" . ($category ?: '一般') . "】" . $title) . "&dates=" . $dates . "/" . date('Ymd', strtotime($dates . ' +1 day'));
}

function getUserStats($userId) {
    $logs = getCompletedLogs($userId);
    $total = 0; $cats = [];
    foreach ($logs as $l) {
        $m = (int)($l['actual_minutes'] ?? $l['estimated_minutes'] ?? 0);
        $total += $m;
        $c = $l['category'] ?: '一般';
        $cats[$c] = ($cats[$c] ?? 0) + $m;
    }
    arsort($cats);
    return ['count' => count($logs), 'total_minutes' => $total, 'top_category' => array_key_first($cats) ?: 'なし'];
}

function getAiCharacterAdvice($userId, $levelInfo) {
    return "分析完了！レベル" . $levelInfo['level'] . "の冒険者として、今の調子で突き進むタマ！";
}