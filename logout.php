<?php
require __DIR__ . '/includes/init.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
