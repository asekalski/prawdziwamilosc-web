<?php
require_once('wp-load.php');

$users = get_users(array(
    'role' => 'subscriber',
    'number' => 100,
    'orderby' => 'ID',
    'order' => 'DESC'
));

echo "ID\tLogin\tEmail\tRegistered\n";
echo str_repeat("-", 60) . "\n";

foreach ($users as $user) {
    echo $user->ID . "\t" . $user->user_login . "\t" . $user->user_email . "\t" . $user->user_registered . "\n";
}
