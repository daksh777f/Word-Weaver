<?php
session_start();
\['test_user'] = 'chirag';
echo 'Session ID: ' . session_id() . PHP_EOL;
echo 'Session User: ' . (\['test_user'] ?? 'NOT SET') . PHP_EOL;
echo 'Save path: ' . ini_get('session.save_path') . PHP_EOL;
?>
