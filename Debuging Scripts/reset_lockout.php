<?php
require_once('wp-load.php');

if (!current_user_can('manage_options') && !isset($_GET['force'])) {
    // die('Access Denied');
}

echo "<h1>Detailed Lockout Debug</h1>";

global $wpdb;

// 1. Check for specific plugin options
$search_terms = ['%limit%', '%lockout%', '%ban%', '%wf%', '%wordfence%', '%ithemes%'];
foreach ($search_terms as $term) {
    echo "<h2>Searching for '$term' in wp_options</h2>";
    $results = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 20", $term));
    
    if ($results) {
        foreach ($results as $row) {
            echo "<strong>{$row->option_name}:</strong> " . substr(print_r(maybe_unserialize($row->option_value), true), 0, 200) . "<br>";
        }
    } else {
        echo "No matches found.<br>";
    }
}

// 2. Check explicitly for 'limit_login_lockouts' (sometimes stored even if transient search failed)
echo "<h2>Direct Option Check</h2>";
$direct_check = get_option('limit_login_lockouts');
echo "<strong>limit_login_lockouts:</strong> <pre>" . print_r($direct_check, true) . "</pre><br>";

// 3. Clear everything suspicious aggressively
echo "<h2>Aggressive Clearance</h2>";
$queries = [
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%limit_login%'",
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%lockout%'",
    "TRUNCATE TABLE {$wpdb->prefix}wfLocs", // IF Wordfence exists
    "TRUNCATE TABLE {$wpdb->prefix}wfBlocks7" // IF Wordfence exists
];

foreach ($queries as $sql) {
    // Only run table truncation if table exists
    if (strpos($sql, 'TRUNCATE') !== false) {
        $table_name = explode(' ', $sql)[2];
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            echo "Skipping $sql (Table not found)<br>";
            continue;
        }
    }
    
    $result = $wpdb->query($sql);
    echo "Executed: $sql (Result: $result)<br>";
}

echo "<h2>Done! Try logging in now.</h2>";
?>
