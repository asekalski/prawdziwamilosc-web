<?php
require_once('wp-load.php');

echo "--- VERIFYING BADGE FIX (COLUMN DETECTION) ---\n";

global $wpdb;
$potential_tables = $wpdb->get_col("SHOW TABLES LIKE '%message%recipient%'");

foreach ($potential_tables as $table) {
    echo "\nTable: $table\n";
    
    // Test the logic added to functions_2.php
    $has_unread_count = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'unread_count'");
    $has_unread = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'unread'");
    
    $column = $has_unread_count ? 'unread_count' : ($has_unread ? 'unread' : '');
    
    echo " - Detected Column: " . ($column ?: "NONE") . "\n";
    
    if ($column) {
        $count = $wpdb->get_var("SELECT SUM({$column}) FROM {$table}");
        echo " - Total Unread in Table: " . (int)$count . "\n";
    }
}

echo "\n--- TESTING sk_get_unread_count_endpoint ---\n";
if (function_exists('sk_get_unread_count_endpoint')) {
    $user_id = 456; // Use a known recipient ID from previous logs
    echo "Testing for User ID: $user_id\n";
    $resp = sk_get_unread_count_endpoint($user_id, true);
    if (is_object($resp)) {
        print_r($resp->get_data());
    } else {
        echo "Endpoint returned non-object: " . print_r($resp, true) . "\n";
    }
} else {
    echo "sk_get_unread_count_endpoint not found. Make sure functions_2.php is loaded.\n";
}
