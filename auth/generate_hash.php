<?php
// ── ONE-OFF HELPER — generates a bcrypt hash for a new admin password ──
// Run this once on your server (e.g. http://localhost/generate_hash.php),
// copy the output hash into the SQL query below, then DELETE this file.

$password = 'admin123'; // ← change this to your real password

echo password_hash($password, PASSWORD_DEFAULT);