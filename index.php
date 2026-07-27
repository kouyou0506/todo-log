<?php
require_once 'includes/functions.php';

requireLogin();

$user = getCurrentUser();
$userId = $user['id'];
$db = getDbConnection();

// 過去に使用したカテゴリ一覧
$catStmt = $db->prepare("SELECT DISTINCT category FROM logs WHERE user_id = ? AND category IS NOT NULL AND category != '' ORDER BY category ASC");
$catStmt->execute([$userId]);
$existingCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// タスク追加処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task'])) {
    $title = trim($_POST['title']);
    $category = trim($_POST['category']) !== '' ? trim($_POST['category']) : '一般';
    $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $estimatedMinutes = (!empty($_POST['estimated_minutes']) && is_numeric($_POST['estimated_minutes'])) ? (int)$_POST['estimated_minutes'] : null;

    if ($title !== '') {
        $addStmt = $db->prepare("INSERT INTO logs (user_id, title, category, due_date, estimated_minutes) VALUES (?, ?, ?, ?, ?)");
        $addStmt->execute([$userId, $title, $category, $dueDate, $estimatedMinutes]);
    }
    header('Location: index.php');
    exit();
}

// タスク更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_task'])) {
    $taskId = $_POST['task_id'];
    $title = trim($_POST['title']);
    $category = trim($_POST['category']) !== '' ? trim($_POST['category']) : '一般';
    $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $estimatedMinutes = (!empty($_POST['estimated_minutes']) && is_numeric($_POST['estimated_minutes'])) ? (int)$_POST['estimated_minutes'] : null;

    if ($title !== '') {
        $updateStmt = $db->prepare("UPDATE logs SET title = ?, category = ?, due_date = ?, estimated_minutes = ? WHERE id = ? AND user_id = ?");
        $updateStmt->execute([$title, $category, $dueDate, $estimatedMinutes, $taskId, $userId]);
    }
    header('Location: index.php');
    exit();
}

// タスク完了処理（時間連動EXP ＋ 早割りボーナス計算）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_complete_task'])) {
    $taskId = $_POST['complete_task_id'];
    $actualMinutes = (!empty($_POST['actual_minutes']) && is_numeric($_POST['actual_minutes'])) ? (int)$_POST['actual_minutes'] : null;
    
    $checkStmt = $db->prepare("SELECT due_date, estimated_minutes FROM logs WHERE id = ? AND user_id = ? AND status = 'pending'");
    $checkStmt->execute([$taskId, $userId]);
    $task = $checkStmt->fetch();

    if ($task) {
        $earnedMinutes = $actualMinutes ?? $task['estimated_minutes'] ?? 0;
        $baseExp = calculateTaskExp($earnedMinutes);
        
        $nowStr = date('Y-m-d H:i:s');
        $earlyBonusExp = calculateEarlyBonus($task['due_date'], $nowStr);
        $totalGainedExp = $baseExp + $earlyBonusExp;
        
        $logStmt = $db->prepare("UPDATE logs SET status = 'completed', actual_minutes = ?, completed_at = NOW() WHERE id = ? AND user_id = ?");
        $logStmt->execute([$actualMinutes, $taskId, $userId]);
        
        addExperience($userId, $totalGainedExp);
    }
    header('Location: index.php');
    exit();
}

// タスク削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_task_id'])) {
    $taskId = $_POST['delete_task_id'];
    $deleteStmt = $db->prepare("DELETE FROM logs WHERE id = ? AND user_id = ?");
    $deleteStmt->execute([$taskId, $userId]);
    header('Location: index.php');
    exit();
}

// 完了ログの個別削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_completed_log_id'])) {
    $logId = $_POST['delete_completed_log_id'];
    deleteCompletedLog($userId, $logId);
    header('Location: index.php');
    exit();
}

// 完了ログの全削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all_completed_logs'])) {
    clearAllCompletedLogs($userId);
    header('Location: index.php');
    exit();
}

// 未完了タスク
$taskStmt = $db->prepare("SELECT * FROM logs WHERE user_id = ? AND status = 'pending' ORDER BY due_date IS NULL, due_date ASC, created_at DESC");
$taskStmt->execute([$userId]);
$tasks = $taskStmt->fetchAll();

// 完了ログ
$completedLogs = getCompletedLogs($userId);

