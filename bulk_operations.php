<?php
/**
 * Bulk Operations Handler for Employee Management System
 * Handles bulk status updates, deletions, and other mass operations
 */

require_once 'config.php';
requireLogin();
requireAdmin();

// Set JSON response header
header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // Check if request is AJAX
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
        throw new Exception('Invalid request method');
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON data');
    }

    // Verify CSRF token
    if (!isset($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
        throw new Exception('Invalid CSRF token');
    }

    $action = $input['action'] ?? '';
    $employee_ids = $input['employee_ids'] ?? [];

    // Validate input
    if (empty($action)) {
        throw new Exception('No action specified');
    }

    if (empty($employee_ids) || !is_array($employee_ids)) {
        throw new Exception('No employees selected');
    }

    // Sanitize employee IDs
    $employee_ids = array_map('intval', $employee_ids);
    $employee_ids = array_filter($employee_ids, function($id) { return $id > 0; });

    if (empty($employee_ids)) {
        throw new Exception('Invalid employee IDs');
    }

    // Start transaction
    $pdo->beginTransaction();

    $affected_count = 0;
    $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';

    switch ($action) {
        case 'activate':
            $stmt = $pdo->prepare("
                UPDATE employee_profiles 
                SET status = 'active', dismissal_reason = NULL 
                WHERE user_id IN ($placeholders)
            ");
            $stmt->execute($employee_ids);
            $affected_count = $stmt->rowCount();
            
            // Log audit trail
            foreach ($employee_ids as $user_id) {
                logAudit($user_id, 'BULK_ACTIVATE', 'employee_profiles', $user_id, 
                    ['status' => 'inactive'], ['status' => 'active'], $pdo);
            }
            break;

        case 'deactivate':
            $stmt = $pdo->prepare("
                UPDATE employee_profiles 
                SET status = 'inactive' 
                WHERE user_id IN ($placeholders)
            ");
            $stmt->execute($employee_ids);
            $affected_count = $stmt->rowCount();
            
            // Log audit trail
            foreach ($employee_ids as $user_id) {
                logAudit($user_id, 'BULK_DEACTIVATE', 'employee_profiles', $user_id, 
                    ['status' => 'active'], ['status' => 'inactive'], $pdo);
            }
            break;

        case 'dismiss':
            $dismissal_reason = $input['dismissal_reason'] ?? 'Bulk dismissal';
            $stmt = $pdo->prepare("
                UPDATE employee_profiles 
                SET status = 'dismissed', dismissal_reason = ? 
                WHERE user_id IN ($placeholders)
            ");
            $params = array_merge([$dismissal_reason], $employee_ids);
            $stmt->execute($params);
            $affected_count = $stmt->rowCount();
            
            // Log audit trail
            foreach ($employee_ids as $user_id) {
                logAudit($user_id, 'BULK_DISMISS', 'employee_profiles', $user_id, 
                    ['status' => 'active'], ['status' => 'dismissed', 'dismissal_reason' => $dismissal_reason], $pdo);
            }
            break;

        case 'delete':
            // First, get employee names for logging
            $stmt = $pdo->prepare("
                SELECT ep.user_id, CONCAT(ep.first_name, ' ', ep.last_name) as name 
                FROM employee_profiles ep 
                WHERE ep.user_id IN ($placeholders)
            ");
            $stmt->execute($employee_ids);
            $employees_to_delete = $stmt->fetchAll();

            // Delete users (cascade will handle related records)
            $stmt = $pdo->prepare("
                DELETE FROM users 
                WHERE id IN ($placeholders) AND role != 'admin'
            ");
            $stmt->execute($employee_ids);
            $affected_count = $stmt->rowCount();
            
            // Log audit trail
            foreach ($employees_to_delete as $employee) {
                logAudit($_SESSION['user_id'], 'BULK_DELETE', 'users', $employee['user_id'], 
                    ['employee_name' => $employee['name']], null, $pdo);
            }
            break;

        case 'export':
            // Generate CSV export for selected employees
            $stmt = $pdo->prepare("
                SELECT 
                    ep.employee_id,
                    CONCAT(ep.first_name, ' ', ep.last_name) as full_name,
                    u.email,
                    ep.phone,
                    ep.department,
                    ep.position,
                    ep.status,
                    ep.salary,
                    ep.hire_date,
                    ep.date_of_birth,
                    ep.ncin,
                    CONCAT(ep.cnss_first, ep.cnss_last) as cnss,
                    ep.address
                FROM employee_profiles ep
                JOIN users u ON ep.user_id = u.id
                WHERE ep.user_id IN ($placeholders)
                ORDER BY ep.first_name, ep.last_name
            ");
            $stmt->execute($employee_ids);
            $employees = $stmt->fetchAll();
            
            $csv_data = "Employee ID,Full Name,Email,Phone,Department,Position,Status,Salary,Hire Date,Date of Birth,NCIN,CNSS,Address\n";
            
            foreach ($employees as $emp) {
                $csv_data .= sprintf(
                    '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"' . "\n",
                    $emp['employee_id'] ?? '',
                    $emp['full_name'] ?? '',
                    $emp['email'] ?? '',
                    $emp['phone'] ?? '',
                    $emp['department'] ?? '',
                    $emp['position'] ?? '',
                    $emp['status'] ?? '',
                    $emp['salary'] ?? '',
                    $emp['hire_date'] ?? '',
                    $emp['date_of_birth'] ?? '',
                    $emp['ncin'] ?? '',
                    $emp['cnss'] ?? '',
                    $emp['address'] ?? ''
                );
            }
            
            $pdo->commit();
            
            // Return CSV data
            $response['success'] = true;
            $response['message'] = 'Export completed successfully';
            $response['csv_data'] = $csv_data;
            $response['filename'] = 'employees_export_' . date('Y-m-d_H-i-s') . '.csv';
            echo json_encode($response);
            exit;

        case 'update_department':
            $new_department = $input['new_department'] ?? '';
            if (empty($new_department)) {
                throw new Exception('New department is required');
            }
            
            $stmt = $pdo->prepare("
                UPDATE employee_profiles 
                SET department = ? 
                WHERE user_id IN ($placeholders)
            ");
            $params = array_merge([$new_department], $employee_ids);
            $stmt->execute($params);
            $affected_count = $stmt->rowCount();
            
            // Log audit trail
            foreach ($employee_ids as $user_id) {
                logAudit($user_id, 'BULK_UPDATE_DEPARTMENT', 'employee_profiles', $user_id, 
                    null, ['department' => $new_department], $pdo);
            }
            break;

        default:
            throw new Exception('Invalid action specified');
    }

    // Commit transaction
    $pdo->commit();

    // Create notifications for affected employees (except delete)
    if ($action !== 'delete' && $affected_count > 0) {
        $notification_messages = [
            'activate' => 'Your account has been activated',
            'deactivate' => 'Your account has been deactivated',
            'dismiss' => 'Your employment status has been updated',
            'update_department' => 'Your department has been updated'
        ];

        $message = $notification_messages[$action] ?? 'Your profile has been updated';
        
        foreach ($employee_ids as $user_id) {
            createNotification($pdo, $user_id, 'profile_update', 'Profile Updated', $message);
        }
    }

    // Send success response
    $response['success'] = true;
    $response['message'] = "Bulk operation completed successfully. {$affected_count} employee(s) affected.";
    $response['affected_count'] = $affected_count;

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    
    // Log error
    error_log("Bulk operation error: " . $e->getMessage());
}

echo json_encode($response);

/**
 * Enhanced audit logging function
 */
function logAudit($user_id, $action, $table_name, $record_id, $old_values = null, $new_values = null, $pdo) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user_id,
            $action,
            $table_name,
            $record_id,
            $old_values ? json_encode($old_values) : null,
            $new_values ? json_encode($new_values) : null,
            $ip_address,
            $user_agent
        ]);
    } catch (Exception $e) {
        // Don't throw error for audit logging failure
        error_log("Audit logging failed: " . $e->getMessage());
    }
}
?>
