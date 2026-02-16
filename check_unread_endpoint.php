<?php
require_once('wp-load.php');

echo "--- CHECKING ENDPOINT PRESENCE ---\n";

if (function_exists('sk_get_unread_count_endpoint')) {
    echo "SUCCESS: sk_get_unread_count_endpoint exists.\n";
} else {
    echo "FAILURE: sk_get_unread_count_endpoint NOT found.\n";
}

echo "\n--- CHECKING REST ROUTES ---\n";
$wp_rest_server = rest_get_server();
$routes = $wp_rest_server->get_routes();

if (isset($routes['/sk/v1/unread-count'])) {
    echo "SUCCESS: /sk/v1/unread-count route is registered.\n";
} else {
    echo "FAILURE: /sk/v1/unread-count route is NOT registered.\n";
    // List some sk/v1 routes to see what IS there
    echo "Available sk/v1 routes:\n";
    foreach ($routes as $route => $data) {
        if (strpos($route, '/sk/v1') === 0) {
            echo " - $route\n";
        }
    }
}
