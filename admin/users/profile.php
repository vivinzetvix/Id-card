<?php
/**
 * Users Management Module - User Profile Alias
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/functions.php';

$currentUser = require_user_module_access($pdo);

$id = (int)($_GET['id'] ?? ($currentUser['id'] ?? 0));

if ($id <= 0) {
    header('Location: index.php');
    exit();
}

header("Location: view.php?id={$id}");
exit();
