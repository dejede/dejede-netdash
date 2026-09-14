#!/bin/sh
set -e
ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

mkdir -p /usr/share/luci/menu.d
mkdir -p /www/luci-static/resources/view/dejede

rm -f /usr/share/luci/menu.d/dejede-network-dashboard.json
rm -f /www/luci-static/resources/view/dejede/network_dashboard.js
rm -f /www/luci-static/resources/view/dejede/mwan3dash.js

cp "$ROOT/luci/usr/share/luci/menu.d/dejede-network-dashboard.json" /usr/share/luci/menu.d/dejede-network-dashboard.json
cp "$ROOT/luci/www/luci-static/resources/view/dejede/mwan3dash.js" /www/luci-static/resources/view/dejede/mwan3dash.js

rm -f /tmp/luci-indexcache*

echo "DEJEDE Network Dashboard loader installed."
echo "Target: /mwan3dash/index.php"
echo "Existing /www/mwan3dash/ was NOT modified."
