<?php
declare(strict_types=1);

function db(): mysqli
{
    static $db;
    if (!$db) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $db->set_charset('utf8mb4');
    }
    return $db;
}
