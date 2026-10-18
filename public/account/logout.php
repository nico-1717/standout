<?php
require_once 'config.php';
require_once 'auth_core.php';

nisama_effettua_logout();

header('Location: index.php');
exit;
