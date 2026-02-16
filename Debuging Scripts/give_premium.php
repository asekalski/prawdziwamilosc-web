<?php
/**
 * Utility script to grant Premium status to test users
 * Usage: Visit this script in browser with ?user_id=XX or ?user_login=YY
 */

require_once('wp-load.php');

if (!current_user_can('manage_options') && $_GET['secret'] !== 'pm2026') {
    die('Access denied. Use ?secret=pm2026 if not logged in as admin.');
}

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$user_login = isset($_GET['user_login']) ? $_GET['user_login'] : '';

if (!$user_id && $user_login) {
    $user = get_user_by('login', $user_login);
    if ($user) $user_id = $user->ID;
}

if (!$user_id) {
    echo "<h1>Grant Premium Status</h1>";
    echo "<p>Usage: <code>?user_id=123</code> or <code>?user_login=testuser</code></p>";
    
    echo "<h3>Recent Users:</h3><ul>";
    $users = get_users(['number' => 10, 'orderby' => 'user_registered', 'order' => 'DESC']);
    foreach ($users as $u) {
        $roles = implode(', ', $u->roles);
        echo "<li>ID: {$u->ID} | Login: {$u->user_login} | Roles: [{$roles}] | <a href='?user_id={$u->ID}&secret=pm2026'>Grant Premium</a></li>";
    }
    echo "</ul>";
    exit;
}

$user = get_userdata($user_id);
if (!$user) die('User not found.');

// Add premium role
$user->add_role('premium');

// OR update if roles are missing
if (empty($user->roles)) {
    $user->set_role('premium');
}

// BuddyPress Member Type
if (function_exists('bp_set_member_type')) {
    bp_set_member_type($user_id, 'premium');
}

echo "<h1>Success!</h1>";
echo "<p>User <b>{$user->user_login}</b> (ID: {$user_id}) is now <b>Premium</b>.</p>";
echo "<p><a href='give_premium.php?secret=pm2026'>Back to list</a></p>";
