<?php
// Load WordPress environment
require( dirname( __FILE__ ) . '/wp-load.php' );

// 1. Find User "Argen-Przyklad"
$argen_user = get_user_by( 'login', 'Argen-Przyklad' );
if ( ! $argen_user ) {
    // Try finding by display name
    $users = get_users(array(
        'search' => '*Argen*',
        'search_columns' => array('user_login', 'user_nicename', 'display_name')
    ));
    if (!empty($users)) {
        $argen_user = $users[0];
    }
}

if ( ! $argen_user ) {
    die( "❌ Could not find user 'Argen-Przyklad' or similar.\n" );
}

echo "✅ Found Target User: " . $argen_user->user_login . " (ID: " . $argen_user->ID . ")\n";
$target_id = $argen_user->ID;

// 2. Find Admin User (Assuming it's the one running this or 'admin' / ID 1)
// Better to search for 'admin' or 'artur'
$admin_user = get_user_by( 'login', 'artur' ); // adjusting to potential admin login
if (!$admin_user) $admin_user = get_user_by('login', 'admin');
if (!$admin_user) $admin_user = get_user_by('id', 1);

if ( ! $admin_user ) {
    die( "❌ Could not find Admin user.\n" );
}

echo "✅ Found Admin User: " . $admin_user->user_login . " (ID: " . $admin_user->ID . ")\n";
$admin_id = $admin_user->ID;

// 3. Check Skipped List
$skipped = get_user_meta( $admin_id, 'sk_skipped_users', true );
if ( ! is_array( $skipped ) ) {
    $skipped = array();
}

echo "📋 Current Skipped List: " . implode( ', ', $skipped ) . "\n";

// 4. Unblock
if ( in_array( $target_id, $skipped ) || in_array((string)$target_id, $skipped) ) {
    $new_skipped = array_diff( $skipped, array( $target_id, (string)$target_id ) );
    update_user_meta( $admin_id, 'sk_skipped_users', $new_skipped );
    echo "🎉 SUCCESS: Removed User ID $target_id from Skipped List.\n";
    echo "📋 New Skipped List: " . implode( ', ', $new_skipped ) . "\n";
} else {
    echo "ℹ️ User ID $target_id was NOT found in the skipped list.\n";
}

// 5. Check Blocked List (BuddyPress) if applicable
if ( function_exists( 'bp_is_active' ) && bp_is_active( 'friends' ) ) {
   // Assuming simple skipped mechanism for now as per previous context
}
