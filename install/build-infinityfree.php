<?php
/**
 * Builds an upload-ready copy of the system for InfinityFree (or similar free PHP hosting).
 *
 *   php install/build-infinityfree.php
 *
 * Output: deploy/infinityfree/htdocs/  (upload its contents into the hosting's htdocs folder)
 *         deploy/tabulation-infinityfree.zip
 * Differences from the local copy: no local .env, no uploaded files, an .htaccess without php_value
 * (not allowed on free hosting), and a .env template with free-hosting values to fill in.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$out = $root . '/deploy/infinityfree/htdocs';
$zipPath = $root . '/deploy/tabulation-infinityfree.zip';

$skip = [
    '#^\.env$#', '#^\.user\.ini$#', '#^\.htaccess$#', '#^\.git(/|$)#', '#^\.gitignore$#',
    '#^deploy(/|$)#', '#^install/build-infinityfree\.php$#',
    '#^storage/(uploads|tmp|logs)/.+#',
];

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

rrmdir($root . '/deploy/infinityfree');
@unlink($zipPath);
mkdir($out, 0775, true);

$count = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    foreach ($skip as $pattern) {
        if (preg_match($pattern, $rel)) {
            continue 2;
        }
    }
    $target = $out . '/' . $rel;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }
    copy($file->getPathname(), $target);
    $count++;
}

// empty storage folders the app writes into
foreach (['storage/uploads', 'storage/tmp', 'storage/logs'] as $dir) {
    @mkdir($out . '/' . $dir, 0775, true);
    file_put_contents($out . '/' . $dir . '/.gitkeep', '');
}
file_put_contents($out . '/storage/.htaccess', "Require all denied\n");

// .htaccess without php_value (free hosting returns "500 Internal Server Error" for it)
file_put_contents($out . '/.htaccess', <<<'HT'
Options -Indexes
DirectoryIndex index.html

# Never serve environment files, internal code, storage or SQL dumps
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
<FilesMatch "\.(sql|md|log|example)$">
    Require all denied
</FilesMatch>
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(app|config|views|storage|database|deploy)(/|$) - [F,L]
</IfModule>

# Pages always revalidate so updates show without a hard refresh
<IfModule mod_headers.c>
    <FilesMatch "\.html$">
        Header set Cache-Control "no-cache, must-revalidate"
    </FilesMatch>
</IfModule>

HT);

// settings for free hosting: fill in the MySQL details from the InfinityFree control panel
file_put_contents($out . '/.env', <<<'ENV'
# ------------------------------------------------------------------
# PHINMA COC Tabulation: settings for InfinityFree
# Fill in the 4 database lines from: Control Panel > MySQL Databases
# ------------------------------------------------------------------

APP_NAME="PHINMA COC Tabulation"
APP_SCHOOL="PHINMA Cagayan de Oro College"
APP_TIMEZONE=Asia/Manila
APP_DEBUG=false
# Free hosting counts every request: pages check for changes every 15 seconds
LIVE_UPDATE_SECONDS=15

# ---------------- Database (from InfinityFree > MySQL Databases)
DB_HOST=sqlXXX.infinityfree.com
DB_PORT=3306
DB_NAME=if0_XXXXXXXX_tabulation
DB_USER=if0_XXXXXXXX
DB_PASS=your-vpanel-password

# ---------------- Uploads (InfinityFree allows files up to 10 MB)
STORAGE_DRIVER=local
STORAGE_LOCAL_PATH=storage/uploads
UPLOAD_MAX_MB=10

# ---------------- Document scanning
# No Tesseract on free hosting. PDF and Word files are read without it.
TESSERACT_PATH=
OCR_SPACE_API_KEY=helloworld

MAX_LOGIN_ATTEMPTS=10

ENV);

// zip for uploading through the online file manager
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$all = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));
foreach ($all as $file) {
    $zip->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen($out) + 1)));
}
$zip->close();

echo "Copied $count files to deploy/infinityfree/htdocs\n";
echo 'Zip: deploy/tabulation-infinityfree.zip (' . round(filesize($zipPath) / 1048576, 1) . " MB)\n";
