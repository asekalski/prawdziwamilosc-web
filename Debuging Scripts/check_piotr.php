<?php
/**
 * FIX: Unblock Piotr (454) from Artur (452) sk_blocked_users
 * Access: https://prawdziwamilosc.pl/check_piotr.php
 * DELETE AFTER USE!
 */
require_once(__DIR__ . '/wp-load.php');

header('Content-Type: application/json; charset=utf-8');

$artur_id = 452;
$piotr_id = 454;

// Get current blocked list
$blocked = get_user_meta($artur_id, 'sk_blocked_users', true) ?: [];

echo json_encode([
    'before' => [
        'artur_452_blocked' => $blocked,
        'piotr_in_list' => in_array($piotr_id, $blocked),
    ]
], JSON_PRETTY_PRINT);

// Remove Piotr (454) from blocked list
if (in_array($piotr_id, $blocked)) {
    $blocked = array_values(array_diff($blocked, [$piotr_id]));
    update_user_meta($artur_id, 'sk_blocked_users', $blocked);
    
    $after = get_user_meta($artur_id, 'sk_blocked_users', true) ?: [];
    echo "\n\n" . json_encode([
        'action' => 'UNBLOCKED Piotr (454) from Artur (452)',
        'after' => [
            'artur_452_blocked' => $after,
            'piotr_in_list' => in_array($piotr_id, $after),
        ]
    ], JSON_PRETTY_PRINT);
} else {
    echo "\n\n" . json_encode(['action' => 'Piotr was NOT in blocked list, no changes made'], JSON_PRETTY_PRINT);
}
