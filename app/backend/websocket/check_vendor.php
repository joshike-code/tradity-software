<?php
/**
 * Check Vendor Folder and Dependencies
 * 
 * Diagnose why Ratchet library isn't being detected
 * Access: https://yourdomain.com/app/backend/websocket/check_vendor.php
 */

header('Content-Type: text/html; charset=UTF-8');

$backendDir = dirname(__DIR__);
$vendorDir = $backendDir . '/vendor';
$autoloadFile = $vendorDir . '/autoload.php';
$ratchetDir = $vendorDir . '/cboden/ratchet';
$composerJson = $backendDir . '/composer.json';
$composerLock = $backendDir . '/composer.lock';

$checks = [];

// Check 1: Vendor directory exists
$checks[] = [
    'name' => 'Vendor Directory',
    'path' => $vendorDir,
    'exists' => is_dir($vendorDir),
    'readable' => is_dir($vendorDir) && is_readable($vendorDir),
    'writable' => is_dir($vendorDir) && is_writable($vendorDir),
    'permissions' => is_dir($vendorDir) ? substr(sprintf('%o', fileperms($vendorDir)), -4) : 'N/A'
];

// Check 2: Autoload file
$checks[] = [
    'name' => 'Autoload File',
    'path' => $autoloadFile,
    'exists' => file_exists($autoloadFile),
    'readable' => file_exists($autoloadFile) && is_readable($autoloadFile),
    'writable' => file_exists($autoloadFile) && is_writable($autoloadFile),
    'permissions' => file_exists($autoloadFile) ? substr(sprintf('%o', fileperms($autoloadFile)), -4) : 'N/A'
];

// Check 3: Ratchet directory
$checks[] = [
    'name' => 'Ratchet Directory',
    'path' => $ratchetDir,
    'exists' => is_dir($ratchetDir),
    'readable' => is_dir($ratchetDir) && is_readable($ratchetDir),
    'writable' => is_dir($ratchetDir) && is_writable($ratchetDir),
    'permissions' => is_dir($ratchetDir) ? substr(sprintf('%o', fileperms($ratchetDir)), -4) : 'N/A'
];

// Check 4: composer.json
$checks[] = [
    'name' => 'composer.json',
    'path' => $composerJson,
    'exists' => file_exists($composerJson),
    'readable' => file_exists($composerJson) && is_readable($composerJson),
    'writable' => file_exists($composerJson) && is_writable($composerJson),
    'permissions' => file_exists($composerJson) ? substr(sprintf('%o', fileperms($composerJson)), -4) : 'N/A'
];

// Check 5: composer.lock
$checks[] = [
    'name' => 'composer.lock',
    'path' => $composerLock,
    'exists' => file_exists($composerLock),
    'readable' => file_exists($composerLock) && is_readable($composerLock),
    'writable' => file_exists($composerLock) && is_writable($composerLock),
    'permissions' => file_exists($composerLock) ? substr(sprintf('%o', fileperms($composerLock)), -4) : 'N/A'
];

// Try to require autoload
$autoloadWorking = false;
$autoloadError = '';
if (file_exists($autoloadFile)) {
    try {
        require_once $autoloadFile;
        $autoloadWorking = true;
    } catch (Exception $e) {
        $autoloadError = $e->getMessage();
    }
}

// Check if Ratchet classes are available
$ratchetClasses = [
    'Ratchet\Server\IoServer',
    'Ratchet\Http\HttpServer',
    'Ratchet\WebSocket\WsServer',
    'Ratchet\MessageComponentInterface'
];

$classesFound = [];
foreach ($ratchetClasses as $class) {
    $classesFound[$class] = class_exists($class);
}

// Count vendor packages
$vendorPackages = 0;
if (is_dir($vendorDir)) {
    $vendors = array_diff(scandir($vendorDir), ['.', '..']);
    foreach ($vendors as $vendor) {
        $vendorPath = $vendorDir . '/' . $vendor;
        if (is_dir($vendorPath) && $vendor !== 'composer' && $vendor !== 'bin') {
            $packages = array_diff(scandir($vendorPath), ['.', '..']);
            $vendorPackages += count($packages);
        }
    }
}

