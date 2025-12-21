<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/response.php';

class ProfileService
{
    public static function getUserProfile($user_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT email, fname, lname, ref_code, country, phone, account_currency, dob_place, tax_country, tax_id, us_citizen, promotional_email, trading_experience, trading_duration, trading_instrument, trading_frequency, trading_objective, trading_risk, employment_status, annual_income, income_source, net_worth, invest_amount, debt, pep, pep_relationship, pep_role, dob, gender, doc_identity_type, doc_identity, doc_identity_id, doc_identity_country, street, city, state, postal, doc_address, doc_address_type, doc_address_date, employer_name, business_name, doc_income, doc_income_type, current_account FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }

            $user = $result->fetch_assoc();
            
            // Convert promotional_email to boolean
            $user['promotional_email'] = strtolower($user['promotional_email']) === 'true';

            return $user;
        } catch (Exception $e) {
            error_log("ProfileService::getUserProfile - " . $e->getMessage());
            Response::error('Failed to retrieve user profile', 500);
        }
    }

    public static function updateUserProfile($user_id, $input) {
        try {
            $conn = Database::getConnection();

            $filled_group = $input['filled_group'];
            $input[$filled_group] = 'true';
            
            // Build dynamic update query based on provided fields
            $fields = [];
            $values = [];
            $types = '';

            $allowedFields = [
                'email', 'fname', 'lname', 'ref_code', 'country', 'phone', 'dob_place',
                'tax_country', 'tax_id', 'us_citizen', 'promotional_email', 'trading_experience',
                'trading_duration', 'trading_instrument', 'trading_frequency', 'trading_objective',
                'trading_risk', 'employment_status', 'annual_income', 'income_source', 'net_worth',
                'invest_amount', 'debt', 'pep', 'pep_relationship', 'pep_role', 'dob', 'gender',
                'doc_identity_type', 'doc_identity_id', 'doc_identity_country',
                'street', 'city', 'state', 'postal', 'doc_address_type',
                'doc_address_date', 'employer_name', 'business_name', 'doc_income_type', 'account_currency',
                'personal_details_isFilled', 'trading_assessment_isFilled', 'financial_assessment_isFilled',
                'identity_verification_isFilled', 'income_verification_isFilled', 'address_verification_isFilled',
            ];

            $booleanFields = ['promotional_email', 'us_citizen'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $fields[] = "$field = ?";
                    if (in_array($field, $booleanFields, true)) {
                        $values[] = $input[$field] === true ? 'true' : 'false';
                    } else {
                        $values[] = $input[$field];
                    }
                    $types .= 's';
                }
            }

            if (empty($fields)) {
                Response::error('No fields to update', 400);
                return;
            }

            $values[] = $user_id;
            $types .= 'i';

            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$values);
            
            if ($stmt->execute()) {
                // Fetch updated user profile
                return true;
            } else {
                Response::error('Failed to update profile', 500);
            }
        } catch (Exception $e) {
            error_log("ProfileService::updateUserProfile - " . $e->getMessage());
            Response::error('Failed to update user profile', 500);
        }
    }

    public static function uploadDocument($user_id, $input) {
        try {

            $filetype = $input['filetype'];
            $fileData = $input['file'];

            // Validate file type parameter
            $field_mapping = [
                'doc_identity' => 'doc_identity',
                'doc_address' => 'doc_address', 
                'doc_income' => 'doc_income'
            ];

            if (!isset($field_mapping[$filetype])) {
                Response::error('Invalid file type', 400);
                return;
            }

            // Extract MIME type and base64 data
            if (!preg_match('/^data:([^;]+);base64,(.+)$/', $fileData, $matches)) {
                Response::error('Invalid base64 file format', 400);
                return;
            }

            $mimeType = $matches[1];
            $base64Data = $matches[2];

            // Validate MIME type
            $allowed_mime_types = [
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'application/pdf' => 'pdf'
            ];

            if (!isset($allowed_mime_types[$mimeType])) {
                Response::error('Invalid file format. Only JPG, JPEG, PNG, PDF allowed', 400);
                return;
            }

            $extension = $allowed_mime_types[$mimeType];

            // Decode base64 data
            $fileContent = base64_decode($base64Data);
            if ($fileContent === false) {
                Response::error('Invalid base64 data', 400);
                return;
            }

            // Check file size (5MB max)
            $max_size = 5 * 1024 * 1024; // 5MB in bytes
            if (strlen($fileContent) > $max_size) {
                Response::error('File too large. Maximum size is 5MB', 400);
                return;
            }

            // Generate unique filename
            $filename = time() . '_' . $user_id;
            $upload_dir = __DIR__ . '/../uploads/';
            
            // Create uploads directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_path = $upload_dir . $filename . '.' . $extension;
            $relative_path = 'uploads/' . $filename . '.' . $extension;

            // Save file
            if (!file_put_contents($file_path, $fileContent)) {
                Response::error('Failed to save uploaded file', 500);
                return;
            }

            // Update database
            $conn = Database::getConnection();
            $field = $field_mapping[$filetype];
            $stmt = $conn->prepare("UPDATE users SET $field = ? WHERE id = ?");
            $stmt->bind_param("si", $relative_path, $user_id);
            
            if ($stmt->execute()) {
                Response::success('Upload successful');
            } else {
                // Clean up uploaded file if database update fails
                unlink($file_path);
                Response::error('Failed to update database', 500);
            }

        } catch (Exception $e) {
            error_log("ProfileService::uploadDocument - " . $e->getMessage());
            Response::error('Failed to upload document', 500);
        }
    }

    public static function getAllUserProfiles() {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id, email, fname, lname, country, phone, trading_experience, employment_status, current_account, created_at FROM users WHERE role = 'user' ORDER BY created_at DESC");
            $stmt->execute();
            $result = $stmt->get_result();

            $profiles = [];
            while ($row = $result->fetch_assoc()) {
                $profiles[] = $row;
            }

            Response::success($profiles);
        } catch (Exception $e) {
            error_log("ProfileService::getAllUserProfiles - " . $e->getMessage());
            Response::error('Failed to retrieve user profiles', 500);
        }
    }

    public static function searchUsersByName($name) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT id, email, fname, lname, country, phone FROM users WHERE (fname LIKE ? OR mname LIKE ? OR lname LIKE ? OR email LIKE ?) AND role = 'user' ORDER BY fname ASC");
            $search_term = "%{$name}%";
            $stmt->bind_param("ssss", $search_term, $search_term, $search_term, $search_term);
            $stmt->execute();
            $result = $stmt->get_result();

            $profiles = [];
            while ($row = $result->fetch_assoc()) {
                $profiles[] = $row;
            }

            Response::success($profiles);
        } catch (Exception $e) {
            error_log("ProfileService::searchUsersByName - " . $e->getMessage());
            Response::error('Failed to search user profiles', 500);
        }
    }

    public static function updateKYCStatus($user_id, $input) {
        try {
            $conn = Database::getConnection();
            
            $kyc_status = $input['kyc_status'];
            $verification_notes = $input['verification_notes'] ?? '';

            $stmt = $conn->prepare("UPDATE users SET acc_type = ?, verification_notes = ? WHERE id = ?");
            $stmt->bind_param("ssi", $kyc_status, $verification_notes, $user_id);
            
            if ($stmt->execute()) {
                Response::success(null, 'KYC status updated successfully');
            } else {
                Response::error('Failed to update KYC status', 500);
            }
        } catch (Exception $e) {
            error_log("ProfileService::updateKYCStatus - " . $e->getMessage());
            Response::error('Failed to update KYC status', 500);
        }
    }

    public static function getKYCCompletionStatus($user_id) {
        try {
            $conn = Database::getConnection();
            $stmt = $conn->prepare("SELECT fname, lname, dob, country, phone, doc_identity, doc_address, doc_income, acc_type FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                Response::error('User not found', 404);
                return;
            }

            $user = $result->fetch_assoc();
            
            $required_fields = ['fname', 'lname', 'dob', 'country', 'phone'];
            $required_docs = ['doc_identity', 'doc_address', 'doc_income'];
            
            $completion = [
                'personal_info_complete' => true,
                'documents_complete' => true,
                'kyc_status' => $user['acc_type'],
                'missing_fields' => [],
                'missing_documents' => []
            ];

            // Check required fields
            foreach ($required_fields as $field) {
                if (empty($user[$field])) {
                    $completion['personal_info_complete'] = false;
                    $completion['missing_fields'][] = $field;
                }
            }

            // Check required documents
            foreach ($required_docs as $doc) {
                if (empty($user[$doc])) {
                    $completion['documents_complete'] = false;
                    $completion['missing_documents'][] = $doc;
                }
            }

            $completion['overall_complete'] = $completion['personal_info_complete'] && $completion['documents_complete'];

            Response::success($completion);
        } catch (Exception $e) {
            error_log("ProfileService::getKYCCompletionStatus - " . $e->getMessage());
            Response::error('Failed to get KYC completion status', 500);
        }
    }
}
