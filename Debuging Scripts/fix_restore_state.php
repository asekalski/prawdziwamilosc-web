<?php
require_once('wp-load.php');

$user_id_1 = 494; // Artur
$target_id = 456; // Argen

echo "--- FIXING Restore State ---\n";

$skipped = get_user_meta($user_id_1, 'sk_skipped_users', true) ?: [];
echo "Current Skipped: " . print_r($skipped, true) . "\n";

if (in_array($target_id, $skipped)) {
    echo "User $target_id found in skipped list. Removing...\n";
    $skipped = array_diff($skipped, [$target_id]);
    update_user_meta($user_id_1, 'sk_skipped_users', array_values($skipped));
    echo "SUCCESS: User removed from skipped list.\n";
} else {
    echo "User $target_id is NOT in skipped list. No action needed.\n";
}

$skipped_after = get_user_meta($user_id_1, 'sk_skipped_users', true) ?: [];
echo "Final Skipped: " . print_r($skipped_after, true) . "\n";
