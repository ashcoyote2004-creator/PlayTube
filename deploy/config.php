<?php
// Environment-driven replacement for the installer-generated config.php
// (that file is gitignored). Copied to /app/config.php by Dockerfile.kuberns.service.

$sql_db_host = getenv('DB_HOST') ?: 'localhost';
$sql_db_port = (int) (getenv('DB_PORT') ?: 3306);
$sql_db_user = getenv('DB_USERNAME') ?: 'root';
$sql_db_pass = getenv('DB_PASSWORD') ?: '';
$sql_db_name = getenv('DB_DATABASE') ?: 'playtube';

// Public URL of the site (no trailing slash).
$site_url = rtrim(getenv('SITE_URL') ?: 'http://localhost', '/');

$purchase_code = getenv('PURCHASE_CODE') ?: '';
$auto_redirect = true;

// First boot: the database has no PlayTube schema yet, so import playtube.sql
// (the same job the web installer does) and create the admin account.
$__ptLink = @new mysqli($sql_db_host, $sql_db_user, $sql_db_pass, $sql_db_name, $sql_db_port);
if (!$__ptLink->connect_errno) {
    $__ptHas = $__ptLink->query("SHOW TABLES LIKE 'config'");
    if ($__ptHas && $__ptHas->num_rows === 0) {
        $__ptSql = file_get_contents(__DIR__ . '/playtube.sql');
        if ($__ptSql !== false && $__ptLink->multi_query($__ptSql)) {
            do {
                if ($res = $__ptLink->store_result()) { $res->free(); }
            } while ($__ptLink->more_results() && $__ptLink->next_result());
        }
        $__ptLink->query("UPDATE `config` SET `value` = '1' WHERE `name` = 'is_ok'");
        $__ptAdminPass = getenv('ADMIN_PASSWORD');
        $__ptAdminUser = getenv('ADMIN_USERNAME') ?: 'admin';
        if ($__ptAdminPass) {
            $__ptU = $__ptLink->real_escape_string($__ptAdminUser);
            $__ptP = $__ptLink->real_escape_string(sha1($__ptAdminPass));
            $__ptE = $__ptLink->real_escape_string(getenv('MAIL_FROM_ADDRESS') ?: 'admin@example.com');
            $__ptLink->query("INSERT INTO `users` (`username`,`password`,`email`,`admin`,`active`,`registered`) VALUES ('$__ptU','$__ptP','$__ptE','1','1','00/0000')");
        }
    }
    $__ptLink->close();
}
