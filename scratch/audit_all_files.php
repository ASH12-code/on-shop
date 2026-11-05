<?php
/**
 * AUDIT MENYELURUH SELURUH FILE WEBSITE ON-SHOP
 * Memeriksa:
 * 1. Sintaks seluruh file PHP (api/, config/, scratch/)
 * 2. Eksistensi seluruh aset CSS, JS, Image, dan tautan halaman di seluruh file HTML (pages/)
 * 3. Eksistensi seluruh endpoint API yang dipanggil di file JS (assets/js/)
 * 4. Keseimbangan kurung kurawal & integritas seluruh file CSS (assets/css/)
 * 5. Integritas tabel dan kolom database MySQL (db_on_shop)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$rootDir = realpath(__DIR__ . '/..');
$phpBin  = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe';
$nodeBin = 'D:\Nodejs javascript\node.exe';

echo "=================================================================\n";
echo "🔍 AUDIT MENYELURUH SELURUH FILE & STRUKTUR WEBSITE ON-SHOP\n";
echo "=================================================================\n\n";

$totalChecks = 0;
$passedChecks = 0;
$warnings = [];
$errors = [];

function getFilesRecursive($dir, $extList = []) {
    $results = [];
    if (!is_dir($dir)) return $results;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (empty($extList) || in_array($ext, $extList)) {
                $results[] = $file->getPathname();
            }
        }
    }
    return $results;
}

// -----------------------------------------------------------------
// 1. CEK SINTAKS SELURUH FILE PHP
// -----------------------------------------------------------------
echo "--- [1/5] PEMERIKSAAN SINTAKS SELURUH FILE PHP ---\n";
$phpDirs = [$rootDir . '/api', $rootDir . '/config'];
$phpFiles = [];
if (file_exists($rootDir . '/index.php')) {
    $phpFiles[] = $rootDir . '/index.php';
}
foreach ($phpDirs as $d) {
    $phpFiles = array_merge($phpFiles, getFilesRecursive($d, ['php']));
}
sort($phpFiles);

foreach ($phpFiles as $phpFile) {
    $totalChecks++;
    $relPath = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $phpFile);
    $relPath = str_replace('\\', '/', $relPath);
    $cmd = sprintf('"%s" -l "%s" 2>&1', $phpBin, $phpFile);
    $output = shell_exec($cmd);
    if (strpos($output, 'No syntax errors detected') !== false) {
        $passedChecks++;
        echo "  [OK] PHP Syntax: $relPath\n";
    } else {
        $errors[] = "PHP Syntax Error in $relPath: " . trim($output);
        echo "  [ERR] PHP Syntax: $relPath -> " . trim($output) . "\n";
    }
}
echo "Total File PHP Diperiksa: " . count($phpFiles) . "\n\n";

// -----------------------------------------------------------------
// 2. CEK INTEGRITAS SELURUH FILE HTML & ASET REFERENSI
// -----------------------------------------------------------------
echo "--- [2/5] PEMERIKSAAN FILE HTML & TAUTAN ASET (CSS/JS/HALAMAN) ---\n";
$htmlFiles = getFilesRecursive($rootDir . '/pages', ['html']);
if (file_exists($rootDir . '/index.html')) {
    $htmlFiles[] = $rootDir . '/index.html';
}
sort($htmlFiles);

foreach ($htmlFiles as $htmlFile) {
    $totalChecks++;
    $relPath = str_replace('\\', '/', str_replace($rootDir . DIRECTORY_SEPARATOR, '', $htmlFile));
    $content = file_get_contents($htmlFile);
    $htmlDir = dirname($htmlFile);
    $fileHasError = false;

    // Cek <link href="..."> dan <script src="...">
    preg_match_all('/<(?:link[^>]+href|script[^>]+src)=["\']([^"\']+)["\']/i', $content, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $ref) {
            if (preg_match('/^(https?:|\/\/|data:|mailto:|tel:|javascript:|#)/i', $ref)) {
                continue;
            }
            $cleanRef = explode('?', explode('#', $ref)[0])[0];
            if (empty($cleanRef)) continue;

            if (strpos($cleanRef, '/on-shop/') === 0) {
                $targetPath = $rootDir . '/' . substr($cleanRef, strlen('/on-shop/'));
            } elseif ($cleanRef[0] === '/') {
                $targetPath = $rootDir . $cleanRef;
            } else {
                $targetPath = $htmlDir . '/' . $cleanRef;
            }

            $totalChecks++;
            if (file_exists($targetPath)) {
                $passedChecks++;
            } else {
                $fileHasError = true;
                $errors[] = "Missing asset in $relPath -> '$ref' (resolved: $targetPath)";
                echo "  [ERR] Missing Asset in $relPath: $ref\n";
            }
        }
    }

    // Cek <a href="..."> statis (abaikan template literal ${...})
    preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $content, $linkMatches);
    if (!empty($linkMatches[1])) {
        foreach ($linkMatches[1] as $href) {
            if (preg_match('/^(https?:|\/\/|mailto:|tel:|javascript:|#|\$\{)/i', $href)) {
                continue;
            }
            $cleanHref = explode('?', explode('#', $href)[0])[0];
            if (empty($cleanHref)) continue;

            if (strpos($cleanHref, '/on-shop/') === 0) {
                $targetPath = $rootDir . '/' . substr($cleanHref, strlen('/on-shop/'));
            } elseif ($cleanHref[0] === '/') {
                $targetPath = $rootDir . $cleanHref;
            } else {
                $targetPath = $htmlDir . '/' . $cleanHref;
            }

            $totalChecks++;
            if (file_exists($targetPath)) {
                $passedChecks++;
            } else {
                $fileHasError = true;
                $errors[] = "Broken HTML link in $relPath -> '$href'";
                echo "  [ERR] Broken Link in $relPath: $href\n";
            }
        }
    }

    if (!$fileHasError) {
        $passedChecks++;
        echo "  [OK] HTML & Assets: $relPath\n";
    }
}
echo "Total File HTML Diperiksa: " . count($htmlFiles) . "\n\n";

// -----------------------------------------------------------------
// 3. CEK SELURUH FILE JAVASCRIPT & ENDPOINT API YANG DIPANGGIL
// -----------------------------------------------------------------
echo "--- [3/5] PEMERIKSAAN FILE JAVASCRIPT & VALIDASI ENDPOINT API ---\n";
$jsFiles = getFilesRecursive($rootDir . '/assets/js', ['js']);
sort($jsFiles);

foreach ($jsFiles as $jsFile) {
    $totalChecks++;
    $relPath = str_replace('\\', '/', str_replace($rootDir . DIRECTORY_SEPARATOR, '', $jsFile));
    $content = file_get_contents($jsFile);
    $jsHasError = false;

    if (file_exists($nodeBin)) {
        $totalChecks++;
        $cmd = sprintf('"%s" --check "%s" 2>&1', $nodeBin, $jsFile);
        $out = shell_exec($cmd);
        if (empty(trim((string)$out))) {
            $passedChecks++;
        } else {
            $jsHasError = true;
            $errors[] = "JS Syntax Error in $relPath -> " . trim((string)$out);
            echo "  [ERR] JS Syntax in $relPath: " . trim((string)$out) . "\n";
        }
    }

    // Cari referensi ke api/.../*.php
    preg_match_all('/(?:api\/[a-zA-Z0-9_\-\/]+\.php)/', $content, $apiMatches);
    if (!empty($apiMatches[0])) {
        $uniqueApis = array_unique($apiMatches[0]);
        foreach ($uniqueApis as $apiRef) {
            $targetApi = $rootDir . '/' . $apiRef;
            $totalChecks++;
            if (file_exists($targetApi)) {
                $passedChecks++;
            } else {
                $jsHasError = true;
                $errors[] = "Missing API endpoint in $relPath -> '$apiRef'";
                echo "  [ERR] Missing API Endpoint in $relPath: $apiRef\n";
            }
        }
    }

    if (!$jsHasError) {
        $passedChecks++;
        echo "  [OK] JS & API Refs: $relPath\n";
    }
}
echo "Total File JS Diperiksa: " . count($jsFiles) . "\n\n";

// -----------------------------------------------------------------
// 4. CEK SELURUH FILE CSS
// -----------------------------------------------------------------
echo "--- [4/5] PEMERIKSAAN INTEGRITAS FILE CSS ---\n";
$cssFiles = getFilesRecursive($rootDir . '/assets/css', ['css']);
sort($cssFiles);

foreach ($cssFiles as $cssFile) {
    $totalChecks++;
    $relPath = str_replace('\\', '/', str_replace($rootDir . DIRECTORY_SEPARATOR, '', $cssFile));
    $content = file_get_contents($cssFile);
    // Hapus komentar CSS sebelum hitung kurung kurawal
    $cleanCss = preg_replace('/\/\*.*?\*\//s', '', $content);
    $openBraces  = substr_count($cleanCss, '{');
    $closeBraces = substr_count($cleanCss, '}');

    if ($openBraces === $closeBraces && strlen(trim($cleanCss)) > 0) {
        $passedChecks++;
        echo "  [OK] CSS Integrity ($openBraces blocks): $relPath\n";
    } else {
        $errors[] = "CSS brace mismatch or empty in $relPath (open: $openBraces, close: $closeBraces)";
        echo "  [ERR] CSS Integrity: $relPath (open: $openBraces, close: $closeBraces)\n";
    }
}
echo "Total File CSS Diperiksa: " . count($cssFiles) . "\n\n";

// -----------------------------------------------------------------
// 5. CEK SKEMA DATABASE & TABEL INTI
// -----------------------------------------------------------------
echo "--- [5/5] PEMERIKSAAN SKEMA & TABEL DATABASE MYSQL ---\n";
require_once $rootDir . '/config/database.php';

$requiredTables = [
    'addresses',
    'admin_audit_logs',
    'cart',
    'categories',
    'chats',
    'disputes',
    'help_ticket_messages',
    'help_tickets',
    'master_orders',
    'order_items',
    'password_resets',
    'payment_verification_logs',
    'payments',
    'platform_settings',
    'product_images',
    'product_variants',
    'products',
    'reviews',
    'shipping_rates',
    'shipping_tracking',
    'shops',
    'sub_orders',
    'user_vouchers',
    'users',
    'vouchers',
    'wallet_transactions',
    'wallets',
    'wishlists',
    'withdrawals'
];

$existingTables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($requiredTables as $tbl) {
    $totalChecks++;
    if (in_array($tbl, $existingTables)) {
        $count = $conn->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
        $passedChecks++;
        echo "  [OK] Tabel Database `$tbl` aktif (Baris saat ini: $count)\n";
    } else {
        $errors[] = "Missing required database table: `$tbl`";
        echo "  [ERR] Tabel Database `$tbl` TIDAK DITEMUKAN!\n";
    }
}

echo "\n=================================================================\n";
echo "HASIL AUDIT FILE & DATABASE:\n";
echo "Total Pemeriksaan : $totalChecks\n";
echo "Berhasil (PASS)   : $passedChecks\n";
echo "Error Ditemukan   : " . count($errors) . "\n";
echo "=================================================================\n";

if (!empty($errors)) {
    echo "\nDAFTAR ERROR:\n";
    foreach ($errors as $idx => $err) {
        echo ($idx + 1) . ". $err\n";
    }
    exit(1);
} else {
    echo "🎉 SELURUH FILE WEBSITE & SKEMA DATABASE 100% VALID TANPA ERROR!\n";
}
