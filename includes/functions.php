<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

// XSS対策用エスケープ関数
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// DB接続関数（環境変数を優先）
function getDbConnection() {
    $host = getenv('MYSQLHOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost');
    $dbname = getenv('MYSQLDATABASE') ?: (defined('DB_NAME') ? DB_NAME : 'todo_db');
    $user = getenv('MYSQLUSER') ?: (defined('DB_USER') ? DB_USER : 'root');
    $pass = getenv('MYSQLPASSWORD') ?: (defined('DB_PASS') ? DB_PASS : '');
    $port = getenv('MYSQLPORT') ?: (defined('DB_PORT') ? DB_PORT : '3306');

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

// ★追加：ログイン中のユーザー情報を取得する関数
function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) return null;
    
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

// 新規ユーザーアカウント登録
function registerUserAccount($name, $email, $password) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'このメールアドレスは既に登録されています。'];
    }
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $insertStmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
    $insertStmt->execute([$name, $email, $hashedPassword]);
    return ['success' => true, 'user_id' => $db->lastInsertId()];
}

// ユーザーログイン処理
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

// ログイン必須チェック
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}

/**
 * 稼働時間（分）から獲得基本EXPを計算
 * 未設定: 50 EXP / 15分未満: 20 EXP / 以降 1分=1EXP (上限300)
 */
function calculateTaskExp($minutes) {
    if (empty($minutes) || !is_numeric($minutes)) {
        return 50;
    }
    $m = (int)$minutes;
    if ($m < 15) {
        return 20;
    }
    return min(300, $m);
}

/**
 * 期日（締め切り）より早く達成した場合の早割りボーナスptを計算
 * 1日早く達成するごとに +10 pt ボーナス
 */
function calculateEarlyBonus($dueDate, $completedAt = null) {
    if (empty($dueDate)) return 0;
    
    $completedTimestamp = $completedAt ? strtotime($completedAt) : time();
    $completedDateStr = date('Y-m-d', $completedTimestamp);

    $due = new DateTime($dueDate);
    $comp = new DateTime($completedDateStr);

    if ($comp < $due) {
        $diffDays = $comp->diff($due)->days;
        return $diffDays * 10;
    }
    return 0;
}

/**
 * 成長曲線レベル計算関数
 * Level N になるための必要累計EXP = (N-1) * N / 2 * 100
 */
function getLevelInfo($totalExp) {
    $level = 1;
    $currentLevelBaseExp = 0;

    while (true) {
        $nextBase = $currentLevelBaseExp + ($level * 100);
        if ($totalExp < $nextBase) {
            break;
        }
        $currentLevelBaseExp = $nextBase;
        $level++;
    }

    $expInCurrentLevel = $totalExp - $currentLevelBaseExp;
    $neededExpForNextLevel = $level * 100;
    $progressPercent = min(100, round(($expInCurrentLevel / $neededExpForNextLevel) * 100));

    // アバター成長段階 (Stage)
    $avatarStage = 1;
    $avatarEmoji = '🥚';
    $avatarTitle = 'タマゴ初心者';
    $avatarClass = 'stage-egg';

    if ($level >= 20) {
        $avatarStage = 4;
        $avatarEmoji = '👑';
        $avatarTitle = '伝説の勇者';
        $avatarClass = 'stage-hero';
    } elseif ($level >= 10) {
        $avatarStage = 3;
        $avatarEmoji = '🛡️';
        $avatarTitle = '頼れるナイト';
        $avatarClass = 'stage-knight';
    } elseif ($level >= 5) {
        $avatarStage = 2;
        $avatarEmoji = '🐣';
        $avatarTitle = 'ヒヨコ冒険者';
        $avatarClass = 'stage-chick';
    }

    return [
        'level' => $level,
        'exp_in_level' => $expInCurrentLevel,
        'needed_exp' => $neededExpForNextLevel,
        'progress_percent' => $progressPercent,
        'avatar_stage' => $avatarStage,
        'avatar_emoji' => $avatarEmoji,
        'avatar_title' => $avatarTitle,
        'avatar_class' => $avatarClass
    ];
}

