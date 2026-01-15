<?php
use Core\SanitizationService;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../controllers/ServerController.php';

//THIS ROUTE IS FOR THE TESTING DEMO OF THIS SOFTWARE ONLY AND SHOULD BE COMMENTED OUT IN COMMERCIAL VERSION AS IT IS A SECURITY RISK
//KEEP IT COMMENTED OUT! OR DELETE THE CONTENT BELOW!

// switch ($_SERVER['REQUEST_METHOD']) {
//     case 'GET':
//         ServerController::getServerStatus();
//         break;

//     case 'POST':        
//         $action = $_GET['action'] ?? null;
        
//         if (!$action) {
//             Response::error('Action parameter required (start or stop)', 400);
//             exit;
//         }
        
//         $action = SanitizationService::sanitizeParam($action);
        
//         if ($action === 'start') {
//             ServerController::startServer();
//         } elseif ($action === 'stop') {
//             ServerController::stopServer();
//         } elseif ($action === 'restart') {
//             ServerController::stopServer();
//             sleep(2);
//             ServerController::startServer();
//         } else {
//             Response::error('Invalid action. Use: start, stop, or restart', 400);
//         }
//         break;

//     default:
//         http_response_code(405);
//         echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
//         break;
// }