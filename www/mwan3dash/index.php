<?php
session_start();

/* ============================================================
   MEMBACA FILE .ENV SECARA OTOMATIS
   ============================================================ */
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

$telegram_token   = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
$telegram_chat_id = $_ENV['TELEGRAM_CHAT_ID'] ?? '';

function dejede_format_kb($kb) {
    $bytes = (float)$kb * 1024;
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes) . ' B';
}

/* ------------------------------------------------------------
   Default konfigurasi (dipakai jika config.php tidak ada /
   tidak lengkap). interface_map menghubungkan interface LOGIKAL
   (dipakai mwan3 & UI) ke nama device fisik di /proc/net/dev,
   sesuai /etc/config/network:
     wan1   -> device 'wan'
     wan2   -> device 'lan2'
     br-lan -> device 'br-lan'
   ------------------------------------------------------------ */
$refresh_interval_ms = 4000;
$default_theme    = 'clean';
$interface_labels = ['wan1' => 'WAN 1', 'wan2' => 'WAN 2', 'br-lan' => 'Bridge LAN'];
$interface_map    = ['wan1' => 'wan', 'wan2' => 'lan2', 'br-lan' => 'br-lan'];

$config_path = __DIR__ . '/config.php';
if (file_exists($config_path)) {
    $cfg = @include $config_path;
    if (is_array($cfg)) {
        if (isset($cfg['refresh_interval_ms']) && is_numeric($cfg['refresh_interval_ms'])) {
            $refresh_interval_ms = (int)$cfg['refresh_interval_ms'];
        }
        if (!empty($cfg['default_theme'])) {
            $default_theme = (string)$cfg['default_theme'];
        }
        if (isset($cfg['interface_labels']) && is_array($cfg['interface_labels'])) {
            $interface_labels = array_merge($interface_labels, $cfg['interface_labels']);
        }
        if (isset($cfg['interface_map']) && is_array($cfg['interface_map'])) {
            $interface_map = array_merge($interface_map, $cfg['interface_map']);
        }
    }
}

$allowed_themes = ['clean','robotic','cyberpunk','terminal','glass','gnome','light','nord','sunset','forest'];
if (!in_array($default_theme, $allowed_themes, true)) {
    $default_theme = 'clean';
}

