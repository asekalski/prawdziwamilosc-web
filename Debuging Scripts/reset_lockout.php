<?php
require_once('wp-load.php');

if (!current_user_can('manage_options') && !isset($_GET['force'])) {
    // die('Access Denied');
}

echo "<h1>Detailed Lockout Debug</h1>";

$user_ip = $_SERVER['REMOTE_ADDR'];
echo "<h2>Your IP: $user_ip</h2>";

global $wpdb;

// 0. List all tables to see what we are dealing with
echo "<h2>Database Tables</h2>";
$tables = $wpdb->get_col("SHOW TABLES");
echo "<ul>";
foreach ($tables as $table) {
    echo "<li>$table</li>";
}
echo "</ul>";

// 1. Check for specific plugin options and transients
$search_terms = ['%limit%', '%lockout%', '%ban%', '%wf%', '%wordfence%', '%ithemes%', '%jwt%', '%retry%', '%loginizer%', '%' . $user_ip . '%'];
foreach ($search_terms as $term) {
    echo "<h2>Searching for '$term' in wp_options (including transients)</h2>";
    $results = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 50", $term));
    
    if ($results) {
        foreach ($results as $row) {
            echo "<strong>{$row->option_name}:</strong> " . substr(print_r(maybe_unserialize($row->option_value), true), 0, 300) . "<br>";
        }
    } else {
        echo "No matches found.<br>";
    }
}

// 2. Check explicitly for 'limit_login_lockouts'
echo "<h2>Direct Option Check</h2>";
$direct_check = get_option('limit_login_lockouts');
echo "<strong>limit_login_lockouts:</strong> <pre>" . print_r($direct_check, true) . "</pre><br>";

// 3. Clear everything suspicious aggressively
echo "<h2>Aggressive Clearance</h2>";

// Discover Loginizer tables
$loginizer_tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}loginizer%'");
$loginizer_queries = [];
foreach ($loginizer_tables as $table) {
    $loginizer_queries[] = "TRUNCATE TABLE $table";
}

$queries = array_merge([
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%limit_login%'",
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%lockout%'",
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%jwt_auth_retry%'",
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_jwt%'",
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_timeout_jwt%'",
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%loginizer%'",
    "DELETE FROM {$wpdb->options} WHERE option_value LIKE '%$user_ip%'",
    "TRUNCATE TABLE {$wpdb->prefix}wfLocs",
    "TRUNCATE TABLE {$wpdb->prefix}wfBlocks7"
], $loginizer_queries);

foreach ($queries as $sql) {
    // Only run table truncation if table exists
    if (strpos($sql, 'TRUNCATE') !== false) {
        $parts = explode(' ', $sql);
        $table_name = end($parts);
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            echo "Skipping $sql (Table not found)<br>";
            continue;
        }
    }
    
    $result = $wpdb->query($sql);
    echo "Executed: $sql (Result: $result)<br>";
}

// 4. Check user meta for IP as well
echo "<h2>Searching for IP in wp_usermeta</h2>";
$meta_queries = [
    "DELETE FROM {$wpdb->usermeta} WHERE meta_value LIKE '%$user_ip%'"
];

foreach ($meta_queries as $sql) {
    $result = $wpdb->query($sql);
    echo "Executed: $sql (Result: $result)<br>";
}

// 5. Force clear transients via API just in case
delete_site_transient('jwt_auth_lockout_' . md5($user_ip));
delete_transient('jwt_auth_lockout_' . md5($user_ip));

echo "<h2>Done! Try logging in now.</h2>";
?>
