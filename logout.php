<?php
require __DIR__ . '/includes/init.php';
@session_start(); // reopen: init releases the lock early
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