/* ============================================================
   ENDPOINT AJAX: STATUS & TRAFIK SYSTEM (JSON)
   ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    $interfaces = [];
    $debug_raw  = '';

    // 1) Coba ubus dulu (JSON, jauh lebih stabil daripada parsing teks)
    $raw_ubus  = @shell_exec('ubus -S call mwan3 status 2>/dev/null');
    $ubus_data = $raw_ubus ? json_decode(trim($raw_ubus), true) : null;

    if (is_array($ubus_data) && !empty($ubus_data['interfaces']) && is_array($ubus_data['interfaces'])) {
        $debug_raw = $raw_ubus;
        foreach ($ubus_data['interfaces'] as $iface_name => $info) {
            $status = 'offline';
            if (isset($info['status']) && is_string($info['status'])) {
                $status = strtolower($info['status']);
            } elseif (isset($info['up'])) {
                $status = $info['up'] ? 'online' : 'offline';
            }
            $loss = 0;
            foreach (['lost_percent', 'loss', 'packet_loss'] as $k) {
                if (isset($info[$k]) && is_numeric($info[$k])) { $loss = (int)$info[$k]; break; }
            }
            $interfaces[] = [
                'iface'    => $iface_name,
                'label'    => $interface_labels[$iface_name] ?? $iface_name,
                'status'   => $status,
                'tracking' => isset($info['enabled']) ? ($info['enabled'] ? 'active' : '') : '',
                'loss_now' => $loss,
                'loss_avg' => $loss,
                'loss_max' => $loss,
            ];
        }
    }

    // 2) Kalau ubus tidak ada / hasilnya kosong, fallback ke parsing teks "mwan3 status"
    if (empty($interfaces)) {
        $raw_mwan3 = @shell_exec('mwan3 status 2>/dev/null');
        $debug_raw = $raw_mwan3 ?: $debug_raw;

        if ($raw_mwan3) {
            // Regex lengkap (format dengan baris "x/y/z% packet loss on IPv4")
            preg_match_all(
                '/^\s*(\S+)\s*\(([^)]+)\):\s*interface is (\w+)(?:,\s*tracking (\w+))?.*?(\d+)\/(\d+)\/(\d+)%\s*packet loss on IPv4/ms',
                $raw_mwan3,
                $matches,
                PREG_SET_ORDER
            );

            // Regex cadangan: kalau format lengkap di atas tidak cocok dengan versi
            // mwan3 router ini, cukup ambil nama interface + kata status-nya saja.
            if (empty($matches)) {
                preg_match_all(
                    '/^\s*(\S+)\s*\(([^)]+)\):\s*interface is (\w+)/m',
                    $raw_mwan3,
                    $matches,
                    PREG_SET_ORDER
                );
            }

            foreach ($matches as $m) {
                $iface_name = $m[1];
                $interfaces[] = [
                    'iface'    => $iface_name,
                    'label'    => $interface_labels[$iface_name] ?? $iface_name,
                    'status'   => $m[3],
                    'tracking' => isset($m[4]) ? $m[4] : '',
                    'loss_now' => isset($m[5]) ? (int)$m[5] : 0,
                    'loss_avg' => isset($m[6]) ? (int)$m[6] : 0,
                    'loss_max' => isset($m[7]) ? (int)$m[7] : 0,
                ];
            }
        }
    }

    $net_dev = @file_get_contents('/proc/net/dev');
    $traffic = [
        'wan1'   => ['rx' => 0, 'tx' => 0], 
        'wan2'   => ['rx' => 0, 'tx' => 0], 
        'br-lan' => ['rx' => 0, 'tx' => 0]
    ];
    
    if ($net_dev) {
        // Gunakan pemetaan dari config.php (interface_map) supaya sesuai
        // dengan device fisik yang sedang aktif di /etc/config/network & mwan3.
        foreach ($interface_map as $logical_key => $target_if) {
            $quoted = preg_quote($target_if, '/');
            if (preg_match('/^\s*' . $quoted . ':\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/m', $net_dev, $match)) {
                $traffic[$logical_key] = [
                    'rx' => (int)$match[1],
                    'tx' => (int)$match[2]
                ];
            }
        }
    }

    $uptime = @file_get_contents('/proc/uptime');
    $uptime_formatted = 'N/A';
    if ($uptime) {
        $seconds = (int)explode(' ', $uptime)[0];
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $mins = floor(($seconds % 3600) / 60);
        $uptime_formatted = ($days > 0 ? "$days hari " : "") . "$hours jam $mins menit";
    }

    header('Content-Type: application/json');
    $response = [
        'ok'          => true,
        'interfaces'  => $interfaces,
        'traffic'     => $traffic,
        'uptime'      => $uptime_formatted,
        'timestamp'   => microtime(true),
        'time_string' => date('H:i:s'),
    ];
    if (isset($_GET['debug'])) {
        $response['debug_raw'] = $debug_raw;
    }
    echo json_encode($response);
    exit;
}

/* ============================================================
   DEJEDE NETMONITOR ENGINE
   ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'netmonitor') {
    $state_file = '/tmp/dejede_netmonitor_state.json';
    $lock_file  = '/tmp/dejede_netmonitor_state.lock';

    $logical_interfaces = array_keys($interface_map);

    $net_dev = @file_get_contents('/proc/net/dev');
    $devices = [];

    if ($net_dev !== false) {
        foreach (preg_split('/\R/', $net_dev) as $line) {
            if (!preg_match('/^\s*([^:]+):\s*(.+)$/', $line, $m)) {
                continue;
            }

            $dev = trim($m[1]);
            $v = preg_split('/\s+/', trim($m[2]));

            if (count($v) >= 16) {
                $devices[$dev] = [
                    'rx_bytes'    => (float)$v[0],
                    'rx_packets'  => (float)$v[1],
                    'rx_errors'   => (float)$v[2],
                    'rx_dropped'  => (float)$v[3],
                    'tx_bytes'    => (float)$v[8],
                    'tx_packets'  => (float)$v[9],
                    'tx_errors'   => (float)$v[10],
                    'tx_dropped'  => (float)$v[11],
                ];
            }
        }
    }

    $now = microtime(true);
    $state = [];

    $lock = @fopen($lock_file, 'c');
    if ($lock) {
        @flock($lock, LOCK_EX);
    }

    if (is_file($state_file)) {
        $old = json_decode(@file_get_contents($state_file), true);
        if (is_array($old)) {
            $state = $old;
        }
    }

    $dt = isset($state['_time']) ? ($now - (float)$state['_time']) : 0.0;
    $valid_delta = ($dt >= 0.05 && $dt <= 5.0);

    $interfaces = [];

    foreach ($logical_interfaces as $logical) {
        $dev_key = $interface_map[$logical] ?? $logical;
        $cur = $devices[$dev_key] ?? null;
        $prev = $state[$logical] ?? null;

        if (!$cur) {
            $interfaces[$logical] = [
                'device' => $logical,
                'up' => false,
                'rx_mbps' => 0.0,
                'tx_mbps' => 0.0,
                'rx_bytes' => 0,
                'tx_bytes' => 0
            ];
            continue;
        }

        $rx_delta = 0.0;
        $tx_delta = 0.0;

        if ($valid_delta && is_array($prev) && isset($prev['rx_bytes'], $prev['tx_bytes'])) {
            if ($cur['rx_bytes'] >= $prev['rx_bytes']) {
                $rx_delta = $cur['rx_bytes'] - $prev['rx_bytes'];
            }
            if ($cur['tx_bytes'] >= $prev['tx_bytes']) {
                $tx_delta = $cur['tx_bytes'] - $prev['tx_bytes'];
            }
        }

        $interfaces[$logical] = [
            'device'      => $logical,
            'up'          => true,
            'rx_mbps'     => ($valid_delta ? ($rx_delta * 8.0 / $dt / 1000000.0) : 0.0),
            'tx_mbps'     => ($valid_delta ? ($tx_delta * 8.0 / $dt / 1000000.0) : 0.0),
            'rx_bytes'    => $cur['rx_bytes'],
            'tx_bytes'    => $cur['tx_bytes'],
            'rx_packets'  => $cur['rx_packets'],
            'tx_packets'  => $cur['tx_packets'],
            'rx_errors'   => $cur['rx_errors'],
            'tx_errors'   => $cur['tx_errors'],
            'rx_dropped'  => $cur['rx_dropped'],
            'tx_dropped'  => $cur['tx_dropped']
        ];

        $state[$logical] = $cur;
    }

    $state['_time'] = $now;
    @file_put_contents($state_file, json_encode($state), LOCK_EX);

    if ($lock) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode([
        'ok' => true,
        'interfaces' => $interfaces,
        'interval' => $dt,
        'timestamp' => $now,
        'time_string' => date('H:i:s.u')
    ]);
    exit;
}

/* ============================================================
   DEJEDE NETWORK HEALTH / SYSTEM SNAPSHOT
   ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'health') {
    $load = @file_get_contents('/proc/loadavg');
    $load_parts = $load ? preg_split('/\s+/', trim($load)) : [];
    $load1 = isset($load_parts[0]) ? (float)$load_parts[0] : 0.0;

    $meminfo = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $mem = [];
    if ($meminfo) {
        foreach ($meminfo as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) $mem[$m[1]] = (float)$m[2] * 1024;
        }
    }
    $mem_total = $mem['MemTotal'] ?? 0;
    $mem_available = $mem['MemAvailable'] ?? ($mem['MemFree'] ?? 0);
    $mem_used = max(0, $mem_total - $mem_available);
    $mem_percent = $mem_total > 0 ? ($mem_used / $mem_total * 100) : 0;

    $temp_c = null;
    foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $temp_file) {
        $raw = @file_get_contents($temp_file);
        if ($raw !== false && is_numeric(trim($raw))) {
            $v = (float)trim($raw);
            if ($v > 1000) $v /= 1000;
            if ($v > -20 && $v < 150) { $temp_c = $v; break; }
        }
    }

    // Board ini (mis. MikroTik hEX RB750Gr3 / MT7621A) tidak punya sensor suhu
    // yang di-expose OpenWrt, jadi $temp_c di atas akan selalu null. Sebagai
    // pengganti, pakai frekuensi CPU aktif (selalu tersedia di MT7621) supaya
    // card "System Load" tetap menampilkan sesuatu yang nyata, bukan "--°C".
    $cpu_freq_mhz = null;
    $freq_raw = @file_get_contents('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq');
    if ($freq_raw !== false && is_numeric(trim($freq_raw))) {
        $cpu_freq_mhz = round(((float)trim($freq_raw)) / 1000);
    }

    $gateway = '';
    $route = @shell_exec("ip -4 route show default 2>/dev/null | head -n 1");
    if ($route && preg_match('/default via ([0-9.]+)/', $route, $m)) $gateway = $m[1];

    $ping_ms = null;
    $ping = @shell_exec("ping -c 1 -W 1 1.1.1.1 2>/dev/null");
    if ($ping && preg_match('/time[=<]([0-9.]+)\s*ms/i', $ping, $m)) $ping_ms = (float)$m[1];

    $loss = null;
    $raw_mwan3 = @shell_exec('mwan3 status 2>/dev/null');
    if ($raw_mwan3 && preg_match('/(\d+)\/(\d+)\/(\d+)%\s*packet loss on IPv4/m', $raw_mwan3, $m)) $loss = (int)$m[1];

    /* --- Storage / Disk (partisi overlay, fallback ke root fs) --- */
    $storage = ['used_percent' => null, 'used_human' => '--', 'total_human' => '--', 'mount' => '-'];
    $df_raw = @shell_exec("df -P /overlay 2>/dev/null | tail -n 1");
    if (!$df_raw || !preg_match('/\S+\s+\d+\s+\d+\s+\d+\s+\d+%\s+\S+/', trim($df_raw))) {
        $df_raw = @shell_exec("df -P / 2>/dev/null | tail -n 1");
    }
    if ($df_raw && preg_match('/\S+\s+(\d+)\s+(\d+)\s+\d+\s+(\d+)%\s+(\S+)/', trim($df_raw), $m)) {
        $storage = [
            'used_percent' => (int)$m[3],
            'used_human'   => dejede_format_kb((int)$m[2]),
            'total_human'  => dejede_format_kb((int)$m[1]),
            'mount'        => $m[4],
        ];
    }

    /* --- Perangkat terhubung (DHCP leases aktif) --- */
    $connected_devices = 0;
    if (is_file('/tmp/dhcp.leases')) {
        $lease_lines = @file('/tmp/dhcp.leases', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $connected_devices = $lease_lines ? count($lease_lines) : 0;
    }

    /* --- Load balance policy & bobot per interface (dari UCI mwan3) --- */
    $lb_policy  = '';
    $lb_weights = [];
    $uci_mwan3 = @shell_exec('uci show mwan3 2>/dev/null');
    if ($uci_mwan3) {
        if (preg_match("/mwan3\.\S+\.use_policy='?([\w-]+)'?/", $uci_mwan3, $m)) {
            $lb_policy = $m[1];
        }
        $member_iface = [];
        if (preg_match_all("/mwan3\.(\w+)\.interface='?([\w-]+)'?/", $uci_mwan3, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $row) $member_iface[$row[1]] = $row[2];
        }
        if (preg_match_all("/mwan3\.(\w+)\.weight='?(\d+)'?/", $uci_mwan3, $mw, PREG_SET_ORDER)) {
            foreach ($mw as $row) {
                $iface = $member_iface[$row[1]] ?? $row[1];
                $lb_weights[$iface] = (int)$row[2];
            }
        }
    }

    $score = 100;
    if ($ping_ms === null) $score -= 35;
    elseif ($ping_ms > 150) $score -= 25;
    elseif ($ping_ms > 80) $score -= 12;
    elseif ($ping_ms > 40) $score -= 5;
    if ($loss !== null) $score -= min(40, $loss * 4);
    if ($load1 > 1.5) $score -= 10;
    if ($mem_percent > 90) $score -= 10;
    elseif ($mem_percent > 80) $score -= 5;
    if ($temp_c !== null && $temp_c > 80) $score -= 10;

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'ok' => true,
        'ping_ms' => $ping_ms,
        'gateway' => $gateway,
        'load1' => $load1,
        'ram_percent' => round($mem_percent, 1),
        'temp_c' => $temp_c,
        'cpu_freq_mhz' => $cpu_freq_mhz,
        'health_score' => max(0, min(100, $score)),
        'storage' => $storage,
        'connected_devices' => $connected_devices,
        'lb_policy' => $lb_policy !== '' ? $lb_policy : 'N/A',
        'lb_weights' => $lb_weights,
        'time_string' => date('H:i:s')
    ]);
    exit;
}