// ステータス・情報
$levelInfo = getLevelInfo($user['exp']);
$stats = getUserStats($userId);
$aiAdvice = getAiCharacterAdvice($userId, $levelInfo);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ToDo & Growth Log</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container">
        <!-- 上部ユーザーバー -->
        <div class="top-bar">
            <span>ログイン中: <strong><?php echo h($user['name']); ?></strong> さん</span>
            <a href="logout.php" class="btn-logout">ログアウト</a>
        </div>

        <h1>ToDo & Growth Log</h1>
        
        <!-- 🎮 マイキャラ・ステータスカード -->
        <div class="card-status-game <?php echo $levelInfo['avatar_class']; ?>">
            <div class="game-card-header">
                <span class="rank-badge">STAGE <?php echo $levelInfo['avatar_stage']; ?></span>
                <span class="user-hero-name"><?php echo h($user['name']); ?> の冒険ログ</span>
            </div>

            <div class="avatar-hero-container">
                <!-- マイキャラ本体 (クリックでジャンプ＆セリフ強調) -->
                <div class="avatar-stage-box" id="avatarAvatar" onclick="triggerAvatarAction()">
                    <div class="avatar-aura"></div>
                    <span class="avatar-icon-lg"><?php echo $levelInfo['avatar_emoji']; ?></span>
                </div>

                <div class="hero-info-box">
                    <div class="hero-title-badge"><?php echo $levelInfo['avatar_title']; ?></div>
                    <div class="hero-level-display">
                        <span class="lvl-label">Lv.</span>
                        <span class="lvl-number"><?php echo $levelInfo['level']; ?></span>
                    </div>
                    <p class="total-exp-tag">累計EXP: <strong><?php echo h($user['exp']); ?></strong> pt</p>
                </div>
            </div>

            <!-- ステータスパラメータバッジ -->
            <div class="stats-mini-row">
                <div class="stat-badge">
                    <span class="stat-title">得意属性</span>
                    <span class="stat-val"><?php echo h($stats['top_category']); ?></span>
                </div>
                <div class="stat-badge">
                    <span class="stat-title">達成クエスト</span>
                    <span class="stat-val"><?php echo $stats['count']; ?> 件</span>
                </div>
                <div class="stat-badge">
                    <span class="stat-title">総鍛錬時間</span>
                    <span class="stat-val"><?php echo round($stats['total_minutes'] / 60, 1); ?> 時間</span>
                </div>
            </div>

            <!-- 経験値プログレスバー -->
            <div class="exp-bar-wrapper">
                <div class="exp-bar-header">
                    <span>NEXT LEVEL</span>
                    <span><strong><?php echo ($levelInfo['needed_exp'] - $levelInfo['exp_in_level']); ?> pt</strong> (<?php echo $levelInfo['exp_in_level']; ?>/<?php echo $levelInfo['needed_exp']; ?>)</span>
                </div>
                <div class="progress-container">
                    <div class="progress-bar" style="width: <?php echo $levelInfo['progress_percent']; ?>%;"></div>
                </div>
            </div>

            <!-- 🤖 マイキャラAI分析吹き出しカード -->
            <div class="ai-speech-box" id="aiSpeechBox">
                <p class="speech-text" id="aiSpeechText"><?php echo nl2br(h($aiAdvice)); ?></p>
            </div>
        </div>

        <!-- 📅 今月の達成カレンダー表示カード -->
        <div class="calendar-card">
            <div class="calendar-header">
                <h3 id="calendarMonthTitle">📅 今月の達成カレンダー</h3>
                <div class="calendar-nav-btns">
                    <button type="button" class="btn-cal-nav" onclick="changeCalendarMonth(-1)">◀ 前月</button>
                    <button type="button" class="btn-cal-nav btn-cal-today" onclick="resetCalendarToCurrent()">今月</button>
                    <button type="button" class="btn-cal-nav" onclick="changeCalendarMonth(1)">翌月 ▶</button>
                </div>
            </div>
            
            <div class="calendar-grid-wrapper">
                <div class="calendar-days-header">
                    <span class="day-sun">日</span>
                    <span>月</span>
                    <span>火</span>
                    <span>水</span>
                    <span>木</span>
                    <span>金</span>
                    <span class="day-sat">土</span>
                </div>
                <div class="calendar-grid" id="calendarGrid">
                    <!-- JavaScriptで動的生成 -->
                </div>
            </div>
        </div>

        <!-- アナリティクス＆グラフダッシュボード -->
        <div class="analytics-card">
            <div class="analytics-header">
                <h3>📊 作業実績 & 分析アナリティクス</h3>
                <div class="header-action-group">
                    <button type="button" class="btn-history-manage" onclick="openHistoryModal()">📜 履歴の管理</button>
                </div>
            </div>

            <!-- フィルタボタン群 -->
            <div class="filter-wrapper">
                <div class="filter-group">
                    <button type="button" class="btn-filter active" data-period="recent_week" onclick="setPeriod('recent_week')">直近7日間</button>
                    <button type="button" class="btn-filter" data-period="this_week" onclick="setPeriod('this_week')">今週</button>
                    <button type="button" class="btn-filter" data-period="recent_month" onclick="setPeriod('recent_month')">直近30日間</button>
                    <button type="button" class="btn-filter" data-period="this_month" onclick="setPeriod('this_month')">今月</button>
                    <button type="button" class="btn-filter" data-period="recent_year" onclick="setPeriod('recent_year')">直近12ヶ月</button>
                    <button type="button" class="btn-filter" data-period="this_year" onclick="setPeriod('this_year')">今年</button>
                    <button type="button" class="btn-filter" data-period="all" onclick="setPeriod('all')">全期間</button>
                </div>
            </div>

            <!-- サマリーカード -->
            <div class="summary-grid">
                <div class="summary-box">
                    <span class="summary-label">対象期間の総作業時間</span>
                    <span class="summary-value" id="summary-time">0分</span>
                </div>
                <div class="summary-box">
                    <span class="summary-label">完了タスク数</span>
                    <span class="summary-value" id="summary-count">0 件</span>
                </div>
            </div>

            <!-- グラフレイアウト -->
            <div class="charts-grid">
                <div class="chart-container">
                    <h4>🍰 カテゴリ別作業割合</h4>
                    <canvas id="categoryChart"></canvas>
                </div>

                <div class="chart-container">
                    <h4 id="timeChartTitle">📈 稼働時間の推移</h4>
                    <canvas id="timeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- タスク追加フォーム -->
        <div class="form-card">
            <h3>新しいタスクを追加</h3>
            <form method="POST" class="task-form">
                <div class="form-row">
                    <input type="text" name="title" placeholder="タスク名（例: 企業研究資料の作成）" required class="input-main">
                </div>
                
                <div class="form-row flex-row">
                    <div class="field-group">
                        <label>カテゴリ</label>
                        <input type="text" name="category" list="category-list" placeholder="例: 就活, 課題" class="input-sub">
                        <datalist id="category-list">
                            <?php foreach ($existingCategories as $cat): ?>
                                <option value="<?php echo h($cat); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="field-group">
                        <label>期日</label>
                        <input type="date" name="due_date" class="input-sub">
                    </div>

                    <div class="field-group">
                        <label>予定時間（分）※任意</label>
                        <input type="number" name="estimated_minutes" placeholder="例: 30" min="1" max="600" class="input-sub">
                    </div>
                </div>

                <button type="submit" name="add_task" class="btn-add">＋ タスクを追加する</button>
            </form>
        </div>

        <!-- 残りタスク一覧 -->
        <h2>残りのタスク (<?php echo count($tasks); ?>件)</h2>
        <ul class="task-list">
            <?php if (empty($tasks)): ?>
                <li class="empty-msg">現在タスクはありません</li>
            <?php else: ?>
                <?php foreach ($tasks as $task): 
                    $taskExp = calculateTaskExp($task['estimated_minutes']);
                    $gcalUrl = generateGoogleCalendarUrl($task['title'], $task['category'], $task['due_date'], $task['estimated_minutes']);
                ?>
                    <li class="task-item">
                        <div class="task-info">
                            <div class="task-header">
                                <span class="badge-category"><?php echo h($task['category'] ?: '一般'); ?></span>
                                <strong class="task-title"><?php echo h($task['title']); ?></strong>
                            </div>
                            <div class="task-meta">
                                <?php if (!empty($task['due_date'])): ?>
                                    <span class="meta-item">📅 期日: <?php echo h($task['due_date']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($task['estimated_minutes'])): ?>
                                    <span class="meta-item">⏱️ 予定時間: <?php echo h($task['estimated_minutes']); ?>分</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="task-actions">
                            <button type="button" class="btn-edit" 
                                data-id="<?php echo $task['id']; ?>"
                                data-title="<?php echo h($task['title']); ?>"
                                data-category="<?php echo h($task['category']); ?>"
                                data-duedate="<?php echo h($task['due_date']); ?>"
                                data-minutes="<?php echo h($task['estimated_minutes']); ?>"
                                onclick="openEditModal(this)">
                                ✏️ 編集
                            </button>

                            <a href="<?php echo $gcalUrl; ?>" target="_blank" rel="noopener noreferrer" class="btn-gcal" title="Googleカレンダーに追加">
                                📅 カレンダー
                            </a>

                            <button type="button" class="btn-complete"
                                data-id="<?php echo $task['id']; ?>"
                                data-title="<?php echo h($task['title']); ?>"
                                data-duedate="<?php echo h($task['due_date']); ?>"
                                data-minutes="<?php echo h($task['estimated_minutes'] ?: 30); ?>"
                                onclick="openCompleteModal(this)">
                                完了 (+<?php echo $taskExp; ?>EXP)
                            </button>

                            <form method="POST" class="inline-form" onsubmit="return confirm('本当にこのタスクを削除しますか？');">
                                <input type="hidden" name="delete_task_id" value="<?php echo $task['id']; ?>">
                                <button type="submit" class="btn-delete" title="タスクを削除">削除</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>

    <!-- 1. タスク完了モーダル -->
    <div id="completeModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🎉 タスク完了報告</h3>
                <span class="close-modal" onclick="closeCompleteModal()">&times;</span>
            </div>
            <form method="POST" class="task-form">
                <input type="hidden" name="complete_task_id" id="complete-task-id">
                <p id="complete-task-title" style="font-weight: bold; font-size: 1.1rem; color: #1e293b; margin-bottom: 6px;"></p>
                <p id="complete-bonus-notice" style="display:none; background:#ecfdf5; color:#047857; padding:8px 12px; border-radius:6px; font-weight:bold; font-size:0.85rem; margin-bottom:12px; border:1px solid #a7f3d0;"></p>
                
                <div class="form-row">
                    <label>実際にかかった時間（分）</label>
                    <input type="number" name="actual_minutes" id="complete-actual-minutes" min="1" max="600" required class="input-main">
                    <small style="color:#64748b; margin-top:4px;">※入力した時間（1分＝1EXP）に応じて基本経験値が入ります！</small>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeCompleteModal()">キャンセル</button>
                    <button type="submit" name="confirm_complete_task" class="btn-complete" style="padding:10px 18px;">獲得して完了！</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. タスク編集モーダル -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3>タスクの編集</h3>
                <span class="close-modal" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="POST" class="task-form">
                <input type="hidden" name="task_id" id="modal-task-id">
                
                <div class="form-row">
                    <label>タスク名</label>
                    <input type="text" name="title" id="modal-title" required class="input-main">
                </div>

                <div class="form-row flex-row">
                    <div class="field-group">
                        <label>カテゴリ</label>
                        <input type="text" name="category" id="modal-category" list="category-list" class="input-sub">
                    </div>

                    <div class="field-group">
                        <label>期日</label>
                        <input type="date" name="due_date" id="modal-duedate" class="input-sub">
                    </div>

                    <div class="field-group">
                        <label>予定時間（分）</label>
                        <input type="number" name="estimated_minutes" id="modal-minutes" min="1" max="600" class="input-sub">
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeEditModal()">キャンセル</button>
                    <button type="submit" name="update_task" class="btn-add" style="padding:10px 18px;">変更を保存</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 3. 完了ログ管理モーダル -->
    <div id="historyModal" class="modal-overlay">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h3>📜 完了タスク履歴の管理</h3>
                <span class="close-modal" onclick="closeHistoryModal()">&times;</span>
            </div>
            <div class="history-list-container">
                <?php if (empty($completedLogs)): ?>
                    <p style="text-align:center; color:#64748b; padding:20px;">まだ完了した記録はありません。</p>
                <?php else: ?>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>完了日時</th>
                                <th>カテゴリ</th>
                                <th>タスク名</th>
                                <th>作業時間</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completedLogs as $log): 
                                $time = $log['actual_minutes'] ?: $log['estimated_minutes'] ?: 0;
                            ?>
                                <tr>
                                    <td><?php echo h(date('Y/m/d H:i', strtotime($log['completed_at']))); ?></td>
                                    <td><span class="badge-category"><?php echo h($log['category'] ?: '一般'); ?></span></td>
                                    <td><strong><?php echo h($log['title']); ?></strong></td>
                                    <td><?php echo h($time); ?> 分</td>
                                    <td>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('この完了記録を削除しますか？（経験値も再計算されます）');">
                                            <input type="hidden" name="delete_completed_log_id" value="<?php echo $log['id']; ?>">
                                            <button type="submit" class="btn-delete-sm">削除</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="modal-footer-flex">
                <?php if (!empty($completedLogs)): ?>
                    <form method="POST" onsubmit="return confirm('⚠️ これまでのすべての完了履歴を削除してリセットしますか？\nこの操作は取り消せません。');">
                        <button type="submit" name="clear_all_completed_logs" class="btn-danger-all">⚠️ すべての完了履歴を全削除（リセット）</button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn-cancel" onclick="closeHistoryModal()">閉じる</button>
            </div>
        </div>
    </div>

    <script>
        // --- マイキャラのタップ（つつく）インタラクション ---
        function triggerAvatarAction() {
            const avatar = document.getElementById('avatarAvatar');
            const speechBox = document.getElementById('aiSpeechBox');

            avatar.classList.add('bounce-action');
            speechBox.classList.add('highlight-speech');

            setTimeout(() => {
                avatar.classList.remove('bounce-action');
                speechBox.classList.remove('highlight-speech');
            }, 600);
        }

        // --- 📅 月間カレンダー描画ロジック ---
        const rawLogs = <?php echo json_encode($completedLogs, JSON_UNESCAPED_UNICODE); ?>;
        let currentCalendarDate = new Date();

        function renderCalendar() {
            const year = currentCalendarDate.getFullYear();
            const month = currentCalendarDate.getMonth();

            document.getElementById('calendarMonthTitle').innerText = `📅 ${year}年${month + 1}月の達成カレンダー`;

            const firstDay = new Date(year, month, 1).getDay();
            const lastDate = new Date(year, month + 1, 0).getDate();

            const grid = document.getElementById('calendarGrid');
            grid.innerHTML = '';

            for (let i = 0; i < firstDay; i++) {
                const emptyCell = document.createElement('div');
                emptyCell.className = 'calendar-day-cell empty';
                grid.appendChild(emptyCell);
            }

            const todayStr = new Date().toISOString().split('T')[0];

            for (let day = 1; day <= lastDate; day++) {
                const dayCell = document.createElement('div');
                dayCell.className = 'calendar-day-cell';

                const mStr = String(month + 1).padStart(2, '0');
                const dStr = String(day).padStart(2, '0');
                const dateKey = `${year}-${mStr}-${dStr}`;

                if (dateKey === todayStr) {
                    dayCell.classList.add('today');
                }

                const dayNum = document.createElement('span');
                dayNum.className = 'day-number';
                dayNum.innerText = day;
                dayCell.appendChild(dayNum);

                const dayLogs = rawLogs.filter(log => log.completed_at.startsWith(dateKey));

                if (dayLogs.length > 0) {
                    const taskList = document.createElement('div');
                    taskList.className = 'cal-task-list';

                    dayLogs.forEach(log => {
                        const item = document.createElement('div');
                        item.className = 'cal-task-item';
                        const time = log.actual_minutes || log.estimated_minutes || 0;
                        item.title = `【${log.category || '一般'}】${log.title} (${time}分)`;
                        item.innerText = `✓ ${log.title}`;
                        taskList.appendChild(item);
                    });

                    dayCell.appendChild(taskList);
                }

                grid.appendChild(dayCell);
            }
        }

        function changeCalendarMonth(offset) {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() + offset);
            renderCalendar();
        }

        function resetCalendarToCurrent() {
            currentCalendarDate = new Date();
            renderCalendar();
        }

        renderCalendar();

        // --- モーダル制御 ---
        function openEditModal(button) {
            document.getElementById('modal-task-id').value = button.getAttribute('data-id');
            document.getElementById('modal-title').value = button.getAttribute('data-title');
            document.getElementById('modal-category').value = button.getAttribute('data-category');
            document.getElementById('modal-duedate').value = button.getAttribute('data-duedate');
            document.getElementById('modal-minutes').value = button.getAttribute('data-minutes');
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function openCompleteModal(button) {
            document.getElementById('complete-task-id').value = button.getAttribute('data-id');
            document.getElementById('complete-task-title').innerText = "「" + button.getAttribute('data-title') + "」";
            document.getElementById('complete-actual-minutes').value = button.getAttribute('data-minutes') || 30;

            const dueDateStr = button.getAttribute('data-duedate');
            const bonusNotice = document.getElementById('complete-bonus-notice');

            if (dueDateStr) {
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const due = new Date(dueDateStr);
                due.setHours(0, 0, 0, 0);

                const diffTime = due - today;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

                if (diffDays > 0) {
                    const bonusPt = diffDays * 10;
                    bonusNotice.innerText = `⏰ 期日より ${diffDays} 日早い達成です！ (+${bonusPt} pt 早割りボーナス獲得！)`;
                    bonusNotice.style.display = 'block';
                } else {
                    bonusNotice.style.display = 'none';
                }
            } else {
                bonusNotice.style.display = 'none';
            }

            document.getElementById('completeModal').classList.add('active');
        }

        function closeCompleteModal() {
            document.getElementById('completeModal').classList.remove('active');
        }

        function openHistoryModal() {
            document.getElementById('historyModal').classList.add('active');
        }

        function closeHistoryModal() {
            document.getElementById('historyModal').classList.remove('active');
        }

        window.onclick = function(event) {
            if (event.target === document.getElementById('editModal')) closeEditModal();
            if (event.target === document.getElementById('completeModal')) closeCompleteModal();
            if (event.target === document.getElementById('historyModal')) closeHistoryModal();
        }

        // --- Chart.js 分析ロジック ---
        let categoryChartInstance = null;
        let timeChartInstance = null;
        let currentPeriod = 'recent_week';

        function setPeriod(period) {
            currentPeriod = period;
            document.querySelectorAll('.btn-filter').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-period') === period);
            });
            renderAnalytics();
        }

        function renderAnalytics() {
            const now = new Date();
            let filteredLogs = [];

            if (currentPeriod === 'recent_week') {
                const limitDate = new Date();
                limitDate.setDate(now.getDate() - 6);
                limitDate.setHours(0, 0, 0, 0);
                filteredLogs = rawLogs.filter(log => new Date(log.completed_at) >= limitDate);
            } else if (currentPeriod === 'this_week') {
                const dayOfWeek = now.getDay() || 7;
                const mondayDate = new Date(now);
                mondayDate.setDate(now.getDate() - (dayOfWeek - 1));
                mondayDate.setHours(0, 0, 0, 0);
                filteredLogs = rawLogs.filter(log => new Date(log.completed_at) >= mondayDate);
            } else if (currentPeriod === 'recent_month') {
                const limitDate = new Date();
                limitDate.setDate(now.getDate() - 29);
                limitDate.setHours(0, 0, 0, 0);
                filteredLogs = rawLogs.filter(log => new Date(log.completed_at) >= limitDate);
            } else if (currentPeriod === 'this_month') {
                const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1, 0, 0, 0);
                filteredLogs = rawLogs.filter(log => new Date(log.completed_at) >= startOfMonth);
            } else if (currentPeriod === 'recent_year') {
                const limitDate = new Date();
                limitDate.setFullYear(now.getFullYear() - 1);
                filteredLogs = rawLogs.filter(log => new Date(log.completed_at) >= limitDate);
            } else if (currentPeriod === 'this_year') {
                const startOfYear = new Date(now.getFullYear(), 0, 1, 0, 0, 0);
                filteredLogs = rawLogs.filter(log => new Date(log.completed_at) >= startOfYear);
            } else {
                filteredLogs = rawLogs;
            }

            let totalMinutes = 0;
            filteredLogs.forEach(log => {
                totalMinutes += parseInt(log.actual_minutes || log.estimated_minutes || 0);
            });

            const hours = Math.floor(totalMinutes / 60);
            const mins = totalMinutes % 60;
            document.getElementById('summary-time').innerText = hours > 0 ? `${hours}時間 ${mins}分` : `${mins}分`;
            document.getElementById('summary-count').innerText = `${filteredLogs.length} 件`;

            const catMap = {};
            filteredLogs.forEach(log => {
                const cat = log.category || '一般';
                const time = parseInt(log.actual_minutes || log.estimated_minutes || 0);
                catMap[cat] = (catMap[cat] || 0) + time;
            });

            const catLabels = Object.keys(catMap);
            const catData = Object.values(catMap);

            if (categoryChartInstance) categoryChartInstance.destroy();
            const ctxCat = document.getElementById('categoryChart').getContext('2d');
            
            categoryChartInstance = new Chart(ctxCat, {
                type: 'doughnut',
                data: {
                    labels: catLabels.length ? catLabels : ['データなし'],
                    datasets: [{
                        data: catData.length ? catData : [1],
                        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#64748b']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return ` ${context.label}: ${context.raw} 分`;
                                }
                            }
                        }
                    }
                }
            });

            let timeLabels = [];
            let timeValues = [];
            let chartType = 'bar';

            if (currentPeriod === 'recent_week' || currentPeriod === 'this_week') {
                document.getElementById('timeChartTitle').innerText = currentPeriod === 'recent_week' ? '📊 直近7日間の日別作業時間' : '📊 今週の日別作業時間';
                chartType = 'bar';
                const daysCount = currentPeriod === 'recent_week' ? 7 : (now.getDay() || 7);
                for (let i = daysCount - 1; i >= 0; i--) {
                    const d = new Date();
                    d.setDate(now.getDate() - i);
                    const dateStr = d.toISOString().split('T')[0];
                    const label = `${d.getMonth() + 1}/${d.getDate()}`;
                    timeLabels.push(label);
                    
                    const dayTotal = filteredLogs
                        .filter(l => l.completed_at.startsWith(dateStr))
                        .reduce((sum, l) => sum + parseInt(l.actual_minutes || l.estimated_minutes || 0), 0);
                    timeValues.push(dayTotal);
                }
            } else if (currentPeriod === 'recent_month' || currentPeriod === 'this_month') {
                document.getElementById('timeChartTitle').innerText = currentPeriod === 'recent_month' ? '📊 直近30日間の作業時間' : '📊 今月の作業時間推移';
                chartType = 'bar';
                const daysCount = currentPeriod === 'recent_month' ? 30 : now.getDate();
                for (let i = daysCount - 1; i >= 0; i--) {
                    const d = new Date();
                    d.setDate(now.getDate() - i);
                    const dateStr = d.toISOString().split('T')[0];
                    const label = `${d.getMonth() + 1}/${d.getDate()}`;
                    timeLabels.push(label);
                    
                    const dayTotal = filteredLogs
                        .filter(l => l.completed_at.startsWith(dateStr))
                        .reduce((sum, l) => sum + parseInt(l.actual_minutes || l.estimated_minutes || 0), 0);
                    timeValues.push(dayTotal);
                }
            } else if (currentPeriod === 'recent_year' || currentPeriod === 'this_year') {
                document.getElementById('timeChartTitle').innerText = currentPeriod === 'recent_year' ? '📈 直近12ヶ月の月別作業時間' : '📈 今年の月別作業時間';
                chartType = 'line';
                const monthsCount = currentPeriod === 'recent_year' ? 12 : (now.getMonth() + 1);
                for (let i = monthsCount - 1; i >= 0; i--) {
                    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
                    const monthKey = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
                    const label = `${d.getMonth() + 1}月`;
                    timeLabels.push(label);

                    const monthTotal = filteredLogs
                        .filter(l => l.completed_at.startsWith(monthKey))
                        .reduce((sum, l) => sum + parseInt(l.actual_minutes || l.estimated_minutes || 0), 0);
                    timeValues.push(monthTotal);
                }
            } else {
                document.getElementById('timeChartTitle').innerText = '📈 全期間の累計月別推移';
                chartType = 'line';
                const monthMap = {};
                filteredLogs.forEach(l => {
                    const m = l.completed_at.substring(0, 7);
                    monthMap[m] = (monthMap[m] || 0) + parseInt(l.actual_minutes || l.estimated_minutes || 0);
                });
                timeLabels = Object.keys(monthMap);
                timeValues = Object.values(monthMap);
                if (!timeLabels.length) {
                    timeLabels = ['今月'];
                    timeValues = [0];
                }
            }

            if (timeChartInstance) timeChartInstance.destroy();
            const ctxTime = document.getElementById('timeChart').getContext('2d');

            timeChartInstance = new Chart(ctxTime, {
                type: chartType,
                data: {
                    labels: timeLabels,
                    datasets: [{
                        label: '作業時間 (分)',
                        data: timeValues,
                        backgroundColor: 'rgba(59, 130, 246, 0.6)',
                        borderColor: '#3b82f6',
                        borderWidth: 2,
                        fill: chartType === 'line'
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: '分' } }
                    }
                }
            });
        }

        renderAnalytics();
    </script>
</body>
</html>