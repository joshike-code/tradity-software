<?php
require_once __DIR__ . '/core/response.php';
require_once __DIR__ . '/vendor/autoload.php';

// Manually load Phinx since it's not in composer.json
spl_autoload_register(function ($class) {
    if (strpos($class, 'Phinx\\') === 0) {
        $relativeClass = substr($class, 6); // Remove 'Phinx\' prefix
        $file = __DIR__ . '/vendor/robmorgan/phinx-0.x/src/Phinx/' . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require $file;
            return true;
        }
    }
    return false;
});

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

// Load config
$configArray = include __DIR__ . '/phinx.php';

// Create config object
$config = new Config($configArray);

// Fake input and output for CLI simulation
$input = new ArrayInput([]);
$output = new StreamOutput(fopen('php://output', 'w'));

// Create migration manager
$manager = new Manager($config, $input, $output);

try {
    $manager->migrate('development');

    // Return array if included from another script
    // This happens when UpdateService includes this file
    if (basename(__FILE__) !== basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
        return [
            'status' => 'success',
            'message' => 'Migrations ran successfully.'
        ];
    }

    // If run directly (CLI or browser)
    echo "Migrations ran successfully.";
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    error_log("Migration error: " . $errorMessage);
    
    // Return array if included from another script
    if (basename(__FILE__) !== basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
        return [
            'status' => 'error',
            'error' => $errorMessage
        ];
    }

    // If run directly (CLI or browser)
    echo "Migration failed: " . $errorMessage;
    http_response_code(500);
}