// Check server.php requirements
$serverFile = __DIR__ . '/server.php';
$serverFileExists = file_exists($serverFile);
$serverFileReadable = $serverFileExists && is_readable($serverFile);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Dependencies Check</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        .content { padding: 30px; }
        .section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .section h2 {
            color: #667eea;
            font-size: 18px;
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 6px;
            overflow: hidden;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f3f4f6;
            font-weight: 600;
            color: #374151;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .status.success { background: #d1fae5; color: #065f46; }
        .status.error { background: #fee2e2; color: #991b1b; }
        .status.warning { background: #fef3c7; color: #92400e; }
        .path {
            font-family: monospace;
            font-size: 12px;
            color: #6b7280;
            word-break: break-all;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #667eea;
        }
        .summary-card h3 {
            font-size: 32px;
            color: #667eea;
            margin-bottom: 5px;
        }
        .summary-card p {
            color: #6b7280;
            font-size: 14px;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .alert.success { background: #d1fae5; color: #065f46; }
        .alert.error { background: #fee2e2; color: #991b1b; }
        .alert.warning { background: #fef3c7; color: #92400e; }
        .code {
            background: #1f2937;
            color: #f3f4f6;
            padding: 15px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 10px 0;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin: 5px;
        }
        .btn:hover { background: #5568d3; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📦 Vendor Dependencies Check</h1>
            <p>Diagnostic report for composer dependencies</p>
        </div>
        
        <div class="content">
            <div class="summary">
                <div class="summary-card">
                    <h3><?= $vendorPackages ?></h3>
                    <p>Packages Found</p>
                </div>
                <div class="summary-card">
                    <h3><?= $autoloadWorking ? '✅' : '❌' ?></h3>
                    <p>Autoload Status</p>
                </div>
                <div class="summary-card">
                    <h3><?= count(array_filter($classesFound)) ?>/<?= count($classesFound) ?></h3>
                    <p>Ratchet Classes</p>
                </div>
            </div>
            
            <?php if (!$autoloadWorking): ?>
                <div class="alert error">
                    <strong>❌ Autoload Failed!</strong><br>
                    <?= $autoloadError ? htmlspecialchars($autoloadError) : 'Could not load vendor/autoload.php' ?>
                </div>
            <?php else: ?>
                <div class="alert success">
                    <strong>✅ Autoload Working!</strong><br>
                    Composer autoloader is functioning correctly.
                </div>
            <?php endif; ?>
            
            <div class="section">
                <h2>File System Checks</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Exists</th>
                            <th>Readable</th>
                            <th>Permissions</th>
                            <th>Path</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($checks as $check): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($check['name']) ?></strong></td>
                                <td>
                                    <span class="status <?= $check['exists'] ? 'success' : 'error' ?>">
                                        <?= $check['exists'] ? '✅ Yes' : '❌ No' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status <?= $check['readable'] ? 'success' : 'error' ?>">
                                        <?= $check['readable'] ? '✅ Yes' : '❌ No' ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($check['permissions']) ?></td>
                                <td class="path"><?= htmlspecialchars($check['path']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="section">
                <h2>Ratchet Class Availability</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Class Name</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classesFound as $class => $found): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($class) ?></code></td>
                                <td>
                                    <span class="status <?= $found ? 'success' : 'error' ?>">
                                        <?= $found ? '✅ Found' : '❌ Not Found' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="section">
                <h2>Server File Check</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Status</th>
                            <th>Path</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>server.php exists</td>
                            <td>
                                <span class="status <?= $serverFileExists ? 'success' : 'error' ?>">
                                    <?= $serverFileExists ? '✅ Yes' : '❌ No' ?>
                                </span>
                            </td>
                            <td class="path"><?= htmlspecialchars($serverFile) ?></td>
                        </tr>
                        <tr>
                            <td>server.php readable</td>
                            <td>
                                <span class="status <?= $serverFileReadable ? 'success' : 'error' ?>">
                                    <?= $serverFileReadable ? '✅ Yes' : '❌ No' ?>
                                </span>
                            </td>
                            <td class="path">-</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <?php if (!$autoloadWorking || count(array_filter($classesFound)) === 0): ?>
                <div class="section">
                    <h2>🔧 Recommended Actions</h2>
                    
                    <?php if (!file_exists($autoloadFile)): ?>
                        <div class="alert error">
                            <strong>vendor/autoload.php is missing!</strong><br>
                            You need to run composer install:
                        </div>
                        <div class="code">cd <?= dirname($vendorDir) ?><br>composer install --no-dev --optimize-autoloader</div>
                    <?php elseif (!is_readable($autoloadFile)): ?>
                        <div class="alert error">
                            <strong>Permission issue!</strong><br>
                            Fix permissions:
                        </div>
                        <div class="code">chmod -R 755 <?= $vendorDir ?></div>
                    <?php else: ?>
                        <div class="alert warning">
                            <strong>Packages might be incomplete!</strong><br>
                            Try reinstalling:
                        </div>
                        <div class="code">cd <?= dirname($vendorDir) ?><br>rm -rf vendor composer.lock<br>composer install --no-dev --optimize-autoloader</div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="alert success">
                    <strong>✅ All checks passed!</strong><br>
                    Your vendor dependencies are properly installed and accessible.
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 20px;">
                <a href="test_binance.php" class="btn">🔄 Re-run Binance Test</a>
                <a href="view_logs.php" class="btn">📊 View Server Logs</a>
                <a href="setup.php" class="btn">🔧 Back to Setup</a>
            </div>
        </div>
    </div>
</body>
</html>