/* ============================================================
   ENDPOINT AKSI SISTEM (REBOOT / SHUTDOWN / TELEGRAM)
   ============================================================ */
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'reboot') {
        @shell_exec('reboot > /dev/null 2>&1 &');
        echo json_encode(['status' => 'success', 'message' => 'Sistem sedang melakukan reboot...']);
        exit;
    } elseif ($action === 'shutdown') {
        @shell_exec('poweroff > /dev/null 2>&1 &');
        echo json_encode(['status' => 'success', 'message' => 'Sistem dimatikan...']);
        exit;
    } elseif ($action === 'telegram_report') {
        if (empty($telegram_token) || empty($telegram_chat_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Token atau Chat ID Telegram belum diisi di file .env!']);
            exit;
        }

        $uptime = @file_get_contents('/proc/uptime');
        $sec = $uptime ? (int)explode(' ', $uptime)[0] : 0;
        $msg  = "📊 *DEJEDE - ROUTER REPORT*\n\n";
        $msg .= "⏱ Uptime: " . floor($sec / 86400) . " hari " . floor(($sec % 86400) / 3600) . " jam\n";
        $msg .= "🌐 Status: Sistem Berjalan Normal\n";
        $msg .= "🕒 Waktu: " . date('Y-m-d H:i:s');

        $url = "https://api.telegram.org/bot{$telegram_token}/sendMessage";
        $data = ['chat_id' => $telegram_chat_id, 'text' => $msg, 'parse_mode' => 'Markdown'];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $result = curl_exec($ch);
        curl_close($ch);

        echo json_encode(['status' => 'success', 'message' => 'Laporan berhasil dikirim ke Telegram!']);
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Aksi tidak dikenal.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dejede - Network Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root{
    --bg:#181818;
    --surface:rgba(255,255,255,.035);
    --surface2:rgba(255,255,255,.06);
    --border:rgba(255,255,255,.08);
    --text:#eeeeec;
    --muted:#8a8a88;
    --accent:#3584e4;
    --accent2:#62a0ea;
    --success:#2ec27e;
    --danger:#e01b24;
    --warning:#f6d32d;
    --radius:12px;
    --topbar:rgba(24,24,24,.86);
    --glow:0 0 0 transparent;
    --font:'Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,sans-serif;
}

body[data-theme="gnome"]{
    --bg:#181818;
    --surface:rgba(255,255,255,.035);
    --surface2:rgba(255,255,255,.06);
    --border:rgba(255,255,255,.07);
    --text:#eeeeec;
    --muted:#8a8a88;
    --accent:#3584e4;
    --success:#2ec27e;
    --radius:12px;
    --topbar:rgba(24,24,24,.86);
}

body[data-theme="robotic"]{
    --bg:#061117;
    --surface:rgba(8,30,40,.76);
    --surface2:rgba(12,43,54,.84);
    --border:rgba(34,230,233,.23);
    --text:#dcffff;
    --muted:#79a8ae;
    --accent:#22e6e9;
    --accent2:#79ffff;
    --success:#38f29b;
    --warning:#ffd166;
    --danger:#ff5577;
    --radius:8px;
    --topbar:rgba(4,14,19,.92);
    --glow:0 0 24px rgba(34,230,233,.10);
}

body[data-theme="cyberpunk"]{
    --bg:#0b0612;
    --surface:rgba(25,10,36,.76);
    --surface2:rgba(43,12,55,.86);
    --border:rgba(255,48,185,.25);
    --text:#fff2fd;
    --muted:#b58eaf;
    --accent:#ff30b8;
    --accent2:#8b5cf6;
    --success:#37f3a2;
    --warning:#ffe066;
    --danger:#ff496e;
    --radius:10px;
    --topbar:rgba(12,5,18,.94);
    --glow:0 0 28px rgba(255,48,185,.13);
}

body[data-theme="terminal"]{
    --bg:#050805;
    --surface:rgba(4,18,7,.88);
    --surface2:rgba(8,30,12,.94);
    --border:rgba(64,255,118,.22);
    --text:#c9ffd6;
    --muted:#68a477;
    --accent:#39ff74;
    --accent2:#9affb3;
    --success:#39ff74;
    --warning:#ffd84d;
    --danger:#ff526b;
    --radius:5px;
    --topbar:rgba(3,8,4,.95);
    --glow:0 0 22px rgba(57,255,116,.08);
    --font:'Consolas','Courier New',monospace;
}

body[data-theme="glass"]{
    --bg:#0d1420;
    --surface:rgba(255,255,255,.08);
    --surface2:rgba(255,255,255,.13);
    --border:rgba(255,255,255,.16);
    --text:#f5f8ff;
    --muted:#aab7ca;
    --accent:#72a8ff;
    --accent2:#b28cff;
    --success:#55e6a5;
    --warning:#ffd76a;
    --danger:#ff7188;
    --radius:18px;
    --topbar:rgba(13,20,32,.62);
    --glow:0 12px 40px rgba(0,0,0,.22);
}

body[data-theme="light"]{
    --bg:#f4f5f7;
    --surface:rgba(255,255,255,.90);
    --surface2:#fff;
    --border:rgba(20,30,45,.10);
    --text:#17202a;
    --muted:#687481;
    --accent:#1769e0;
    --accent2:#4389ef;
    --success:#168a59;
    --warning:#a87500;
    --danger:#c62838;
    --radius:12px;
    --topbar:rgba(255,255,255,.88);
    --glow:0 8px 25px rgba(30,50,80,.07);
}

/* TEMA DEFAULT: latar bersih/minimalis, komponen & aksen berwarna-warni */
body[data-theme="clean"]{
    --bg:#f4f6fb;
    --surface:#ffffff;
    --surface2:#ffffff;
    --border:rgba(15,23,42,.08);
    --text:#111827;
    --muted:#6b7280;
    --accent:#2563eb;
    --accent2:#7c3aed;
    --success:#16a34a;
    --warning:#d97706;
    --danger:#dc2626;
    --radius:14px;
    --topbar:rgba(255,255,255,.92);
    --glow:0 6px 22px rgba(15,23,42,.06);
}

body[data-theme="nord"]{
    --bg:#2e3440;
    --surface:rgba(216,222,233,.06);
    --surface2:rgba(216,222,233,.10);
    --border:rgba(216,222,233,.14);
    --text:#eceff4;
    --muted:#9aa5b1;
    --accent:#88c0d0;
    --accent2:#81a1c1;
    --success:#a3be8c;
    --warning:#ebcb8b;
    --danger:#bf616a;
    --radius:10px;
    --topbar:rgba(46,52,64,.9);
    --glow:0 8px 22px rgba(0,0,0,.18);
}

body[data-theme="sunset"]{
    --bg:#1a1025;
    --surface:rgba(255,255,255,.05);
    --surface2:rgba(255,255,255,.09);
    --border:rgba(255,150,90,.18);
    --text:#fff3e8;
    --muted:#c9a6a0;
    --accent:#ff7a59;
    --accent2:#ffb84d;
    --success:#5fd68a;
    --warning:#ffcf5c;
    --danger:#ff5c7a;
    --radius:14px;
    --topbar:rgba(26,16,37,.9);
    --glow:0 0 26px rgba(255,122,89,.12);
}

body[data-theme="forest"]{
    --bg:#0f1712;
    --surface:rgba(255,255,255,.04);
    --surface2:rgba(255,255,255,.08);
    --border:rgba(120,200,140,.18);
    --text:#e8f5ea;
    --muted:#8fae93;
    --accent:#4caf6d;
    --accent2:#8bd17c;
    --success:#5fe38a;
    --warning:#e3c15f;
    --danger:#e35f6f;
    --radius:12px;
    --topbar:rgba(15,23,18,.9);
    --glow:0 0 20px rgba(76,175,109,.10);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; font-family: var(--font); }
html { width: 100%; min-height: 100%; overflow-x: hidden; }
body { background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; transition: background .35s ease, color .35s ease; overflow-x: hidden; -webkit-font-smoothing: antialiased; }

.topbar { height: 40px; min-height: 40px; background: var(--topbar); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 14px; position: sticky; top: 0; z-index: 100; }
.topbar-brand { font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 6px; color: var(--text); white-space: nowrap; }
.topbar-controls { display: flex; align-items: center; gap: 5px; }

.gnome-btn { appearance: none; background: rgba(255,255,255,.06); border: 1px solid var(--border); color: var(--text); padding: 4px 9px; min-height: 26px; border-radius: 6px; font-size: 11px; font-weight: 500; cursor: pointer; transition: background .18s ease, border-color .18s ease, transform .15s ease; display: flex; align-items: center; justify-content: center; gap: 5px; white-space: nowrap; }
.gnome-btn:hover { background: rgba(255,255,255,.12); transform: translateY(-1px); }
.gnome-btn:active { transform: scale(.96); }
.gnome-btn.danger { background: rgba(224,27,36,.15); border-color: rgba(224,27,36,.3); color: #ff7b7b; }
.gnome-btn.danger:hover { background: rgba(224,27,36,.3); }
.gnome-btn.tg { background: rgba(34,158,217,.15); border-color: rgba(34,158,217,.3); color: #4ac1f7; }
.gnome-btn.tg:hover { background: rgba(34,158,217,.3); }

.main-container { padding: 16px; max-width: 1100px; margin: 0 auto; width: 100%; flex: 1; display: flex; flex-direction: column; gap: 14px; }
.header-status { display: flex; justify-content: space-between; align-items: center; background: var(--surface); border: 1px solid var(--border); padding: 10px 14px; border-radius: 10px; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); min-height: 42px; }
.uptime-info { font-size: 12px; color: var(--muted); }

.wan-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px; backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); display: flex; flex-direction: column; gap: 9px; box-shadow: var(--glow); transition: transform .22s ease, border-color .22s ease, background .22s ease; }
.card:hover { transform: translateY(-1px); border-color: color-mix(in srgb, var(--accent) 28%, var(--border)); }
.card-title { font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: space-between; min-height: 20px; }
.metric-row { display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: var(--muted); line-height: 1.2; }
.metric-val { color: var(--text); font-weight: 600; }

.speedtest-box { display: flex; flex-direction: column; align-items: center; background: rgba(0,0,0,.16); padding: 8px; border-radius: 9px; border: 1px solid var(--border); width: 100%; overflow: hidden; }
.speed-header-info { display: flex; justify-content: space-around; align-items: center; width: 100%; margin-bottom: 2px; text-align: center; }
.speed-label-top { font-size: 9px; text-transform: uppercase; color: var(--muted); letter-spacing: .55px; display: block; }
.speed-val-top { font-size: 13px; font-weight: 700; color: var(--success); line-height: 1.1; }

.gauge-container { position: relative; width: 100%; max-width: 240px; aspect-ratio: 2 / 1; margin: 0 auto; display: flex; justify-content: center; align-items: center; }
.gauge-canvas { width: 100% !important; height: auto !important; display: block; touch-action: none; }
.gauge-digital-val { text-align: center; font-size: 20px; line-height: 1; font-weight: 700; color: var(--text); margin-top: -1px; letter-spacing: -.4px; }
.gauge-digital-unit { font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: .6px; text-align: center; margin-top: 2px; }

.chart-card { width: 100%; }
.chart-wrapper { position: relative; height: 200px; width: 100%; overflow: hidden; }

.theme-picker { display: flex; align-items: center; gap: 3px; padding: 2px 5px; background: var(--surface2); border: 1px solid var(--border); border-radius: 7px; min-width: 0; }
.theme-picker select { background: transparent; border: 0; outline: 0; color: var(--text); font-size: 11px; padding: 3px; cursor: pointer; max-width: 110px; }
.theme-picker option { background: #15191d; color: #fff; }

.status-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--success); box-shadow: 0 0 7px var(--success); margin-right: 4px; flex-shrink: 0; }

