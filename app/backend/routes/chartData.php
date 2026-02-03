<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../controllers/ChartDataController.php';

$user = AuthMiddleware::handle(['user']);
$user_id = $user->user_id;

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (!isset($_GET['pair'])) {
            Response::error('Pair parameter is required', 400);
        }
        
        $pair = $_GET['pair'];
        $interval = $_GET['interval'] ?? '5m';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
        ChartDataController::getHistoricalCandles($pair, $interval, $limit, $user_id);
        break;

    case 'POST':
        // Batch request for multiple pairs
        ChartDataController::getBatchHistoricalCandles($user_id);
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}