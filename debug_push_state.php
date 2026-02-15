<?php
define('WP_USE_THEMES', false);
require_once('wp-load.php');

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : get_current_user_id();

if (!$user_id) {
    die("No user ID provided and not logged in.");
}

echo "<h1>Debug for User: $user_id</h1>";

// 1. Alerted Threads Meta
$alerted = get_user_meta($user_id, 'sk_alerted_threads', true);
echo "<h2>sk_alerted_threads Meta:</h2>";
echo "<pre>";
print_r($alerted);
echo "</pre>";

// 2. Unread Count and IDs
$results = sk_get_unread_count_endpoint($user_id, true);
echo "<h2>Unread Count Endpoint (Skip Reset):</h2>";
echo "<pre>";
print_r($results->get_data());
echo "</pre>";

// 3. Tokens
$tokens = get_user_meta($user_id, 'sk_expo_push_token', false);
echo "<h2>sk_expo_push_token:</h2>";
echo "<pre>";
print_r($tokens);
echo "</pre>";

// 4. Check BP Table directly
global $wpdb;
$table_recipients = $wpdb->prefix . 'bp_messages_recipients';
$bp_unread = $wpdb->get_results($wpdb->prepare("SELECT thread_id, unread_count FROM $table_recipients WHERE user_id = %d AND unread_count > 0", $user_id));
echo "<h2>BuddyPress Unread (DB):</h2>";
echo "<pre>";
print_r($bp_unread);
echo "</pre>";

// 5. Check BM Table directly
$bm_table = $wpdb->prefix . 'bm_message_recipients';
if ($wpdb->get_var("SHOW TABLES LIKE '$bm_table'") == $bm_table) {
    $bm_unread = $wpdb->get_results($wpdb->prepare("SELECT thread_id, unread FROM $bm_table WHERE user_id = %d AND unread > 0", $user_id));
    echo "<h2>Better Messages Unread (DB):</h2>";
    echo "<pre>";
    print_r($bm_unread);
    echo "</pre>";
}
