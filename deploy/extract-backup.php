<?php

declare(strict_types=1);

/*
 * Extracts an encrypted backup zip (milestone 12, docs/DEPLOY.md "Restore").
 * The archives are AES-256 encrypted, which the stock `unzip` on shared
 * hosting can't open; PHP's ZipArchive can.
 *
 *   BACKUP_ARCHIVE_PASSWORD=... php deploy/extract-backup.php <backup.zip> <target-dir>
 *
 * The password is read from the environment so it never lands in the shell
 * history or the process list.
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: BACKUP_ARCHIVE_PASSWORD=... php deploy/extract-backup.php <backup.zip> <target-dir>\n");
    exit(2);
}

[, $zipPath, $target] = $argv;
$password = getenv('BACKUP_ARCHIVE_PASSWORD');

if ($password === false || $password === '') {
    fwrite(STDERR, "BACKUP_ARCHIVE_PASSWORD is not set.\n");
    exit(2);
}

if (! is_dir($target) && ! mkdir($target, 0700, true)) {
    fwrite(STDERR, "Cannot create {$target}.\n");
    exit(1);
}

$zip = new ZipArchive;

if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "Cannot open {$zipPath}.\n");
    exit(1);
}

$zip->setPassword($password);

if (! $zip->extractTo($target)) {
    fwrite(STDERR, 'Extraction failed (wrong password?): '.$zip->getStatusString()."\n");
    exit(1);
}

echo "Extracted {$zip->numFiles} entries to {$target}\n";

for ($i = 0; $i < $zip->numFiles; $i++) {
    echo '  '.$zip->getNameIndex($i)."\n";
}

$zip->close();
