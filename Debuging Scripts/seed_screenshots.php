<?php
/**
 * seed_screenshots.php (v3 - Final Mapping)
 * Script to create 5 high-quality dummy profiles for App Store screenshots.
 * 
 * Instructions:
 * 1. Upload this script to your WordPress root directory.
 * 2. Ensure the 'seed_images' folder (with the generated .png files) is also in the root.
 * 3. Run the script by visiting yourdomain.com/seed_screenshots.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('WP_USE_THEMES', false);
if (file_exists('wp-load.php')) {
    require_once('wp-load.php');
} else {
    die('Error: This script must be placed in the WordPress root directory where wp-load.php exists.');
}

require_once(ABSPATH . 'wp-admin/includes/user.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

// FIELD DISCOVERY MODE
if (isset($_GET['discover'])) {
    global $wpdb;
    echo "<h1>Field Discovery</h1>";
    $fields = $wpdb->get_results("SELECT id, name, type FROM {$wpdb->prefix}bp_xprofile_fields WHERE parent_id = 0");
    foreach ($fields as $f) {
        echo "<strong>ID: {$f->id} | Name: {$f->name} | Type: {$f->type}</strong><br>";
        $options = $wpdb->get_results($wpdb->prepare("SELECT name FROM {$wpdb->prefix}bp_xprofile_fields WHERE parent_id = %d", $f->id));
        if ($options) {
            echo "Options: ";
            $opt_list = [];
            foreach ($options as $o) $opt_list[] = $o->name;
            echo implode(', ', $opt_list) . "<br>";
        }
        echo "<hr>";
    }
    echo "<p><a href='seed_screenshots.php'>Back to Seeder</a></p>";
    exit;
}

echo "<p><a href='?discover=1' style='background:#eee; padding:10px; border:1px solid #ccc; text-decoration:none;'>[!] RE-RUN DISCOVERY</a></p>";

// Helper to set xprofile field with logging
function sk_seed_field($field_id_or_name, $user_id, $value) {
    if (!function_exists('xprofile_set_field_data')) return false;
    $result = xprofile_set_field_data($field_id_or_name, $user_id, $value);
    if ($result) {
        echo "<span style='color:green;'>- [SUCCESS] $field_id_or_name set to: $value</span><br>";
    } else {
        $current = xprofile_get_field_data($field_id_or_name, $user_id);
        if ($current == $value) {
             echo "<span style='color:blue;'>- [NO CHANGE] $field_id_or_name already has value: $value</span><br>";
             return true;
        }
        echo "<span style='color:red;'>- [FAILED] $field_id_or_name could NOT be set to: $value</span><br>";
    }
    return $result;
}

// CONFIGURATION
$target_user_id = 1; // ID of the user who will receive the initial messages
$image_dir = ABSPATH . 'seed_images/'; 

$profiles = [
    [
        'username' => 'ania_sk',
        'email'    => 'ania@test.pl',
        'display_name' => 'Ania',
        'gender'   => 'Kobieta',
        'age'      => 30,
        'faith'    => 'Wierzący',
        'politics' => 'Liberalne',
        'work'     => 'Własny Biznes',
        'diet'     => 'Wegetarianin',
        'image'    => 'profile_ania_1770930857095.png',
        'msg'      => 'Hej! Cieszę się, że Cię tu widzę. Co u Ciebie słychać?'
    ],
    [
        'username' => 'marek_sk',
        'email'    => 'marek@test.pl',
        'display_name' => 'Marek',
        'gender'   => 'Mężczyzna',
        'age'      => 32,
        'faith'    => 'Ateista',
        'politics' => 'Konserwatywne',
        'work'     => 'Normalna Praca',
        'diet'     => 'Wszystkożerca',
        'image'    => 'profile_marek_1770930857095_2_retry_1770930926135.png',
        'msg'      => 'Cześć! Fajny profil. Też interesujesz się numerologią?'
    ],
    [
        'username' => 'karolina_sk',
        'email'    => 'karolina@test.pl',
        'display_name' => 'Karolina',
        'gender'   => 'Kobieta',
        'age'      => 28,
        'faith'    => 'Duchowy',
        'politics' => 'Liberalne',
        'work'     => 'Praca Kreatywna',
        'diet'     => 'Weganin',
        'image'    => 'profile_karolina_1770930857095_3_retry_1770930940405.png',
        'msg'      => 'Dzień dobry :) Mam nadzieję, że Twój dzień jest wspaniały!'
    ],
    [
        'username' => 'tomek_sk',
        'email'    => 'tomek@test.pl',
        'display_name' => 'Tomek',
        'gender'   => 'Mężczyzna',
        'age'      => 35,
        'faith'    => 'Wierzący',
        'politics' => 'Konserwatywne',
        'work'     => 'Korporacja',
        'diet'     => 'Wszystkożerca',
        'image'    => 'profile_tomek_1770930857095_4_retry_1770930954213.png',
        'msg'      => 'Witaj! Szukam kogoś do wspólnych rozmów o życiu. Może to Ty?'
    ],
    [
        'username' => 'julia_sk',
        'email'    => 'julia@test.pl',
        'display_name' => 'Julia',
        'gender'   => 'Kobieta',
        'age'      => 25,
        'faith'    => 'Inne',
        'politics' => 'Apolityczny',
        'work'     => 'Nie pracuję',
        'diet'     => 'Keto/Inne',
        'image'    => 'profile_julia_1770930857095_5_retry_1770930966263.png',
        'msg'      => 'Hejka! Widzę, że mamy podobne podejście do wiary. Pogadamy?'
    ]
];

echo "<h1>Seeding Profiles (v3 - Final Mapping)</h1>";

foreach ($profiles as $p) {
    echo "<h2>Processing: {$p['username']}</h2>";
    
    // 1. User Creation
    if (username_exists($p['username'])) {
        $user_id = username_exists($p['username']);
        echo "User already exists (ID: $user_id). Updating...<br>";
    } else {
        $user_id = wp_create_user($p['username'], 'password123', $p['email']);
        if (is_wp_error($user_id)) {
            echo "<span style='color:red;'>Error creating user: " . $user_id->get_error_message() . "</span><br>";
            continue;
        }
        echo "<span style='color:green;'>Created user (ID: $user_id).</span><br>";
    }

    wp_update_user(['ID' => $user_id, 'display_name' => $p['display_name']]);

    // 2. xprofile Fields (Using discovered Mapping)
    echo "<h3>Setting xprofile data:</h3>";
    sk_seed_field(1, $user_id, $p['display_name']); // ID 1 = Name
    sk_seed_field(129, $user_id, $p['gender']);   // ID 129 = Płeć
    
    $birth_year = date('Y') - $p['age'];
    sk_seed_field(107, $user_id, "{$birth_year}-06-15 00:00:00"); // ID 107 = Data urodzenia
    
    sk_seed_field(346, $user_id, $p['faith']);    // ID 346 = Podejście do wiary
    sk_seed_field(351, $user_id, $p['politics']); // ID 351 = Poglądy Polityczne
    sk_seed_field(356, $user_id, $p['work']);     // ID 356 = Styl Pracy
    sk_seed_field(362, $user_id, $p['diet']);     // ID 362 = Styl jedzenia

    // 3. Avatar Assignment
    echo "<h3>Assigning Avatar:</h3>";
    $image_path = $image_dir . $p['image'];
    if (file_exists($image_path) && function_exists('bp_core_avatar_upload_path')) {
        $avatar_dir = bp_core_avatar_upload_path() . '/avatars/' . $user_id . '/';
        if (!file_exists($avatar_dir)) wp_mkdir_p($avatar_dir);
        
        $avatar_full = $avatar_dir . $user_id . '-bpfull.jpg';
        $avatar_thumb = $avatar_dir . $user_id . '-bpthumb.jpg';
        
        $editor = wp_get_image_editor($image_path);
        if (!is_wp_error($editor)) {
            $editor->resize(450, 450, true);
            $editor->save($avatar_full);
            $editor->resize(150, 150, true);
            $editor->save($avatar_thumb);
            echo "<span style='color:green;'>SUCCESS: Avatar saved.</span><br>";
        } else {
             copy($image_path, $avatar_full);
             copy($image_path, $avatar_thumb);
             echo "Used copy fallback.<br>";
        }
    }

    // 4. Send Message
    echo "<h3>Sending Message:</h3>";
    if (function_exists('Better_Messages') && $target_user_id) {
        $bm_args = ['sender_id' => $user_id, 'recipients' => [$target_user_id], 'content' => $p['msg'], 'force' => true];
        $result = Better_Messages()->functions->new_message($bm_args);
        if ($result) echo "<span style='color:green;'>SUCCESS: Message sent.</span><br>";
    }
}

echo "<h2>Seeding Complete!</h2>";
echo "<p style='font-weight:bold; color:red;'>DELETE THIS SCRIPT NOW!</p>";