/* Lampu bohlam status (berkedip saat online) */
.status-badge{ display:inline-flex; align-items:center; gap:6px; font-size:10px; font-weight:700; letter-spacing:.3px; padding:3px 9px 3px 7px; border-radius:20px; background:rgba(255,255,255,.06); border:1px solid var(--border); white-space:nowrap; }
.status-bulb{ position:relative; width:8px; height:8px; border-radius:50%; flex-shrink:0; background:var(--muted); }
.status-bulb::after{ content:''; position:absolute; inset:-4px; border-radius:50%; background:inherit; opacity:.35; filter:blur(2px); }
.status-bulb[data-state="online"]{ background:var(--success); box-shadow:0 0 6px var(--success), 0 0 14px var(--success); animation:bulbBlink 1.6s ease-in-out infinite; }
.status-bulb[data-state="offline"]{ background:var(--danger); box-shadow:0 0 6px var(--danger); opacity:.7; animation:none; }
.status-bulb[data-state="checking"]{ background:var(--warning); box-shadow:0 0 6px var(--warning); animation:bulbBlink .8s ease-in-out infinite; }
@keyframes bulbBlink{
    0%,100%{ opacity:1; filter:brightness(1.15); }
    50%{ opacity:.4; filter:brightness(.8); }
}
.theme-hint { font-size: 9px; color: var(--muted); margin-top: 2px; }

