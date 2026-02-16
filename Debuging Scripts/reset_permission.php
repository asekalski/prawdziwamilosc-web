<?php
require_once('wp-load.php');

$user_id = 452; // Artur
echo "<h1>Reset Chat Permission for User: $user_id</h1>";

if (isset($_GET['remove_id'])) {
    $target_id = intval($_GET['remove_id']);
    
    $allowed = get_user_meta($user_id, 'sk_allowed_chat_ids', true) ?: [];
    
    // Remove ID
    if (($key = array_search($target_id, $allowed)) !== false) {
        unset($allowed[$key]);
        update_user_meta($user_id, 'sk_allowed_chat_ids', array_values($allowed));
        echo "<h3 style='color:green'>Removed User $target_id from allowed list.</h3>";
    } else {
        echo "<h3 style='color:orange'>User $target_id was not in the list.</h3>";
    }
}

// Show current list
$allowed = get_user_meta($user_id, 'sk_allowed_chat_ids', true) ?: [];
echo "<h2>Currently Allowed Users:</h2>";

if (empty($allowed)) {
    echo "No users allowed.";
} else {
    echo "<ul>";
    foreach ($allowed as $uid) {
        $user_data = get_userdata($uid);
        $name = $user_data ? $user_data->display_name : "Unknown User";
        echo "<li>
            <strong>ID: $uid ($name)</strong> 
            <a href='?remove_id=$uid' style='color:red; font-weight:bold; margin-left:10px;'>[RESET PERMISSION]</a>
        </li>";
    }
    echo "</ul>";
}
?>
