<?php
// NBC Central managed bridge
$GLOBALS['nbc_application_key'] = 'rationalization';
header('Cache-Control: no-store');
header('Location: ' . '/rationalization-system/login.php', true, 303);
exit;
