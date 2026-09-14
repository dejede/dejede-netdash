# ⚡ DEJEDE Network Dashboard — OpenWrt 25.12.x

![OpenWrt](https://img.shields.io/badge/OpenWrt-25.x%20--%2025.x-1b4b34?logo=openwrt&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.x-777bb4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-blue)
![Status](https://img.shields.io/badge/status-stable-brightgreen)
![Auth](https://img.shields.io/badge/auth-session%20%2B%20CSRF-important)


Loader LuCI sederhana untuk menampilkan dashboard PHP yang sudah ada di `/www/mwan3dash/index.php`.
## Konsep

Project ini **tidak membuat ulang dashboard**. LuCI hanya menyediakan menu:

`Services → DEJEDE Network Dashboard`

Saat menu dibuka, LuCI memuat:

`/mwan3dash/index.php`

Dashboard utama tetap berada di `/www/mwan3dash/`.

## Persyaratan

- OpenWrt 25.12.x
- LuCI
- Dashboard DEJEDE sudah terpasang:
  - `/www/mwan3dash/index.php`
  - `/www/mwan3dash/config.php`

> `index.php` dan `config.php` tidak disertakan dalam repository ini.

## Instalasi

### Clone langsung di router

```sh
cd /tmp
git clone https://github.com/USERNAME/dejede-network-dashboard.git
cd dejede-network-dashboard
chmod +x install.sh
./install.sh
rm -f /tmp/luci-indexcache*
```

Ganti `USERNAME` dengan username GitHub.

### Setelah instalasi

Refresh LuCI dengan `Ctrl+F5`.

Menu:

```text
Services
├── Dejede Explorer
├── DEJEDE Network Dashboard
└── Terminal
```

## Cara kerja

```text
LuCI
  ↓
DEJEDE Network Dashboard
  ↓
/mwan3dash/index.php
  ↓
Dashboard DEJEDE yang sudah ada
```

Loader menggunakan view LuCI dan iframe. Tidak ada PHP yang dipindahkan atau ditulis ulang.

## Yang tidak disentuh

Project ini tidak:

- mengubah `/www/mwan3dash/index.php`
- mengubah `/www/mwan3dash/config.php`
- menyalin source dashboard
- membuat backend NetMonitor baru
- membuat controller Lua
- menggunakan `poll`
- menggunakan ES module `import`
- mengubah Dejede Explorer

## Cek dashboard sumber

```sh
ls -l /www/mwan3dash/
```

Harus terlihat minimal:

```text
config.php
index.php
```

## Update

```sh
cd /tmp/dejede-network-dashboard
git pull
./install.sh
rm -f /tmp/luci-indexcache*
```

## Uninstall

```sh
chmod +x uninstall.sh
./uninstall.sh
```

Uninstall hanya menghapus loader LuCI dan tidak menghapus `/www/mwan3dash/`.
