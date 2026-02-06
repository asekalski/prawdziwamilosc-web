<?php
// Load WordPress environment
// Adjust path as needed. Assuming typical WP structure where web dir is root or public_html
// Current cwd is /Users/artursekalski/.gemini/prawdziwamilosc.pl_ver2-web/
if (file_exists('wp-load.php')) {
    require_once('wp-load.php');
} else {
    die("Error: wp-load.php not found in current directory.\n");
}

// Find a user ID if not provided
$user_id = 1;
$user = get_users(['number' => 1, 'fields' => 'ID']);
if ($user) {
    $user_id = $user[0];
}

echo "Testing with User ID: $user_id\n";

if (!function_exists('sk_get_batch_xprofile_data')) {
    die("Error: sk_get_batch_xprofile_data function not found.\n");
}

$data = sk_get_batch_xprofile_data([$user_id]);
echo "Batch Data:\n";
print_r($data);

if (isset($data[$user_id]['Data urodzenia'])) {
    $birth_date = $data[$user_id]['Data urodzenia'];
    echo "Raw Birth Date: " . print_r($birth_date, true) . "\n";
    
    if (function_exists('get_zodiac_sign')) {
        $zodiac = get_zodiac_sign($birth_date);
        echo "Calculated Zodiac: $zodiac\n";
    } else {
        echo "Error: get_zodiac_sign function not found.\n";
    }
} else {
    echo "Error: 'Data urodzenia' not found in batch data.\n";
}
