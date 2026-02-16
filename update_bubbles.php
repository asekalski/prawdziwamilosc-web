<?php
require_once( 'wp-load.php' );

// Get latest user
$users = get_users(['number' => 1, 'orderby' => 'ID', 'order' => 'DESC']);
if (empty($users)) die("No users found");
$user = $users[0];
$uid = $user->ID;

echo "User ID: $uid (" . $user->user_login . ")\n";

// Set values for bubbles
$fields = [
    346 => 'Wierzący', // Faith
    351 => 'Liberalne', // Politics
    356 => 'Freelancerka', // Work
    362 => 'Vege' // Diet
];

foreach ($fields as $fid => $val) {
    $res = xprofile_set_field_data($fid, $uid, $val);
    echo "Set Field $fid to '$val': " . ($res ? 'Success' : 'Failed') . "\n";
}

echo "Done.\n";
