<?php
// Add this to functions_2.php temporarily

add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/debug-thread/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'sk_debug_thread_check',
        'permission_callback' => '__return_true',
    ]);
});

function sk_debug_thread_check($request) {
    global $wpdb;
    $thread_id = $request->get_param('id');
    
    $bp_messages_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}bp_messages_messages WHERE thread_id = %d",
        $thread_id
    ));

    $bp_thread = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bp_messages_threads WHERE id = %d",
        $thread_id
    ));
    
    return [
        'thread_id' => $thread_id,
        'bp_messages_count' => $bp_messages_count,
        'bp_thread_exists' => $bp_thread ? 'yes' : 'no',
        'bp_thread_date' => $bp_thread ? $bp_thread->date_sent : null
    ];
}
