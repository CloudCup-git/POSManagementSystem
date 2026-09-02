<?php
// Mapbox public tokens are intentionally exposed to the browser by Mapbox GL JS.
// Set MAPBOX_PUBLIC_TOKEN via environment variable, or in includes/Mapbox_Config.local.php (gitignored, not committed).
// Restrict this token to your production domain in the Mapbox account dashboard.
$mapboxLocalConfig = __DIR__ . '/Mapbox_Config.local.php';
if (is_file($mapboxLocalConfig)) {
    require $mapboxLocalConfig;
} elseif (!defined('MAPBOX_PUBLIC_TOKEN')) {
    define('MAPBOX_PUBLIC_TOKEN', getenv('MAPBOX_PUBLIC_TOKEN') ?: '');
}
