<?php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_core.php';

standout_effettua_logout();

header('Location: ../index.php');
exit;
