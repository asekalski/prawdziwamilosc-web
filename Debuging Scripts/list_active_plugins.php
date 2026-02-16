<?php
require_once('wp-load.php');

if (!current_user_can('manage_options') && !isset($_GET['force'])) {
    // die('Access Denied');
}

echo "<h1>Active Plugins</h1>";
$active_plugins = get_option('active_plugins');
echo "<pre>";
print_r($active_plugins);
echo "</pre>";

echo "<h2>Limit Login Attempts / Lockouts Data</h2>";
// standard limit login attempts
echo "<h3>Transients (limit_login_%)</h3>";
global $wpdb;
$transients = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%limit_login_%'");
foreach ($transients as $t) {
    echo "<strong>{$t->option_name}:</strong> <pre>" . print_r(maybe_unserialize($t->option_value), true) . "</pre><br>";
}

// Wordfence
echo "<h3>Wordfence (wfConfig)</h3>";
$wfCheck = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wfConfig'");
if ($wfCheck) {
    echo "Wordfence tables exist.<br>";
} else {
    echo "Wordfence tables NOT found.<br>";
}
?>