.modal-overlay { display: none; position: fixed; inset: 0; width: 100%; height: 100%; background: rgba(0,0,0,.5); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; padding: 12px; }
.modal-dialog { background: #242424; border: 1px solid var(--border); border-radius: 12px; width: 320px; max-width: 100%; padding: 20px; box-shadow: 0 15px 30px rgba(0,0,0,.4); text-align: center; }
.modal-dialog h3 { font-size: 15px; margin-bottom: 8px; }
.modal-dialog p { font-size: 12px; color: var(--muted); margin-bottom: 16px; }
.modal-buttons { display: flex; gap: 8px; }
.modal-buttons button { flex: 1; padding: 8px; border-radius: 6px; border: 1px solid var(--border); cursor: pointer; font-weight: 500; font-size: 12px; }
.btn-cancel { background: rgba(255,255,255,.04); color: var(--text); }
.btn-confirm { background: var(--accent); color: white; border: none; }

.health-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
.health-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; box-shadow: var(--glow); }
.health-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; font-weight: 600; margin-bottom: 7px; }
.health-card-head strong { font-size: 14px; color: var(--success); }
.health-items { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; }
.health-items > div { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.health-items span { font-size: 8px; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; }
.health-items b { font-size: 10px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wan-extra { opacity: .82; }
.chart-title-row { gap: 8px; }
.chart-summary { font-size: 10px; color: var(--muted); white-space: nowrap; }
.chart-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; font-size: 9px; color: var(--muted); }
.chart-stats span { padding: 5px 7px; border: 1px solid var(--border); border-radius: 6px; background: rgba(0,0,0,.10); white-space: nowrap; }
.chart-stats b { color: var(--text); font-weight: 600; }

@media (max-width:768px){
    .health-grid { grid-template-columns: repeat(auto-fit, minmax(128px, 1fr)); gap: 7px; } .health-card { padding: 8px 9px; } .health-items { gap: 5px; } .chart-stats { gap: 4px; } .chart-stats span { padding: 4px 5px; }
    .main-container { padding: 9px; gap: 9px; }
    .topbar { height: 36px; min-height: 36px; padding: 0 9px; }
    .topbar-brand { font-size: 11px; }
    .gnome-btn { padding: 3px 7px; min-height: 24px; font-size: 10px; }
    .wan-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .card { padding: 9px; gap: 7px; border-radius: 9px; }
    .chart-wrapper { height: 155px; }
}

@media (max-width:480px){
    .health-grid { grid-template-columns: repeat(auto-fit, minmax(108px, 1fr)); gap: 5px; } .health-card { padding: 7px; } .health-card-head { font-size: 9px; margin-bottom: 5px; } .health-card-head strong { font-size: 12px; } .health-items { gap: 4px; } .health-items span { font-size: 7px; } .health-items b { font-size: 8px; } .chart-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); font-size: 8px; }
    .main-container { padding: 6px; gap: 6px; }
    .topbar { height: 34px; min-height: 34px; padding: 0 7px; }
    .chart-wrapper { height: 135px; }
    .status-badge { padding: 2px 7px 2px 5px; gap: 4px; font-size: 9px; }
    .status-bulb { width: 6px; height: 6px; }
}

/* Mobile sangat kecil / tampilan lebih tipis (thin) */
@media (max-width:380px){
    .main-container { padding: 5px; gap: 5px; }
    .topbar { height: 32px; min-height: 32px; padding: 0 6px; }
    .topbar-brand { font-size: 10px; gap: 4px; }
    .gnome-btn { padding: 2px 6px; min-height: 22px; font-size: 9px; }
    .theme-picker select { max-width: 78px; font-size: 10px; }
    .wan-grid { gap: 5px; }
    .card { padding: 6px; gap: 5px; border-radius: 8px; }
    .card-title { font-size: 11px; min-height: 16px; }
    .metric-row { font-size: 9px; }
    .speedtest-box { padding: 5px; gap: 1px; }
    .speed-val-top { font-size: 11px; }
    .gauge-digital-val { font-size: 16px; }
    .health-grid { grid-template-columns: repeat(auto-fit, minmax(92px, 1fr)); gap: 4px; }
    .health-card { padding: 5px; }
    .chart-wrapper { height: 110px; }
    .status-badge { padding: 1px 6px 1px 4px; font-size: 8px; }
}

