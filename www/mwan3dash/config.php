<?php
/**
 * ============================================================
 *  DEJEDE NETWORK DASHBOARD - KONFIGURASI
 * ============================================================
 *  File ini WAJIB mengembalikan (return) sebuah array asosiatif,
 *  karena index.php membacanya lewat:
 *      $cfg = @include $config_path;
 *  Jika hanya berisi variabel biasa (tanpa "return"), pengaturan
 *  di sini TIDAK akan pernah terbaca oleh index.php.
 * ============================================================
 */
return [

    // Interval refresh data dashboard (dalam milidetik)
    'refresh_interval_ms' => 4000,

    // Tema tampilan default saat pertama kali dibuka (sebelum user memilih sendiri)
    // Pilihan bawaan: clean, robotic, cyberpunk, terminal, glass, gnome, light,
    //                 nord, sunset, forest
    'default_theme' => 'clean',

    // Label yang ditampilkan di UI untuk tiap interface LOGIKAL
    'interface_labels' => [
        'wan1'   => 'WAN 1',
        'wan2'   => 'WAN 2',
        'br-lan' => 'Bridge LAN'
    ],

    // Pemetaan interface LOGIKAL (dipakai mwan3 & UI) ke nama device
    // fisik yang muncul di /proc/net/dev (sesuai file /etc/config/network).
    //   - wan1   => device 'wan'    (config interface 'wan1' di /etc/config/network)
    //   - wan2   => device 'lan2'   (config interface 'wan2' di /etc/config/network)
    //   - br-lan => device 'br-lan' (bridge LAN, ports lan3/lan4/lan5)
    'interface_map' => [
        'wan1'   => 'wan',
        'wan2'   => 'lan2',
        'br-lan' => 'br-lan'
    ],
];
