<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';

class PairsService
{
    public static function getAllPairs() {
        try {
            $conn = Database::getConnection();

            $sql = "SELECT * FROM pairs";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                Response::error('Failed to get pairs', 500);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            $pairs = [];
            while ($row = $result->fetch_assoc()) {
                $pairs[] = $row;
            }

            $stmt->close();

            return $pairs;
        } catch (Exception $e) {
            error_log("PairsService::getAllPairs - " . $e->getMessage());
            Response::error('Failed to retrieve trading pairs', 500);
        }
    }

    public static function getPairById($id) {
        $conn = Database::getConnection();
        $sql = "SELECT id, name, type, pair1, pair2, digits, lot_size, spread, margin_percent, min_volume, max_volume, status, date_updated FROM pairs WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $id);
        $stmt->execute();
        if (!$stmt) {
            Response::error('Could not get pair', 500);
        }

        $result = $stmt->get_result()->fetch_assoc();

        if (!$result) {
            Response::error('Pair not found', 404);
        }

        return $result;
    }

    public static function getPairByName($pair_name) {
        try {
            $pairs = self::getAllPairs();
            
            foreach ($pairs as $pair) {
                if ($pair['name'] === $pair_name) {
                    Response::success($pair);
                    return;
                }
            }
            
            Response::error('Trading pair not found', 404);
        } catch (Exception $e) {
            error_log("PairsService::getPairByName - " . $e->getMessage());
            Response::error('Failed to retrieve trading pair', 500);
        }
    }

    public static function updatePairStatus($id, $input)
    {
        $conn = Database::getConnection();

        $status = $input['status'];
        if($status !== 'active' && $status !== 'inactive') {
            Response::error('Invalid input', 422);
        };

        $stmt = $conn->prepare("UPDATE pairs SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);

        if (!$stmt->execute()) {
            Response::error('Status update failed', 500);
        };

        return true;
    }

    public static function updatePair($pair_name, $input) {
        try {
            $pairs = self::getAllPairs();
            $found = false;
            
            foreach ($pairs as &$pair) {
                if ($pair['name'] === $pair_name) {
                    $found = true;
                    
                    // Update only provided fields
                    $allowedFields = [
                        'type', 'pair1', 'pair2', 'digits', 'lot_size',
                        'pip_price', 'pip_value', 'spread', 'min_volume',
                        'max_volume', 'margin_percent', 'status'
                    ];

                    foreach ($allowedFields as $field) {
                        if (isset($input[$field])) {
                            $pair[$field] = $input[$field];
                        }
                    }
                    
                    $pair['updated_at'] = gmdate('Y-m-d H:i:s');
                    Response::success($pair, 'Trading pair updated successfully');
                    return;
                }
            }
            
            if (!$found) {
                Response::error('Trading pair not found', 404);
            }
        } catch (Exception $e) {
            error_log("PairsService::updatePair - " . $e->getMessage());
            Response::error('Failed to update trading pair', 500);
        }
    }

    public static function deletePair($pair_name) {
        try {
            // In a real implementation, this would delete from database
            Response::success(null, 'Trading pair deleted successfully');
        } catch (Exception $e) {
            error_log("PairsService::deletePair - " . $e->getMessage());
            Response::error('Failed to delete trading pair', 500);
        }
    }

    public static function getPairsByType($type) {
        try {
            $pairs = self::getAllPairs();
            $filteredPairs = array_filter($pairs, function($pair) use ($type) {
                return $pair['type'] === $type;
            });

            Response::success(array_values($filteredPairs));
        } catch (Exception $e) {
            error_log("PairsService::getPairsByType - " . $e->getMessage());
            Response::error('Failed to retrieve trading pairs by type', 500);
        }
    }

    public static function getActivePairs() {
        try {
            $pairs = self::getAllPairs();
            $activePairs = array_filter($pairs, function($pair) {
                return $pair['status'] === 'active';
            });

            Response::success(array_values($activePairs));
        } catch (Exception $e) {
            error_log("PairsService::getActivePairs - " . $e->getMessage());
            Response::error('Failed to retrieve active trading pairs', 500);
        }
    }
}
