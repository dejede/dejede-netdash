#!/bin/sh
set -e
rm -f /usr/share/luci/menu.d/dejede-network-dashboard.json
rm -f /www/luci-static/resources/view/dejede/mwan3dash.js
rm -f /www/luci-static/resources/view/dejede/network_dashboard.js
rm -f /tmp/luci-indexcache*
echo "DEJEDE Network Dashboard loader removed."
echo "Existing /www/mwan3dash/ was NOT modified."
