<?php

use Core\SanitizationService;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once __DIR__ . '/../services/UpdateService.php';
require_once __DIR__ . '/../core/response.php';
require_once __DIR__ . '/../core/SanitizationService.php';
require_once __DIR__ . '/../middleware/Validator.php';

class UpdateController {

    public static function getUpdateStatus() {
       $status = UpdateService::getUpdateStatus();
       Response::success($status);
    }

    public static function getLatestUpdate() {
        $result = UpdateService::getLatestUpdate();
        Response::success($result);
    }

    public static function getAllChangelogs() {
        $result = UpdateService::getAllChangelogs();
        Response::success($result);
    }

    public static function getCurrentChangelog() {
        $changelogFile = __DIR__ . '/../changelog.txt';
        
        if(file_exists($changelogFile)) {
            $fileContent = file_get_contents($changelogFile);
            Response::success($fileContent);
        } else {
            Response::error('Changelog not found', 404);
        }
    }

    public static function removeCurrentChangelog() {
        $changelogFile = __DIR__ . '/../changelog.txt';
        
        if (file_exists($changelogFile)) {
            @unlink($changelogFile);
        }
        Response::success("Changelog removed successfully");
    }

    public static function applyUpdate() {
        $rawInput = json_decode(file_get_contents("php://input"), true);
        $input = SanitizationService::sanitize($rawInput);

        UpdateService::applyUpdate([]);
    }
}

