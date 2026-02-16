<?php
/**
 * OPcache Status Test
 * 
 * USUŃ TEN PLIK PO SPRAWDZENIU!
 */

echo '<html><head><meta charset="UTF-8"><title>OPcache Test</title></head><body style="font-family: Arial, sans-serif; padding: 20px; max-width: 600px; margin: 0 auto;">';

echo '<h1>🔍 Test OPcache</h1>';

if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    
    if ($status && isset($status['opcache_enabled']) && $status['opcache_enabled']) {
        echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 8px; margin: 20px 0;">';
        echo '<h2 style="color: #155724; margin-top: 0;">✅ OPcache jest WŁĄCZONY</h2>';
        echo '</div>';
        
        echo '<h3>📊 Statystyki:</h3>';
        echo '<table style="width: 100%; border-collapse: collapse;">';
        
        // Memory
        $usedMem = round($status['memory_usage']['used_memory'] / 1024 / 1024, 2);
        $freeMem = round($status['memory_usage']['free_memory'] / 1024 / 1024, 2);
        $totalMem = $usedMem + $freeMem;
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'>💾 Pamięć używana:</td><td><strong>{$usedMem} MB</strong> / {$totalMem} MB</td></tr>";
        
        // Scripts
        $scripts = $status['opcache_statistics']['num_cached_scripts'];
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'>📄 Załadowane skrypty:</td><td><strong>{$scripts}</strong></td></tr>";
        
        // Hit rate
        $hits = $status['opcache_statistics']['hits'];
        $misses = $status['opcache_statistics']['misses'];
        $hitRate = ($hits + $misses > 0) ? round(($hits / ($hits + $misses)) * 100, 2) : 0;
        echo "<tr><td style='padding: 8px; border-bottom: 1px solid #ddd;'>🎯 Hit Rate:</td><td><strong>{$hitRate}%</strong></td></tr>";
        
        echo '</table>';
        
    } else {
        echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 8px; margin: 20px 0;">';
        echo '<h2 style="color: #721c24; margin-top: 0;">❌ OPcache jest WYŁĄCZONY</h2>';
        echo '<p>Skontaktuj się z hostingiem lub włącz w panelu PHP.</p>';
        echo '</div>';
    }
} else {
    echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 8px; margin: 20px 0;">';
    echo '<h2 style="color: #721c24; margin-top: 0;">❌ OPcache nie jest zainstalowany</h2>';
    echo '<p>Twój serwer nie ma zainstalowanego rozszerzenia OPcache.</p>';
    echo '</div>';
}

echo '<hr style="margin: 30px 0;">';
echo '<p style="color: #dc3545; font-weight: bold;">⚠️ USUŃ TEN PLIK PO SPRAWDZENIU!</p>';
echo '<p style="color: #666; font-size: 12px;">Plik: opcache-test.php</p>';

echo '</body></html>';
