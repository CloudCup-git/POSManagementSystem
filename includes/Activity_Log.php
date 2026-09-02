<?php
/** Shared audit trail for business-changing actions. */
function ensure_activity_log(mysqli $conn): void {
    static $ready = false;
    if ($ready) return;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS activity_log (
        activity_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_id INT NULL,
        actor_name VARCHAR(150) NOT NULL DEFAULT '',
        domain ENUM('employee','inventory','money','system') NOT NULL,
        action VARCHAR(80) NOT NULL,
        target_type VARCHAR(80) NOT NULL,
        target_id VARCHAR(80) NULL,
        details TEXT NULL,
        audience VARCHAR(80) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_activity_audience_created (audience, created_at),
        INDEX idx_activity_domain_created (domain, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}
function activity_audience(string $domain): string {
    return match ($domain) {
        'employee' => 'hr,admin', 'inventory' => 'store_manager',
        'money' => 'finance', default => 'admin',
    };
}
function log_activity(?mysqli $conn, string $domain, string $action, string $target_type, $target_id = null, string $details = '', ?int $actor_id = null, ?string $actor_name = null): void {
    if (!$conn) return;
    ensure_activity_log($conn);
    $actor_id ??= (int) ($_SESSION['user_id'] ?? $_SESSION['employee_id'] ?? 0);
    $actor_name ??= (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System');
    $target_id = $target_id === null ? null : (string) $target_id;
    $audience = activity_audience($domain);
    $stmt = mysqli_prepare($conn, 'INSERT INTO activity_log (actor_id, actor_name, domain, action, target_type, target_id, details, audience) VALUES (?,?,?,?,?,?,?,?)');
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'isssssss', $actor_id, $actor_name, $domain, $action, $target_type, $target_id, $details, $audience);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
