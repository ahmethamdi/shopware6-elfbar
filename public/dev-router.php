<?php
// php -S için router: gerçek dosya varsa onu servis et, yoksa Shopware index.php'ye düş.
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // PHP built-in server dosyayı doğrudan servis etsin
}
require __DIR__ . '/index.php';
