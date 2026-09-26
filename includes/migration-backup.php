<?php
/**
 * SADA One — full database dump taken before a one-time data migration rewrites rows.
 * Written to backups/ (denied to the web by .htaccess); returns the file name.
 */
function migration_db_backup(PDO $pdo, string $label, string $reason): string {
    $dir = dirname(__DIR__) . '/backups';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!file_exists("$dir/.htaccess")) file_put_contents("$dir/.htaccess", "Require all denied\n");
    $name = 'db-before-' . $label . '-' . date('Ymd-His') . '.sql';
    $fh = fopen("$dir/$name", 'wb');
    if (!$fh) throw new RuntimeException('Veritabanı yedeği yazılamadı: backups/ klasörü yazılabilir değil.');
    @set_time_limit(600);
    fwrite($fh, "-- SADA One database backup before the $reason, " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "\nDROP TABLE IF EXISTS `$table`;\n$create;\n");
        $st = $pdo->query("SELECT * FROM `$table`");
        $batch = [];
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
            if (count($batch) === 200) { fwrite($fh, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; }
        }
        if ($batch) fwrite($fh, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return $name;
}
