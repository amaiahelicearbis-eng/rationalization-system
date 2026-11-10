<?php
declare(strict_types=1);
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ModelData.php' . ($query !== '' ? '?' . $query : ''));
exit;