// 経験値加算 ＆ ユーザー情報更新
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

/**
 * 完了済みログの全件取得（グラフ・集計・カレンダー用）
 */
function getCompletedLogs($userId) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT id, title, category, due_date, actual_minutes, estimated_minutes, completed_at FROM logs WHERE user_id = ? AND status = 'completed' ORDER BY completed_at DESC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * 個別の完了ログ削除処理
 */
function deleteCompletedLog($userId, $logId) {
    $db = getDbConnection();
    $stmt = $db->prepare("DELETE FROM logs WHERE id = ? AND user_id = ? AND status = 'completed'");
    $stmt->execute([$logId, $userId]);
    recalculateUserExp($userId);
}

/**
 * 完了ログの全削除処理（リセット）
 */
function clearAllCompletedLogs($userId) {
    $db = getDbConnection();
    $stmt = $db->prepare("DELETE FROM logs WHERE user_id = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    recalculateUserExp($userId);
}

/**
 * ユーザーの経験値とレベルを完了ログから再計算（早割りボーナス含む）
 */
function recalculateUserExp($userId) {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT due_date, actual_minutes, estimated_minutes, completed_at FROM logs WHERE user_id = ? AND status = 'completed'");
    $stmt->execute([$userId]);
    $logs = $stmt->fetchAll();

    $totalExp = 0;
    foreach ($logs as $log) {
        $minutes = $log['actual_minutes'] ?? $log['estimated_minutes'] ?? 0;
        $baseExp = calculateTaskExp($minutes);
        $bonusExp = calculateEarlyBonus($log['due_date'], $log['completed_at']);
        $totalExp += ($baseExp + $bonusExp);
    }

    $levelInfo = getLevelInfo($totalExp);
    $updateStmt = $db->prepare("UPDATE users SET level = ?, exp = ?, avatar_stage = ? WHERE id = ?");
    $updateStmt->execute([$levelInfo['level'], $totalExp, $levelInfo['avatar_stage'], $userId]);
}

/**
 * Googleカレンダー登録用URL生成関数
 */
function generateGoogleCalendarUrl($title, $category, $dueDate, $estimatedMinutes) {
    $baseUrl = "https://www.google.com/calendar/render?action=TEMPLATE";
    
    $eventTitle = "【" . ($category ?: '一般') . "】" . $title;
    $details = "ToDo & Growth Log より登録したタスクです。\nカテゴリ: " . ($category ?: '一般');
    if ($estimatedMinutes) {
        $details .= "\n予定時間: " . $estimatedMinutes . "分";
    }

    if (!empty($dueDate)) {
        $startDate = date('Ymd', strtotime($dueDate));
        if (!empty($estimatedMinutes)) {
            $startTime = "090000";
            $endTimestamp = strtotime($dueDate . " 09:00:00") + ((int)$estimatedMinutes * 60);
            $endDate = date('Ymd', $endTimestamp);
            $endTime = date('His', $endTimestamp);
            $dates = "{$startDate}T{$startTime}/{$endDate}T{$endTime}";
        } else {
            $endDate = date('Ymd', strtotime($dueDate . ' +1 day'));
            $dates = "{$startDate}/{$endDate}";
        }
    } else {
        $startDate = date('Ymd');
        $endDate = date('Ymd', strtotime('+1 day'));
        $dates = "{$startDate}/{$endDate}";
    }

    return $baseUrl . 
        "&text=" . urlencode($eventTitle) . 
        "&dates=" . $dates . 
        "&details=" . urlencode($details);
}

/**
 * マイキャラ分析 ＆ ユーザー統計データの取得
 */
function getUserStats($userId) {
    $logs = getCompletedLogs($userId);
    $taskCount = count($logs);
    $totalMinutes = 0;
    $catMap = [];
    
    foreach ($logs as $log) {
        $m = (int)($log['actual_minutes'] ?? $log['estimated_minutes'] ?? 0);
        $totalMinutes += $m;
        $cat = $log['category'] ?: '一般';
        $catMap[$cat] = ($catMap[$cat] ?? 0) + $m;
    }

    arsort($catMap);
    $topCat = !empty($catMap) ? array_key_first($catMap) : 'なし';

    return [
        'count' => $taskCount,
        'total_minutes' => $totalMinutes,
        'top_category' => $topCat
    ];
}

/**
 * マイキャラAI分析＆リアルタイム会話メッセージ生成エンジン
 */
function getAiCharacterAdvice($userId, $levelInfo) {
    $logs = getCompletedLogs($userId);
    $taskCount = count($logs);
    
    $stage = $levelInfo['avatar_stage'];

    if ($taskCount === 0) {
        if ($stage === 1) return "「まだ達成ログがないピヨ！新しいタスクを完了すると、ボクが自動で分析してアドバイスするタマ！」";
        if ($stage === 2) return "「準備運動はバッチリだピヨ！最初のタスクを達成して、レベルアップのスタートを切るピヨ！」";
        if ($stage === 3) return "「準備は整っておるな。最初の試練を突破し、実績をここに刻むのだ！」";
        return "「伝説の始まりだな。いつでも最初のミッションを達成するが良い！」";
    }

    $totalMinutes = 0;
    $catMap = [];
    $todayCount = 0;
    $todayStr = date('Y-m-d');

    foreach ($logs as $log) {
        $m = (int)($log['actual_minutes'] ?? $log['estimated_minutes'] ?? 0);
        $totalMinutes += $m;
        
        $cat = $log['category'] ?: '一般';
        $catMap[$cat] = ($catMap[$cat] ?? 0) + $m;

        $completedTime = strtotime($log['completed_at']);
        if (date('Y-m-d', $completedTime) === $todayStr) {
            $todayCount++;
        }
    }

    arsort($catMap);
    $topCat = array_key_first($catMap);
    $topCatTime = $catMap[$topCat];
    $topCatPercent = round(($topCatTime / max(1, $totalMinutes)) * 100);

    $hours = floor($totalMinutes / 60);
    $mins = $totalMinutes % 60;
    $timeText = $hours > 0 ? "{$hours}時間{$mins}分" : "{$mins}分";

    $todayBonusText = $todayCount > 0 ? " 今日はすでに【{$todayCount}件】完了して絶好調だピヨ！" : "";

    if ($stage === 1) {
        $msg = "「記録を分析したピヨ！累計【{$taskCount}件】（合計{$timeText}）達成したタマ！{$todayBonusText}\n";
        $msg .= "特に【{$topCat}】に全体の {$topCatPercent}% の時間を費やしてるピヨ！この調子で次のレベルを目指すタマ！」";
    } elseif ($stage === 2) {
        $msg = "「ナイス分析結果だピヨ！通算【{$taskCount}件】（{$timeText}）のログがたまってるピヨ！{$todayBonusText}\n";
        $msg .= "【{$topCat}】への集中力がすごくて全体の{$topCatPercent}%を占めてるピヨ！スキマ時間でさらに経験値を稼ぐピヨ！」";
    } elseif ($stage === 3) {
        $msg = "「見事な鍛錬の記録だ殿！累積作業時間は【{$timeText}】（全{$taskCount}件）に達したぞ！{$todayBonusText}\n";
        $msg .= "分析の結果、【{$topCat}】（全体の{$topCatPercent}%）への集中がズバ抜けておる。本日も鍛錬に励むとしよう！」";
    } else {
        $msg = "「圧巻のログデータだな！累計【{$timeText}】、実に{$taskCount}件もの試練を打ち破ってきた！{$todayBonusText}\n";
        $msg .= "主の強みは【{$topCat}】への圧倒的な投下時間（{$topCatPercent}%）だ。さらなる高みへ躍進しようではないか！」";
    }

    return $msg;
}
?