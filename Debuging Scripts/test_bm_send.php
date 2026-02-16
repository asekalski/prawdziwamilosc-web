<?php
require_once('wp-load.php');

$sender_id = isset($_GET['sender_id']) ? intval($_GET['sender_id']) : 494; // Default to the user from logs
$recipient_id = isset($_GET['recipient_id']) ? intval($_GET['recipient_id']) : 456; // Default to recipient from logs

echo "UNIQUE MARKER: " . time() . "\n";
echo "Testing Better Messages API send_message...\n";
echo "Sender: $sender_id, Recipient: $recipient_id\n";

if (!function_exists('better_messages')) {
    die("Better Messages API not found.\n");
}

$bm_args = [
    'sender_id'    => $sender_id,
    'recipients'   => [$recipient_id], // Array syntax
    'subject'      => 'Super Wiadomość',
    'content'      => 'This is a test message from debug script direct call.',
    'return'       => 'thread_id'
];

echo "Args: " . print_r($bm_args, true) . "\n";

try {
    echo "Attempting Better_Messages()->functions->new_message...\n";
    
    if (!class_exists('Better_Messages')) {
        die("Class Better_Messages does not exist.\n");
    }
    
    $instance = Better_Messages();
    if (!isset($instance->functions)) {
        echo "Better_Messages instance has no 'functions' property.\n";
        print_r($instance);
        die();
    }

    $sent_id = $instance->functions->new_message($bm_args);
    
    if (is_wp_error($sent_id)) {
        echo "Error: " . $sent_id->get_error_message() . "\n";
    } else {
        echo "Success! Thread ID: $sent_id\n";
        
        // Check DB immediately
        global $wpdb;
        $table_recipients = 'wppy_bm_message_recipients'; // Assuming prefix wppy_
        
        // Show ALL columns for this thread
        $rows = $wpdb->get_results("SELECT * FROM $table_recipients WHERE thread_id = $sent_id");
        print_r($rows);
    }

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
