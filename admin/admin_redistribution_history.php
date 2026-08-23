<?php
require_once '../includes/auth_check.php';
requireRole('admin');

header('Location: admin_redistribution.php?history_open=1');
exit;
