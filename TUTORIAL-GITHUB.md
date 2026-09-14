# Tutorial GitHub DEJEDE Network Dashboard

## 1. Buat repository

Di GitHub pilih **New repository**.

Contoh nama:

```text
dejede-network-dashboard
```

Pilih Public jika ingin dibagikan.

## 2. Upload struktur

Upload file dengan struktur:

```text
dejede-network-dashboard/
├── README.md
├── TUTORIAL-GITHUB.md
├── install.sh
├── uninstall.sh
└── luci/
    ├── usr/share/luci/menu.d/
    │   └── dejede-network-dashboard.json
    └── www/luci-static/resources/view/dejede/
        └── mwan3dash.js
```

## 3. Install dari router

```sh
cd /tmp
git clone https://github.com/USERNAME/dejede-network-dashboard.git
cd dejede-network-dashboard
chmod +x install.sh
./install.sh
rm -f /tmp/luci-indexcache*
```

## 4. Pastikan dashboard utama ada

```sh
ls -l /www/mwan3dash/
```

Minimal:

```text
config.php
index.php
```

## 5. Buka LuCI

Refresh browser, lalu:

```text
Services
└── DEJEDE Network Dashboard
```

Klik menu tersebut. Halaman yang tampil adalah dashboard asli dari:

```text
/www/mwan3dash/index.php
```

## 6. Update dari GitHub

```sh
cd /tmp/dejede-network-dashboard
git pull
./install.sh
rm -f /tmp/luci-indexcache*
```

## 7. Catatan penting

Jangan upload `config.php` ke GitHub apabila file tersebut berisi password, token, API key, atau konfigurasi pribadi.

Repository ini adalah **loader LuCI**, bukan source dashboard PHP.
