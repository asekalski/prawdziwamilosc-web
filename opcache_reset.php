<?php
/**
 * OPcache Reset
 */
if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    echo "OPcache Reset result: " . ($result ? "SUCCESS" : "FAILURE");
} else {
    echo "opcache_reset() function not found.";
}
