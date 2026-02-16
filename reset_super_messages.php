<?php
require_once('wp-load.php');

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$username = isset($_GET['username']) ? sanitize_user($_GET['username']) : '';

if (!$user_id && !$username) {
    echo "Usage: ?user_id=X or ?username=name\n";
    exit;
}

if ($username && !$user_id) {
    $user = get_user_by('login', $username);
    if ($user) {
        $user_id = $user->ID;
    } else {
        die("User '$username' not found.");
    }
}

echo "Resetting Super Message limit for User ID: $user_id...\n";

// 1. Reset 'sk_super_messages_week' (This is what sk_get_remaining_super_messages uses!)
update_user_meta($user_id, 'sk_super_messages_week', []);
echo "Reset 'sk_super_messages_week'.\n";

// 2. Reset 'sk_super_messages_sent' (This tracks pending messages)
update_user_meta($user_id, 'sk_super_messages_sent', []);
echo "Reset 'sk_super_messages_sent'.\n";

// 3. Reset legacy count just in case
update_user_meta($user_id, 'sk_super_message_count_weekly', 0);

echo "Done. Weekly limit and pending status cleared.\n";
