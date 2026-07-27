<?php
// Render環境で設定した環境変数（MYSQLHOSTなど）を使う設定です
define('DB_HOST', getenv('MYSQLHOST') ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'todo_db');
define('DB_PORT', getenv('MYSQLPORT') ?: '3306');
?>