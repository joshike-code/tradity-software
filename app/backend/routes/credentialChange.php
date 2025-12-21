<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../controllers/CredentialController.php';
require_once __DIR__ . '/../core/response.php';

// This route handles public credential change verification (no auth required)
// Used when user clicks the link in their email

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // Verify credential change token (public access)
        if (isset($_GET['action']) && $_GET['action'] === 'verify') {
            CredentialController::verifyCredentialToken();
        } else {
            Response::error('Action parameter not recognized', 400);
        }
        break;

    case 'POST':
        // Update credentials after token verification (public access)
        if (isset($_GET['action']) && $_GET['action'] === 'update') {
            CredentialController::updateCredentials();
        } else {
            Response::error('Action parameter not recognized', 400);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}
