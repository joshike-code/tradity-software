<?php
// This script replaces Traditi default data with user's correct data (resolving an issue caused by previous update that reverted user's changes on homepage)
$keys = require __DIR__ . '/../config/keys.php';

// Get user's platform data
$platformName = $keys['platform']['name'];
$mainLogo = $keys['platform']['main_logo'];


// Install in landing index
$mainIndexPath = realpath(__DIR__ . '/../../../index.html');
if (file_exists($mainIndexPath)) {
    $indexContent = file_get_contents($mainIndexPath);
    $indexContent = str_replace('Traditi', $platformName, $indexContent);
    $indexContent = str_replace('app/backend/logos/default/main_logo_traditi.png', "app/backend/$mainLogo", $indexContent);
    file_put_contents($mainIndexPath, $indexContent);
}
?>