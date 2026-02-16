<?php
require_once('wp-load.php');
$users = get_users(['orderby' => 'registered', 'order' => 'DESC', 'number' => 5]);
foreach ($users as $user) {
    echo "ID: " . $user->ID . " | Login: " . $user->user_login . " | Email: " . $user->user_email . " | Registered: " . $user->user_registered . "\n";
}