/* Layar besar (desktop) - tampilan lebih maksimal & lega */
@media (min-width:1200px){
    .main-container { max-width: 1480px; padding: 22px; gap: 18px; }
    .topbar { height: 48px; min-height: 48px; padding: 0 22px; }
    .topbar-brand { font-size: 15px; }
    .gnome-btn { padding: 6px 14px; min-height: 32px; font-size: 12px; }
    .header-status { padding: 14px 20px; }
    .health-grid { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
    .health-card { padding: 16px 18px; }
    .health-items { gap: 10px; }
    .wan-grid { gap: 20px; }
    .card { padding: 20px; gap: 14px; }
    .card-title { font-size: 15px; }
    .gauge-container { max-width: 300px; }
    .gauge-digital-val { font-size: 26px; }
    .chart-wrapper { height: 280px; }
    .chart-stats span { padding: 7px 10px; font-size: 11px; }
}

@media (min-width:1600px){
    .main-container { max-width: 1700px; }
    .wan-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
</head>
<body>

    <div class="topbar">
        <div class="topbar-brand">
            <span>⚙️</span> Dejede Control Center
        </div>
        <div class="topbar-controls">
            <div class="theme-picker" title="Pilih tema dashboard">
                <span>🎨</span>
                <select id="themeSelect" onchange="setTheme(this.value)" aria-label="Pilih tema">
                    <option value="clean">CLEAN</option>
                    <option value="light">LIGHT</option>
                    <option value="gnome">GNOME</option>
                    <option value="glass">GLASS</option>
                    <option value="nord">NORD</option>
                    <option value="sunset">SUNSET</option>
                    <option value="forest">FOREST</option>
                    <option value="robotic">ROBOTIC</option>
                    <option value="cyberpunk">CYBERPUNK</option>
                    <option value="terminal">TERMINAL</option>
                </select>
            </div>
            <button class="gnome-btn tg" onclick="sendTelegramReport()" title="Kirim Laporan">📤 Telegram</button>
            <button class="gnome-btn danger" onclick="confirmAction('reboot')" title="Reboot">🔄</button>
            <button class="gnome-btn danger" onclick="confirmAction('shutdown')" title="Shutdown">⏻</button>
        </div>
    </div>

    <div class="main-container">
        <div class="header-status">
            <div>
                <h2 style="font-size: 15px; font-weight: 600;">System Overview</h2>
                <span class="uptime-info" id="uptimeText">Memuat informasi...</span><div class="theme-hint"><span class="status-dot"></span><span id="themeName">CLEAN</span> interface</div>
            </div>
            <div id="lastUpdate" style="font-size: 11px; color: var(--muted);">Sync...</div>
        </div>
        
        <div class="health-grid">
            <div class="health-card">
                <div class="health-card-head"><span>🛡 Network Health</span><strong id="healthScore">--</strong></div>
                <div class="health-items">
                    <div><span>Latency</span><b id="pingValue">-- ms</b></div>
                    <div><span>Packet Loss</span><b id="healthLoss">--</b></div>
                    <div><span>Gateway</span><b id="gatewayValue">--</b></div>
                </div>
            </div>
            <div class="health-card">
                <div class="health-card-head"><span>⚙ System Load</span><strong id="tempValue">--</strong></div>
                <div class="health-items">
                    <div><span>CPU Load</span><b id="cpuLoadValue">--</b></div>
                    <div><span>RAM</span><b id="ramValue">--</b></div>
                    <div><span>Monitor</span><b id="healthSync">WAIT</b></div>
                </div>
            </div>
            <div class="health-card">
                <div class="health-card-head"><span>💾 Storage</span><strong id="storagePercent">--%</strong></div>
                <div class="health-items">
                    <div><span>Terpakai</span><b id="storageUsed">--</b></div>
                    <div><span>Total</span><b id="storageTotal">--</b></div>
                    <div><span>Partisi</span><b id="storageMount">--</b></div>
                </div>
            </div>
            <div class="health-card">
                <div class="health-card-head"><span>📶 Perangkat</span><strong id="deviceCount">--</strong></div>
                <div class="health-items">
                    <div><span>DHCP Aktif</span><b id="deviceCountText">--</b></div>
                    <div><span>Interface</span><b>br-lan</b></div>
                    <div><span>Sumber</span><b>dhcp.leases</b></div>
                </div>
            </div>
            <div class="health-card">
                <div class="health-card-head"><span>⚖ Load Balance</span><strong id="lbPolicy">--</strong></div>
                <div class="health-items">
                    <div><span>WAN1 Weight</span><b id="lbWan1Weight">--</b></div>
                    <div><span>WAN2 Weight</span><b id="lbWan2Weight">--</b></div>
                    <div><span>Mode</span><b id="lbMode">--</b></div>
                </div>
            </div>
        </div>

        <!-- WAN 1 (wan1) & WAN 2 (wan2) -->
        <div class="wan-grid">
            <!-- WAN 1 Card -->
            <div class="card">
                <div class="card-title">
                    <span>🌐 WAN 1 (wan1)</span>
                    <span class="status-badge">
                        <span class="status-bulb" id="wan1Bulb" data-state="checking"></span>
                        <span id="wan1Status">CHECKING</span>
                    </span>
                </div>
                <div class="metric-row"><span>Packet Loss:</span> <span class="metric-val" id="wan1Loss">0%</span></div>
                <div class="metric-row wan-extra"><span>RX/TX Packets:</span> <span class="metric-val" id="wan1Packets">-- / --</span></div>
                
                <div class="speedtest-box">
                    <div class="speed-header-info">
                        <div>
                            <span class="speed-label-top">⬇ Download</span>
                            <span class="speed-val-top" id="wan1RxText">0.00 Mbps</span>
                        </div>
                        <div>
                            <span class="speed-label-top">⬆ Upload</span>
                            <span class="speed-val-top" id="wan1TxText" style="color: var(--accent);">0.00 Mbps</span>
                        </div>
                    </div>
                    <div class="gauge-container">
                        <canvas id="gaugeWan1" class="gauge-canvas" width="220" height="115"></canvas>
                    </div>
                    <div class="gauge-digital-val" id="wan1Digital">0.00</div>
                    <div class="gauge-digital-unit">Mbps (Download Speed)</div>
                </div>
            </div>

            <!-- WAN 2 Card -->
            <div class="card">
                <div class="card-title">
                    <span>🌐 WAN 2 (wan2)</span>
                    <span class="status-badge">
                        <span class="status-bulb" id="wan2Bulb" data-state="checking"></span>
                        <span id="wan2Status">CHECKING</span>
                    </span>
                </div>
                <div class="metric-row"><span>Packet Loss:</span> <span class="metric-val" id="wan2Loss">0%</span></div>
                <div class="metric-row wan-extra"><span>RX/TX Packets:</span> <span class="metric-val" id="wan2Packets">-- / --</span></div>
                
                <div class="speedtest-box">
                    <div class="speed-header-info">
                        <div>
                            <span class="speed-label-top">⬇ Download</span>
                            <span class="speed-val-top" id="wan2RxText">0.00 Mbps</span>
                        </div>
                        <div>
                            <span class="speed-label-top">⬆ Upload</span>
                            <span class="speed-val-top" id="wan2TxText" style="color: var(--accent);">0.00 Mbps</span>
                        </div>
                    </div>
                    <div class="gauge-container">
                        <canvas id="gaugeWan2" class="gauge-canvas" width="220" height="115"></canvas>
                    </div>
                    <div class="gauge-digital-val" id="wan2Digital">0.00</div>
                    <div class="gauge-digital-unit">Mbps (Download Speed)</div>
                </div>
            </div>
        </div>

        <!-- Grafik Trafik (Bridge LAN) -->
        <div class="card chart-card">
            <div class="card-title chart-title-row">
                <span>📈 Grafik Trafik Real-Time (Bridge LAN)</span>
                <span class="chart-summary" id="lanTotalText">Total: 0 B</span>
            </div>
            <div class="chart-stats">
                <span>RX <b id="lanRxNow">0.00</b> Mbps</span>
                <span>TX <b id="lanTxNow">0.00</b> Mbps</span>
                <span>Peak <b id="lanPeak">0.00</b> Mbps</span>
                <span>Avg <b id="lanAvg">0.00</b> Mbps</span>
            </div>
            <div class="chart-wrapper">
                <canvas id="trafficChart"></canvas>
            </div>
        </div>
    </div>

    <div id="actionModal" class="modal-overlay">
        <div class="modal-dialog">
            <h3 id="modalTitle">Konfirmasi Sistem</h3>
            <p id="modalDesc">Apakah Anda yakin ingin melanjutkan tindakan ini?</p>
            <div class="modal-buttons">
                <button class="btn-cancel" onclick="closeModal()">Batal</button>
                <button class="btn-confirm" onclick="executeAction()">Lanjutkan</button>
            </div>
        </div>
    </div>

    <script>
        // Konfigurasi dari config.php (server-side) diteruskan ke JS
        const DEJEDE_CONFIG = <?php echo json_encode([
            'refreshIntervalMs' => $refresh_interval_ms,
            'defaultTheme'      => $default_theme,
        ], JSON_UNESCAPED_SLASHES); ?>;

        function getCSS(name){return getComputedStyle(document.body).getPropertyValue(name).trim();}
        function setTheme(theme){
            const allowed=['clean','robotic','cyberpunk','terminal','glass','gnome','light','nord','sunset','forest'];
            if(!allowed.includes(theme)) theme=DEJEDE_CONFIG.defaultTheme || 'clean';
            document.body.dataset.theme=theme;
            localStorage.setItem('dejede-theme',theme);
            const s=document.getElementById('themeSelect'); if(s)s.value=theme;
            const n=document.getElementById('themeName'); if(n)n.textContent=theme.toUpperCase();
            if(typeof trafficChart!=='undefined'){
                trafficChart.data.datasets[0].borderColor=getCSS('--success');
                trafficChart.data.datasets[1].borderColor=getCSS('--accent');
                trafficChart.options.scales.x.ticks.color=getCSS('--muted');
                trafficChart.options.scales.y.ticks.color=getCSS('--muted');
                trafficChart.options.plugins.legend.labels.color=getCSS('--text');
                trafficChart.update('none');
            }
        }
        (function(){
            const t=localStorage.getItem('dejede-theme')||DEJEDE_CONFIG.defaultTheme||'clean';
            document.body.dataset.theme=t;
            window.addEventListener('DOMContentLoaded',function(){
                setTheme(t);
            });
        })();

        let currentActionType = '';
        let healthBusy = false;
        let gaugeAnim = {
            wan1: { current: 0, target: 0 },
            wan2: { current: 0, target: 0 }
        };

        function speedToPercent(mbps) {
            const maxSpeed = 200;
            const clamped = Math.min(Math.max(mbps, 0), maxSpeed);
            return Math.pow(clamped / maxSpeed, 0.6);
        }

        function drawGauge(canvasId, currentMbps) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;

            const logicalWidth = 220;
            const logicalHeight = 115;
            const dpr = Math.max(1, Math.min(window.devicePixelRatio || 1, 3));
            const backingWidth = Math.round(logicalWidth * dpr);
            const backingHeight = Math.round(logicalHeight * dpr);

            if (canvas.width !== backingWidth || canvas.height !== backingHeight) {
                canvas.width = backingWidth;
                canvas.height = backingHeight;
            }

            const ctx = canvas.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.clearRect(0, 0, logicalWidth, logicalHeight);

            const cx = 110, cy = 105, radius = 80;

            ctx.beginPath();
            ctx.arc(cx, cy, radius, Math.PI, 2 * Math.PI);
            ctx.lineWidth = 10;
            ctx.strokeStyle = '#2a2a2a';
            ctx.stroke();

            let percent = speedToPercent(currentMbps);
            const activeAngle = Math.PI + (percent * Math.PI);
            
            ctx.beginPath();
            ctx.arc(cx, cy, radius, Math.PI, activeAngle);
            ctx.lineWidth = 10;
            ctx.strokeStyle = getCSS('--success') || '#2ec27e';
            ctx.stroke();

            const scaleMarks = [0, 5, 10, 20, 50, 100, 150, 200];
            scaleMarks.forEach(val => {
                let p = speedToPercent(val);
                let angle = Math.PI + (p * Math.PI);

                let innerR = radius - 15;
                let outerR = radius - 5;
                let x1 = cx + innerR * Math.cos(angle);
                let y1 = cy + innerR * Math.sin(angle);
                let x2 = cx + outerR * Math.cos(angle);
                let y2 = cy + outerR * Math.sin(angle);

                ctx.beginPath();
                ctx.moveTo(x1, y1);
                ctx.lineTo(x2, y2);
                ctx.lineWidth = (val === 0 || val === 50 || val === 100 || val === 200) ? 1.5 : 1;
                ctx.strokeStyle = getCSS('--muted') || '#7a7a7a';
                ctx.stroke();

                let textR = radius - 26;
                let tx = cx + textR * Math.cos(angle);
                let ty = cy + textR * Math.sin(angle);
                
                ctx.fillStyle = getCSS('--muted') || '#9a9996';
                ctx.font = '9px Segoe UI, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(val, tx, ty);
            });

            ctx.save();
            ctx.translate(cx, cy);
            ctx.rotate(activeAngle);
            ctx.beginPath();
            ctx.moveTo(0, -2.5);
            ctx.lineTo(radius - 8, 0);
            ctx.lineTo(0, 2.5);
            ctx.closePath();
            ctx.fillStyle = '#ffffff';
            ctx.fill();
            ctx.restore();

            ctx.beginPath();
            ctx.arc(cx, cy, 5, 0, 2 * Math.PI);
            ctx.fillStyle = getCSS('--accent') || '#3584e4';
            ctx.fill();
        }

        function animateGauges() {
            gaugeAnim.wan1.current += (gaugeAnim.wan1.target - gaugeAnim.wan1.current) * 0.2;
            gaugeAnim.wan2.current += (gaugeAnim.wan2.target - gaugeAnim.wan2.current) * 0.2;

            drawGauge('gaugeWan1', gaugeAnim.wan1.current);
            drawGauge('gaugeWan2', gaugeAnim.wan2.current);

            requestAnimationFrame(animateGauges);
        }
        requestAnimationFrame(animateGauges);

        const ctxChart = document.getElementById('trafficChart').getContext('2d');
        const trafficChart = new Chart(ctxChart, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        label: 'Download (RX) Mbps',
                        borderColor: getCSS('--success'),
                        backgroundColor: 'rgba(46, 194, 126, 0.1)',
                        data: [],
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Upload (TX) Mbps',
                        borderColor: getCSS('--accent'),
                        backgroundColor: 'rgba(53, 132, 228, 0.1)',
                        data: [],
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: getCSS('--muted'), font: { size: 10 } } },
                    y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: getCSS('--muted'), font: { size: 10 } }, beginAtZero: true }
                },
                plugins: { legend: { labels: { color: getCSS('--text'), font: { size: 11 } } } }
            }
        });

        function confirmAction(type) {
            currentActionType = type;
            const modal = document.getElementById('actionModal');
            document.getElementById('modalTitle').textContent = type === 'reboot' ? 'Reboot Sistem' : 'Matikan Perangkat';
            document.getElementById('modalDesc').textContent = type === 'reboot' ? 'Perangkat router akan dimulai ulang sekarang.' : 'Perangkat akan dimatikan.';
            modal.style.display = 'flex';
        }

        function closeModal() { document.getElementById('actionModal').style.display = 'none'; }

        async function executeAction() {
            closeModal();
            let formData = new FormData();
            formData.append('action', currentActionType);
            let res = await fetch('', { method: 'POST', body: formData });
            let data = await res.json();
            alert(data.message);
        }

        async function sendTelegramReport() {
            let formData = new FormData();
            formData.append('action', 'telegram_report');
            let res = await fetch('', { method: 'POST', body: formData });
            let data = await res.json();
            alert(data.message);
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function setHealthText(id,value,good=true){
            const el=document.getElementById(id); if(!el)return;
            el.textContent=value; el.style.color=good?'var(--text)':'var(--danger)';
        }

        async function fetchHealthData(){
            if(healthBusy)return; healthBusy=true;
            try{
                const res=await fetch('?ajax=health',{cache:'no-store'}), h=await res.json();
                if(!h.ok)return;
                const score=Number(h.health_score), se=document.getElementById('healthScore');
                if(se){se.textContent=Number.isFinite(score)?score+'/100':'--';se.style.color=score>=85?'var(--success)':(score>=60?'var(--warning)':'var(--danger)')}
                const ping=h.ping_ms===null?null:Number(h.ping_ms);
                setHealthText('pingValue',ping===null?'TIMEOUT':ping.toFixed(1)+' ms',ping!==null&&ping<=100);
                setHealthText('gatewayValue',h.gateway||'N/A',!!h.gateway);
                const load=Number(h.load1)||0, ram=Number(h.ram_percent)||0;
                setHealthText('cpuLoadValue',load.toFixed(2),load<1.5);
                setHealthText('ramValue',ram.toFixed(1)+'%',ram<80);
                const temp=h.temp_c===null?null:Number(h.temp_c);
                const freq=(h.cpu_freq_mhz===null||h.cpu_freq_mhz===undefined)?null:Number(h.cpu_freq_mhz);
                const tempEl=document.getElementById('tempValue');
                if(tempEl){
                    if(temp!==null){
                        tempEl.textContent=temp.toFixed(0)+'°C';
                        tempEl.style.color=temp<75?'var(--success)':'var(--danger)';
                    } else if(freq!==null){
                        tempEl.textContent=freq+' MHz';
                        tempEl.style.color='var(--text)';
                    } else {
                        // Board tanpa sensor suhu maupun cpufreq (mis. RB750Gr3/MT7621
                        // tanpa scaling) -> tampilkan status beban CPU dari load average,
                        // ini selalu tersedia di semua perangkat Linux/OpenWrt.
                        let label='NORMAL', color='var(--success)';
                        if(load>=2.0){ label='TINGGI'; color='var(--danger)'; }
                        else if(load>=1.0){ label='SEDANG'; color='var(--warning)'; }
                        tempEl.textContent=label;
                        tempEl.style.color=color;
                    }
                }
                const sync=document.getElementById('healthSync');
                if(sync){sync.textContent=h.time_string||'OK';sync.style.color='var(--success)'}

                // Storage
                const st=h.storage||{};
                const stPct=st.used_percent===null||st.used_percent===undefined?null:Number(st.used_percent);
                const spEl=document.getElementById('storagePercent');
                if(spEl){spEl.textContent=stPct===null?'--%':stPct+'%';spEl.style.color=stPct===null?'var(--text)':(stPct<80?'var(--success)':(stPct<92?'var(--warning)':'var(--danger)'))}
                setHealthText('storageUsed', st.used_human||'--');
                setHealthText('storageTotal', st.total_human||'--');
                setHealthText('storageMount', st.mount||'-');

                // Perangkat terhubung
                const devCount=Number(h.connected_devices)||0;
                const dcEl=document.getElementById('deviceCount');
                if(dcEl)dcEl.textContent=devCount;
                setHealthText('deviceCountText', devCount+' perangkat');

                // Load balance policy
                const w=h.lb_weights||{};
                const w1=w.wan1!==undefined?w.wan1:null, w2=w.wan2!==undefined?w.wan2:null;
                const lbEl=document.getElementById('lbPolicy');
                if(lbEl)lbEl.textContent=(h.lb_policy||'N/A').toString().toUpperCase();
                setHealthText('lbWan1Weight', w1===null?'--':w1);
                setHealthText('lbWan2Weight', w2===null?'--':w2);
                let mode='--';
                if(w1!==null && w2!==null){
                    mode = (w1===w2) ? 'Seimbang' : (w1>w2 ? 'Prioritas WAN1' : 'Prioritas WAN2');
                }
                setHealthText('lbMode', mode);
            }catch(e){
                const sync=document.getElementById('healthSync');
                if(sync){sync.textContent='ERROR';sync.style.color='var(--danger)'}
            }finally{healthBusy=false}
        }

        async function fetchDashboardData() {
            try {
                const statusRes = await fetch('?ajax=status', { cache: 'no-store' });
                const data = await statusRes.json();

                document.getElementById('uptimeText').textContent = 'Uptime: ' + data.uptime;
                document.getElementById('lastUpdate').textContent = 'Sync: ' + data.time_string;

                if (data.interfaces && data.interfaces.length > 0) {
                    const applyStatus = (prefix, iface) => {
                        const label = document.getElementById(prefix + 'Status');
                        const bulb  = document.getElementById(prefix + 'Bulb');
                        const state = iface.status === 'online' ? 'online' : 'offline';
                        if (label) label.textContent = iface.status.toUpperCase();
                        if (bulb) bulb.dataset.state = state;
                    };
                    data.interfaces.forEach(iface => {
                        if (iface.iface === 'wan1') {
                            document.getElementById('wan1Loss').textContent = iface.loss_now + '%';
                            applyStatus('wan1', iface);
                        } 
                        else if (iface.iface === 'wan2') {
                            document.getElementById('wan2Loss').textContent = iface.loss_now + '%';
                            applyStatus('wan2', iface);
                        }
                    });
                }

                const nmRes = await fetch('?ajax=netmonitor', { cache: 'no-store' });
                const nm = await nmRes.json();

                if (!nm.ok || !nm.interfaces) return;

                const wan1 = nm.interfaces.wan1 || {};
                const wan2 = nm.interfaces.wan2 || {};
                const lan  = nm.interfaces['br-lan'] || {};

                const wan1Rx = Math.max(0, Number(wan1.rx_mbps) || 0);
                const wan1Tx = Math.max(0, Number(wan1.tx_mbps) || 0);
                const wan2Rx = Math.max(0, Number(wan2.rx_mbps) || 0);
                const wan2Tx = Math.max(0, Number(wan2.tx_mbps) || 0);

                document.getElementById('wan1RxText').textContent = wan1Rx.toFixed(2) + ' Mbps';
                document.getElementById('wan1TxText').textContent = wan1Tx.toFixed(2) + ' Mbps';
                document.getElementById('wan1Digital').textContent = wan1Rx.toFixed(2);
                gaugeAnim.wan1.target = wan1Rx;
                document.getElementById('wan1Packets').textContent = (Number(wan1.rx_packets)||0).toLocaleString()+' / '+(Number(wan1.tx_packets)||0).toLocaleString();

                document.getElementById('wan2RxText').textContent = wan2Rx.toFixed(2) + ' Mbps';
                document.getElementById('wan2TxText').textContent = wan2Tx.toFixed(2) + ' Mbps';
                document.getElementById('wan2Digital').textContent = wan2Rx.toFixed(2);
                gaugeAnim.wan2.target = wan2Rx;
                document.getElementById('wan2Packets').textContent = (Number(wan2.rx_packets)||0).toLocaleString()+' / '+(Number(wan2.tx_packets)||0).toLocaleString();

                if (lan.rx_bytes !== undefined) {
                    document.getElementById('lanTotalText').textContent = 'Total: ' + formatBytes(Number(lan.rx_bytes) || 0);
                }

                const lanRx = Math.max(0, Number(lan.rx_mbps) || 0);
                const lanTx = Math.max(0, Number(lan.tx_mbps) || 0);

                if (trafficChart.data.labels.length > 30) {
                    trafficChart.data.labels.shift();
                    trafficChart.data.datasets[0].data.shift();
                    trafficChart.data.datasets[1].data.shift();
                }

                trafficChart.data.labels.push(nm.time_string || new Date().toLocaleTimeString());
                trafficChart.data.datasets[0].data.push(lanRx);
                trafficChart.data.datasets[1].data.push(lanTx);
                
                const combined = trafficChart.data.datasets[0].data.concat(trafficChart.data.datasets[1].data);
                const peak = combined.length ? Math.max(...combined) : 0;
                const avg = combined.length ? combined.reduce((a,b)=>a+b,0)/combined.length : 0;
                
                document.getElementById('lanRxNow').textContent = lanRx.toFixed(2);
                document.getElementById('lanTxNow').textContent = lanTx.toFixed(2);
                document.getElementById('lanPeak').textContent = peak.toFixed(2);
                document.getElementById('lanAvg').textContent = avg.toFixed(2);
                trafficChart.update('none');

            } catch (e) {
                console.error('Gagal memuat DEJEDE NetMonitor:', e);
            }
        }

        let dashboardBusy = false;
        async function dashboardLoop() {
            if (dashboardBusy) return;
            dashboardBusy = true;
            try { await fetchDashboardData(); } finally { dashboardBusy = false; }
        }
        
        fetchDashboardData();
        fetchHealthData();
        setInterval(dashboardLoop, 300);
        setInterval(fetchHealthData, Math.max(1000, DEJEDE_CONFIG.refreshIntervalMs || 4000));
    </script>
</body>
</html>
