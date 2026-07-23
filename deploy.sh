#!/bin/bash
LOCAL_FILE="/Users/artursekalski/.gemini/Prawdziwa_Milosc/prawdziwamilosc.pl_ver5-web/functions_2.php"
REMOTE_DEST="server850532@server850532.nazwa.pl:PrawdziwaMilosc/wp-content/themes/astra-child/functions.php"
KEY_FILE="$HOME/.ssh/id_rsa"

echo "Deploying functions_2.php to server..."
scp -i "$KEY_FILE" -o ConnectTimeout=15 "$LOCAL_FILE" "$REMOTE_DEST"

if [ $? -eq 0 ]; then
    echo "🎉 Upload successful! File deployed to PrawdziwaMilosc."
    echo "Resetting OPcache..."
    curl -i https://prawdziwamilosc.pl/wp-content/themes/astra-child/opcache_reset.php
else
    echo "❌ Upload failed. Please check your network or wait a moment for the server firewall lock to clear."
fi
