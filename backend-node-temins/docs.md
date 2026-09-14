# Dokumentasi  Backend Node.js Temins IoT

Dokumentasi ini menyajikan panduan komprehensif mengenai arsitektur, struktur file, fungsi-fungsi program, skema data, endpoint API, serta alur kerja sistem backend **Temins IoT** yang berlokasi di:
`/home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins`

---

## Daftar Isi
1. [Gambaran Umum Sistem](#1-gambaran-umum-sistem)
2. [Arsitektur & Teknologi](#2-arsitektur--teknologi)
3. [Struktur Direktori & File](#3-struktur-direktori--file)
4. [Konfigurasi Lingkungan & Basis Data](#4-konfigurasi-lingkungan--basis-data)
5. [Keamanan & Autentikasi](#5-keamanan--autentikasi)
6. [Dokumentasi Lengkap File & Fungsi](#6-dokumentasi-lengkap-file--fungsi)
   - [Root File](#61-root-file)
   - [Konfigurasi & Middleware](#62-konfigurasi--middleware)
   - [Migrasi & Utilitas](#63-migrasi--utilitas)
   - [Rute & Controller Otentikasi (Auth)](#64-rute--controller-otentikasi-auth)
   - [Rute & Controller User](#65-rute--controller-user)
   - [Rute & Controller AWS (Automatic Weather Station)](#66-rute--controller-aws-automatic-weather-station)
   - [Rute & Controller AWLR (Automatic Water Level Recorder)](#67-rute--controller-awlr-automatic-water-level-recorder)
   - [Rute & Controller Smart Farm (SF)](#68-rute--controller-smart-farm-sf)
   - [Rute & Controller Instansi](#69-rute--controller-instansi)
   - [Rute & Controller Admin](#610-rute--controller-admin)
   - [Rute & Controller EWS (Early Warning System)](#611-rute--controller-ews-early-warning-system)
7. [Matriks Endpoint API](#7-matriks-endpoint-api)
8. [Integrasi IoT & Protokol MQTT](#8-integrasi-iot--protokol-mqtt)
9. [Panduan Instalasi & Pengoperasian](#9-panduan-instalasi--pengoperasian)
10. [Catatan Pemeliharaan & Rekomendasi](#10-catatan-pemeliharaan--rekomendasi)

---

## 1. Gambaran Umum Sistem

Sistem backend ini merupakan implementasi RESTful API dan IoT Gateway berbasis Node.js yang dirancang untuk platform **Temins IoT**. Sistem ini mengelola data telemetri dan kontrol perangkat keras untuk 3 kategori instrumen utama:
1. **AWS (Automatic Weather Station)**: Stasiun pemantau cuaca otomatis (suhu, kelembapan, kecepatan & arah angin, curah hujan, radiasi matahari, dll).
2. **AWLR (Automatic Water Level Recorder)**: Alat pemantau tinggi muka air otomatis pada sungai, saluran irigasi, atau bendungan.
3. **SF (Smart Farm)**: Pemantau kondisi pertanian cerdas (kelembapan tanah, suhu tanah, pH, NPK/Nitrogen-Fosfor-Kalium, EC, TDS, salinitas).
4. **EWS (Early Warning System)**: Sistem peringatan dini berbasis sirine, perintah suara/audio jarak jauh via MQTT, dan upload audio OTA (*Over-The-Air*).

Backend ini juga berfungsi sebagai API pengganti/modernisasi dari arsitektur warisan (legacy) PHP (terlihat dari penamaan rute seperti `login.php`, `ds.php`, `history.php`, `dev.php`, dll), sehingga aplikasi antarmuka pengguna (Frontend Web & Aplikasi Mobile) dapat bermigrasi ke Node.js secara mulus tanpa mengubah endpoint URL lama.

---

## 2. Arsitektur & Teknologi

Backend dibangun menggunakan pendekatan modular berbasis arsitektur **Express MVC-like** (Model diwakili oleh query langsung MySQL2 Promise, View diwakili oleh JSON Response, Controller menangani logika bisnis, dan Routes menangani pemetaan URL).

### Stack Teknologi Utama:
| Komponen | Pustaka / Teknologi | Deskripsi |
| :--- | :--- | :--- |
| **Runtime** | Node.js (CommonJS) | Lingkungan eksekusi Javascript di sisi server |
| **Web Framework** | `express` (^5.2.1) | Framework minimalis untuk routing dan HTTP server |
| **Database Client** | `mysql2` (^3.23.2) | Driver koneksi MySQL menggunakan pool promise-based |
| **Autentikasi** | `jsonwebtoken` (^9.0.3) | Pembuatan dan verifikasi token akses (JWT) |
| **Enkripsi Sandi** | `bcryptjs` (^3.0.3) | Hashing dan verifikasi kata sandi dengan salt |
| **IoT Protocol** | `mqtt` (^5.15.2) | Klien protokol MQTT (mendukung TCP & WebSocket) |
| **File Upload** | `multer` (^2.3.0) | Middleware penanganan upload multipart/form-data |
| **Email SMTP** | `nodemailer` (^9.0.4) | Pengiriman email notifikasi dan pengetesan SMTP |
| **Konfigurasi** | `dotenv` (^17.4.2) | Manajemen environment variables dari file `.env` |
| **CORS** | `cors` (^2.8.6) | Penanganan Cross-Origin Resource Sharing |

---

## 3. Struktur Direktori & File

```text
backend-node-temins/
├── .env copy                 # Salinan konfigurasi variabel lingkungan
├── config/
│   └── db.js                 # Koneksi database pool MySQL
├── controllers/
│   ├── admin/
│   │   ├── dev.js            # Manajemen template perangkat IoT
│   │   ├── instansi.js       # Manajemen data instansi & assignment user
│   │   └── set_rec.js        # Konfigurasi data recorder JSON
│   ├── awlr/
│   │   ├── dashboard.js      # Data realtime dashboard AWLR
│   │   ├── history.js        # Riwayat sensor AWLR (Web & Mobile)
│   │   └── power.js          # Monitoring daya/baterai AWLR
│   ├── aws/
│   │   ├── cuaca.js          # Topic MQTT cuaca AWS
│   │   ├── dashboard.js      # Data realtime dashboard AWS
│   │   ├── history.js        # Riwayat sensor AWS (Web & Mobile)
│   │   ├── power.js          # Monitoring daya/baterai AWS
│   │   └── wind.js           # Topic MQTT parameter angin & hujan AWS
│   ├── ews.js                # Kontrol EWS & upload audio OTA via MQTT
│   ├── sf/
│   │   ├── dashboard.js      # Data realtime dashboard Smart Farm
│   │   ├── history.js        # Riwayat sensor Smart Farm (Web & Mobile)
│   │   └── power.js          # Monitoring daya/baterai Smart Farm
│   └── user/
│       ├── info.js           # Detail akun dan daftar perangkat milik user
│       └── multi.js          # Penanganan user yang memiliki multi-device
├── index.js                  # Entry point utama aplikasi Express
├── middleware/
│   └── auth.js               # Verifikasi token JWT pada HTTP Header
├── migrations/
│   └── add_is_demo.js        # Script migrasi kolom is_demo pada tabel users
├── package.json              # Definisi dependensi & skrip npm
├── routes/
│   ├── admin/
│   │   ├── dev.js            # Routing /api-app/admin/dev.php
│   │   ├── ds.js             # Routing /api-app/admin/ds.php (Dashboard & CRUD Device)
│   │   ├── instansi.js       # Routing /api-app/admin/instansi.php
│   │   ├── set_rec.js        # Routing /api-app/admin/set_rec.php
│   │   └── settings.js       # Routing /api-app/admin/admin-set.php
│   ├── auth.js               # Routing /api-app/auth (Login User & Instansi)
│   ├── ews.js                # Routing /api/v1/ews
│   ├── instansi/
│   │   └── aws.js            # Routing /api-app/instansi/aws/ds.php
│   ├── user/
│   │   ├── awlr.js           # Routing /api-app/user/awlr
│   │   ├── aws.js            # Routing /api-app/user/aws
│   │   └── sf.js             # Routing /api-app/user/sf
│   └── user.js               # Routing /api-app/user (Info & Multi-device)
├── test_mqtt.js              # Script pengujian konektivitas broker MQTT
└── uploads/                  # Folder penyimpanan berkas audio EWS
```

---

## 4. Konfigurasi Lingkungan & Basis Data

### 4.1. File Konfigurasi `.env`
Contoh konfigurasi environment yang digunakan oleh aplikasi:

```env
PORT=4000
BASE_URL=https://be-dash.temins.id/node
DB_HOST=localhost
DB_USER=aris
DB_PASS=Aris@022805
DB_NAME=temins
JWT_SECRET=rahasia_token_jwt_temins
MQTT_BROKER_URL=wss://karsacerdasinovatif.web.id:8081
```

### 4.2. Koneksi Database (`config/db.js`)
Menggunakan koneksi pool `mysql2/promise` untuk efisiensi koneksi tinggi:
- **`waitForConnections: true`**: Permintaan antri jika pool penuh.
- **`connectionLimit: 10`**: Batas maksimum koneksi simultan.
- **`queueLimit: 0`**: Antrean tanpa batas.

### 4.3. Entitas & Tabel Database Utama
Sistem berinteraksi dengan tabel-tabel MySQL berikut:
1. **`users`**: Menyimpan identitas akun (`id`, `username`, `password`, `role`, `email`, `instansi_id`, `is_demo`, `diBuat`).
2. **`instansi`**: Menyimpan identitas instansi/organisasi (`id`, `name`, `username`, `password`).
3. **`user_devices`**: Menyimpan kaitan kepemilikan perangkat ke pengguna (`id`, `user_id`, `device_name`, `device_unique_id`, `device_type`, `location`, `city`, `owner_name`, `internet_no`, `pic`, `pic_contact`, `timezone`, `status`, `masa_aktif`, `masa_paket`, `waktu_add`).
4. **`device_settings`**: Konfigurasi parameter sensor perangkat (`id`, `device_unique_id`, `parameter_name`, `mqtt_topic`, `unit`, `display_order`, `is_visible`, `category`, `tinggi_sensor`). Kategori mencakup `sensor`, `power`, `config`, dan `jenis`.
5. **`sensor_logs`**: Log historis data sensor (`id`, `device_unique_id`, `topic`, `value`, `recorded_at`).
6. **`user_sensor_charts`**: Konfigurasi chart visualisasi sensor yang aktif (`id`, `user_id`, `device_unique_id`, `device_setting_id`, `chart_order`, `is_active`, `data`).
7. **`device_automations`**: Aturan otomatisasi/alert sensor (`id`, `device_unique_id`, `parameter_name`, `operator`, `threshold`, `send_email`, `send_notification`).
8. **`device_templates` & `template_params`**: Master cetak biru parameter untuk memudahkan pembuatan perangkat baru.
9. **`app_configs`**: Konfigurasi global versi aplikasi mobile/frontend (`id`, `status`, `versi`, `url`).
10. **`user_push_tokens`**: Token perangkat FCM/Push notification milik pengguna.
11. **`email_configs`**: Konfigurasi kredensial SMTP Gmail untuk notifikasi otomatis.

---

## 5. Keamanan & Autentikasi

### 5.1. Mekanisme JWT & Password Hashing
- Kata sandi akun disimpan dalam bentuk hash menggunakan **Bcrypt** dengan salt round 10.
- Autentikasi menghasilkan token **JWT (JSON Web Token)** dengan masa aktif 7 hari (atau 1 tahun untuk token instansi).
- Payload JWT memuat data:
  ```json
  {
    "uid": 12,
    "username": "user_demo",
    "role": "user",
    "device_type": "aws",
    "exp": 1792400000
  }
  ```

### 5.2. Middleware Verifikasi Token (`middleware/auth.js`)
Fungsi `verifyToken(req, res, next)` memeriksa header HTTP:
```http
Authorization: Bearer <jwt_token>
```
Jika token tidak valid, kadaluwarsa, atau tidak dikirim, server mengembalikan status HTTP `401 Unauthorized`.

### 5.3. Role & Hak Akses
1. **`admin`**:
   - Memiliki hak akses penuh ke rute `/api-app/admin/*`.
   - Mengelola perangkat, pengguna, instansi, template sensor, notifikasi push, dan pengaturan server.
2. **`instansi`**:
   - Memiliki akses ke rute `/api-app/instansi/*`.
   - Dapat melihat agregasi seluruh perangkat milik anggota/user yang bernaung di bawah instansi tersebut.
3. **`user`**:
   - Memiliki akses ke `/api-app/user/*` (sesuai tipe alat: `aws`, `awlr`, atau `sf`).
   - Hanya dapat membaca perangkat yang terdaftar atas ID user-nya.

### 5.4. Proteksi Akun Demo (`is_demo`)
Pada operasi Admin (`routes/admin/ds.js`), akun yang ditandai dengan flag `is_demo = 1` dilindungi agar konfigurasi sensor inti, riwayat log, dan data chart tidak dapat ditimpa atau dihapus saat proses update/delete dilakukan oleh akun demo.

---

## 6. Dokumentasi Lengkap File & Fungsi

### 6.1. Root File

#### [index.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/index.js)
File gerbang utama (*entry point*) server Express.
- **Fungsi**:
  1. Menginisialisasi Express dan membaca konfigurasi via `dotenv`.
  2. Menerapkan middleware global: `cors()`, `express.json()`, `express.urlencoded()`.
  3. Menyediakan static folder file uploads melalui URL `/uploads`.
  4. Mendaftarkan seluruh routing aplikasi (`/api-app/auth`, `/api-app/user`, `/api-app/admin/*`, `/api/v1/ews`, dll).
  5. Menjalankan HTTP server pada port yang ditentukan (`process.env.PORT || 4000`).

#### [package.json](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/package.json)
File manifes proyek Node.js.
- **Tipe Proyek**: CommonJS (`"type": "commonjs"`).
- **Dependensi Utama**: `bcryptjs`, `cors`, `dotenv`, `express`, `jsonwebtoken`, `mqtt`, `multer`, `mysql2`, `nodemailer`.

#### [test_mqtt.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/test_mqtt.js)
Skrip diagnostik mandiri (*standalone test script*) untuk memeriksa status koneksi jaringan ke MQTT Broker:
- Menguji WebSocket port `8081` (`ws://karsacerdasinovatif.web.id:8081`).
- Menguji TCP port `1883` (`mqtt://karsacerdasinovatif.web.id:1883`).

---

### 6.2. Konfigurasi & Middleware

#### [config/db.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/config/db.js)
- **Fungsi**: Membuat instance database connection pool menggunakan `mysql2/promise`.
- **Ekspor**: `pool` (objek pool database untuk eksekusi query async/await).

#### [middleware/auth.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/middleware/auth.js)
- **Fungsi**:
  - `verifyToken(req, res, next)`:
    - Mengambil string token dari header `Authorization: Bearer <token>`.
    - Memverifikasi integritas dan masa berlaku token menggunakan `jwt.verify()` dengan kunci `process.env.JWT_SECRET`.
    - Jika valid, menyimpan payload token ke `req.user` dan melanjutkan ke `next()`.
    - Jika tidak valid, mengembalikan status HTTP `401`.

---

### 6.3. Migrasi & Utilitas

#### [migrations/add_is_demo.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/migrations/add_is_demo.js)
- **Fungsi**: `migrate()`
  - Melakukan pengecekan skema database pada tabel `users` via `INFORMATION_SCHEMA.COLUMNS`.
  - Menambahkan kolom `is_demo TINYINT(1) DEFAULT 0` jika kolom tersebut belum ada di database.

---

### 6.4. Rute & Controller Otentikasi (Auth)

#### [routes/auth.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/auth.js)
Menangani proses autentikasi sistem.
- **Endpoint**: `POST /api-app/auth/login.php`
- **Query Parameter**: `?login=user` atau `?login=instansi`
- **Body**: `{ "username": "...", "password": "..." }`
- **Alur Kerja**:
  1. Jika `login === 'instansi'`:
     - Mencari akun di tabel `instansi`.
     - Memverifikasi kata sandi dengan `bcrypt.compare`.
     - Menetapkan role `instansi` dan target redirect `user/instansi/`.
  2. Jika login user umum:
     - Mencari akun di tabel `users`.
     - Memverifikasi kata sandi dengan `bcrypt.compare`.
     - Jika role akun adalah `admin`: target redirect diatur ke `sett/`.
     - Jika role akun adalah `user`: mengambil data tipe perangkat dari tabel `user_devices` (`awlr`, `aws`, atau `smart_farm`) dan menentukan target redirect ke `user/awlr/`, `user/aws/`, atau `user/sf/`.
  3. Menandatangani token JWT berdurasi 7 hari.
  4. Mengembalikan respons status sukses beserta data token dan informasi pengguna.

---

### 6.5. Rute & Controller User

#### [routes/user.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/user.js)
Menyediakan informasi perangkat dan konfigurasi umum untuk user.
- `GET /api-app/user/timeout.php`: Mengembalikan daftar ID perangkat yang menggunakan durasi batas waktu komunikasi panjang (contoh: `["0035"]`).
- `GET /api-app/user/info.php`: Memanggil controller `getUserInfo`.
- `GET /api-app/user/multi/devices.php`: Memanggil controller `getMultiDevices`.
- `GET /api-app/user/multi/aws/ds.php`: Memanggil controller `getMultiAwsDs`.

#### [controllers/user/info.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/user/info.js)
- **Fungsi**: `getUserInfo(req, res)`
  - Mengambil daftar perangkat dari tabel `user_devices` berdasarkan `user_id` yang terdekripsi dari token JWT.
  - Mengembalikan informasi perangkat (nama alat, nomor internet/SIM, PIC kontak, lokasi, status alat, zona waktu).

#### [controllers/user/multi.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/user/multi.js)
Menangani akun pengguna yang memiliki lebih dari satu perangkat IoT.
- **Fungsi**: `getMultiDevices(req, res)`
  - Menampilkan daftar ringkas seluruh perangkat milik pengguna (`device_unique_id`, `device_name`, `location`).
- **Fungsi Pembantu**: `detectType(label)`
  - Melakukan identifikasi otomatis tipe sensor berdasarkan label teks (contoh: arah angin -> `wind_dir`, kelembapan -> `hum`, hujan -> `rain`, suhu -> `temp`, baterai -> `battery`).
- **Fungsi**: `getMultiAwsDs(req, res)`
  - Mengambil parameter query `device_id`.
  - Memastikan perangkat tersebut valid dan milik user yang sedang login.
  - Mengambil konfigurasi sensor dari `device_settings` beserta nilai log telemetri terakhir dari `sensor_logs`.
  - Mengambil konfigurasi grafik visual dari `user_sensor_charts`.
  - Mengembalikan data gabungan perangkat, sensor, grafik, dan topik MQTT yang harus disubscribe oleh antarmuka pengguna.

---

### 6.6. Rute & Controller AWS (Automatic Weather Station)

#### [routes/user/aws.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/user/aws.js)
Menghubungkan endpoint antarmuka AWS ke masing-masing controller:
- `GET /api-app/user/aws/ds.php` -> `getDashboard`
- `GET /api-app/user/aws/power.php` -> `getPower`
- `GET /api-app/user/aws/power-mobile.php` -> `getPowerMobile`
- `GET /api-app/user/aws/history.php` -> `getHistory`
- `GET /api-app/user/aws/history-mobile.php` -> `getHistoryMobile`
- `GET /api-app/user/aws/cuaca.php` -> `getCuaca`
- `GET /api-app/user/aws/wind.php` -> `getWind`

#### [controllers/aws/dashboard.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/aws/dashboard.js)
- **Fungsi**: `getDashboard(req, res)`
  - Mengambil perangkat AWS milik user dari `user_devices`.
  - Mengambil daftar sensor aktif (`category = 'sensor'`).
  - Mengambil nilai pengukuran terakhir (`sensor_logs`) untuk tiap sensor.
  - Membaca pengaturan grafik aktif dari `user_sensor_charts`.
  - Menyusun respons lengkap: data alat, array sensor, daftar grafik, dan topik MQTT.

#### [controllers/aws/history.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/aws/history.js)
- **Fungsi Pembantu**: `getSensorConfig(label)`
  - Menentukan ikon FontAwesome (`fa-compass`, `fa-wind`, `fa-cloud-rain`, dll) dan kode warna heksadesimal representasi grafik berdasarkan nama sensor.
- **Fungsi**: `getHistory(req, res)`
  - Mengambil daftar sensor grafik aktif untuk versi web.
  - Mengembalikan daftar rentang tahun yang tersedia dari tahun 2024 hingga tahun saat ini.
- **Fungsi**: `getHistoryMobile(req, res)`
  - Mengambil daftar sensor grafik aktif untuk aplikasi mobile.
  - Menghitung rentang tahun data secara dinamis berdasarkan `MIN(recorded_at)` dan `MAX(recorded_at)` pada tabel `sensor_logs`.

#### [controllers/aws/power.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/aws/power.js)
- **Fungsi**: `getPower(req, res)` & `getPowerMobile(req, res)`
  - Mengambil konfigurasi sensor dengan `category = 'power'` (Tegangan Solar Panel/Baterai, Arus Pengisian, Daya dalam satuan Watt).
  - Mengambil nilai log terakhir masing-masing parameter daya dari `sensor_logs`.
  - Mengelompokkan tipe daya (`amp`, `volt`, `watt`, atau `general`).

#### [controllers/aws/wind.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/aws/wind.js)
- **Fungsi**: `getWind(req, res)`
  - Memetakan topik MQTT khusus parameter angin dan hujan:
    - Curah Hujan (`ch`)
    - Arah Angin (`aa`)
    - Kecepatan Angin (`ka`)
  - Mengembalikan daftar topik MQTT untuk visualisasi kompas dan anemometer realtime.

#### [controllers/aws/cuaca.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/aws/cuaca.js)
- **Fungsi**: `getCuaca(req, res)`
  - Memetakan topik MQTT khusus parameter cuaca:
    - Suhu Udara (`su`)
    - Kelembapan Udara (`ku`)
    - Radiasi Matahari (`rm`)
    - Curah Hujan (`ch`)
    - Kecepatan Angin (`ka`)

---

### 6.7. Rute & Controller AWLR (Automatic Water Level Recorder)

#### [routes/user/awlr.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/user/awlr.js)
Menghubungkan endpoint AWLR:
- `GET /api-app/user/awlr/ds.php` -> `getDashboard`
- `GET /api-app/user/awlr/history.php` -> `getHistory`
- `GET /api-app/user/awlr/history-mobile.php` -> `getHistoryMobile`
- `GET /api-app/user/awlr/power.php` -> `getPower`
- `GET /api-app/user/awlr/power-mobile.php` -> `getPowerMobile`

#### [controllers/awlr/dashboard.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/awlr/dashboard.js)
- **Fungsi**: `getDashboard(req, res)`
  - Mengambil data alat AWLR milik pengguna.
  - Membaca parameter sensor `tinggi air cm` (jarak/kedalaman) dan `batre`/`battery`.
  - Mengambil nilai jarak air awal dan baterai dari log terakhir.
  - Membaca konfigurasi ambang batas (`category = 'config'`), tipe instalasi perairan/sungai (`mqtt_topic = 'jenis'`), dan data grafik level muka air (`chart_order = 111`).
  - Mengembalikan payload yang siap dikonsumsi oleh visualisasi grafis tangki/sungai AWLR.

#### [controllers/awlr/history.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/awlr/history.js)
- **Fungsi Pembantu**: `getSensorStyle(label)`
  - Memberikan ikon dan warna styling untuk sensor AWLR.
- **Fungsi**: `getHistory(req, res)` & `getHistoryMobile(req, res)`
  - Mengembalikan daftar sensor AWLR yang memiliki grafik aktif beserta rentang tahun pencatatan log.

#### [controllers/awlr/power.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/awlr/power.js)
- **Fungsi**: `getPower(req, res)` & `getPowerMobile(req, res)`
  - Mengambil telemetri daya kelistrikan perangkat AWLR (`tegangan`, `arus`, `daya`).

---

### 6.8. Rute & Controller Smart Farm (SF)

#### [routes/user/sf.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/user/sf.js)
Menghubungkan endpoint Smart Farm:
- `GET /api-app/user/sf/ds.php` -> `getDashboard`
- `GET /api-app/user/sf/history.php` -> `getHistory`
- `GET /api-app/user/sf/history-mobile.php` -> `getHistoryMobile`
- `GET /api-app/user/sf/power.php` -> `getPower`
- `GET /api-app/user/sf/power-mobile.php` -> `getPowerMobile`

#### [controllers/sf/dashboard.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/sf/dashboard.js)
- **Fungsi Pembantu**: `detectType(label)`
  - Mengklasifikasikan sensor tanah: kelembapan tanah (`soil_moist`), suhu tanah (`soil_temp`), pH tanah (`val_ph`), Nitrogen (`val_n`), Fosfor (`val_p`), Kalium (`val_k`), TDS (`val_tds`), EC (`val_ec`), Salinitas (`val_salt`), dan Baterai (`battery`).
- **Fungsi**: `getDashboard(req, res)`
  - Mengambil data alat pertanian cerdas.
  - Memetakan pembacaan sensor ke dalam struktur objek khusus: `soil_moist`, `soil_temp`, `soil_ph`, `battery`, array `npk`, dan array parameter kimia lainnya (`chem`).
  - Mengembalikan konfigurasi koneksi broker MQTT WebSocket (`wss://karsacerdasinovatif.web.id:8081`).

#### [controllers/sf/history.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/sf/history.js)
- **Fungsi**: `getHistory(req, res)`
  - Mengambil daftar sensor aktif dan rentang tahun riwayat pencatatan untuk visualisasi riwayat web.
- **Fungsi**: `getHistoryMobile(req, res)`
  - Mengambil opsi sensor untuk antarmuka grafik mobile.

#### [controllers/sf/power.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/sf/power.js)
- **Fungsi**: `getPower(req, res)` & `getPowerMobile(req, res)`
  - Membaca telemetri daya (tegangan baterai, arus panel, daya sistem Smart Farm).

---

### 6.9. Rute & Controller Instansi

#### [routes/instansi/aws.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/instansi/aws.js)
Menangani dashboard multi-perangkat bagi akun korporasi/instansi (B2B).
- **Endpoint**: `GET /api-app/instansi/aws/ds.php`
- **Autentikasi**: Wajib JWT dengan role `instansi`.
- **Alur Kerja**:
  1. Mengambil ID instansi dari token.
  2. Melakukan query JOIN antara `user_devices` dan `users` untuk mengambil seluruh perangkat yang dimiliki oleh user-user binaan instansi tersebut (`WHERE u.instansi_id = ?`).
  3. Mengembalikan daftar perangkat (`device_list`).
  4. Jika dikirimkan parameter query `?device_id=...`:
     - Mengambil rincian sensor aktif pada perangkat yang dipilih.
     - Mengambil konfigurasi grafik visual.
     - Mengembalikan detail perangkat terpilih (`selected_device_details`).

---

### 6.10. Rute & Controller Admin

Seluruh rute admin dilindungi oleh middleware pengecekan role `admin`. Jika pengguna bukan admin, server langsung menolak dengan status HTTP `403 Forbidden`.

#### [routes/admin/ds.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/admin/ds.js)
Controller terintegrasi untuk Dashboard Manajemen Perangkat Admin (`/api-app/admin/ds.php`).
- **Aksi `GET`**:
  - `action=get_templates`: Mengambil data master template perangkat beserta parameter default sensor.
  - `action=get_device_config`: Mengambil konfigurasi komprehensif suatu perangkat (`device_settings`, `user_sensor_charts`, `device_automations`, info perangkat, data AWLR spesifik).
  - *Tanpa action*: Mengambil daftar seluruh pengguna dan perangkat, ringkasan jumlah alat aktif vs nonaktif.
- **Aksi `POST`**:
  - `action=create_user`:
    - Membuat akun user baru (password di-hash dengan bcrypt).
    - Mendaftarkan data perangkat ke `user_devices`.
    - Menginsert parameter sensor terpilih ke `device_settings` dan grafik ke `user_sensor_charts`.
    - Mendaftarkan aturan alert ke `device_automations`.
    - Menginisialisasi otomatis parameter power (`Tegangan`, `Arus Charging`, `daya`) dan parameter dasar AWLR jika tipe alat adalah AWLR.
  - `action=update_config`:
    - Memperbarui data pengguna, zona waktu, lokasi, kontak PIC, dan status perangkat.
    - Jika akun bukan akun demo (`is_demo = 0`), memperbarui konfigurasi parameter sensor, chart, dan otomatisasi.
  - `action=delete_param`: Menghapus satu parameter sensor beserta grafiknya.
  - `action=change_password`: Mengubah kata sandi user dengan hash bcrypt baru.
  - `action=delete_user`: Menghapus user dan seluruh data terkait perangkat (dengan proteksi akun demo).

#### [routes/admin/settings.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/admin/settings.js)
Pengaturan sistem admin (`/api-app/admin/admin-set.php`).
- **Aksi `admin-users`**:
  - `GET`: Mengambil daftar akun admin.
  - `POST`: Menambahkan admin baru.
  - `PUT (change_password)`: Mengubah sandi admin.
  - `PUT (edit_profile)`: Mengubah username/email admin.
  - `DELETE`: Menghapus admin (dilengkapi validasi agar admin tidak terhapus jika hanya tersisa 1 admin).
- **Aksi `app-config`**:
  - `GET`: Membaca versi aplikasi, status maintenance, dan URL download app.
  - `POST`: Memperbarui data konfigurasi aplikasi.
- **Aksi `push-users`**:
  - `GET`: Mengambil daftar user yang memiliki push token aktif untuk notifikasi FCM.
- **Aksi `send-notif`**:
  - `POST`: Mengirim notifikasi push ke pengguna tertentu atau semua pengguna via microservice eksternal (`https://be-data.dash.temins.id/send/notif`).
- **Aksi `email-config`**:
  - `GET`: Mengambil konfigurasi email pengirim (dengan masking password aplikasi).
  - `POST (type=update)`: Menyimpan email dan Google App Password.
  - `POST (type=test)`: Mengirim email uji coba via Nodemailer (SMTP Gmail port 587).

#### [routes/admin/dev.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/admin/dev.js) & [controllers/admin/dev.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/admin/dev.js)
Manajemen Template Perangkat IoT (`/api-app/admin/dev.php`).
- **Fungsi**: `handleDevTemplates(req, res)`
  - `GET`: Menampilkan seluruh daftar template alat beserta parameter sensor bawaannya (`device_templates` JOIN `template_params`).
  - `POST (action=create)`: Membuat template baru dengan kode unik dan daftar parameter.
  - `POST (action=update)`: Memperbarui nama template dan memperbarui ulang parameter sensor.
  - `POST (action=delete)`: Menghapus template beserta parameter terkait.

#### [routes/admin/instansi.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/admin/instansi.js) & [controllers/admin/instansi.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/admin/instansi.js)
Manajemen Organisasi / Instansi (`/api-app/admin/instansi.php`).
- **Fungsi**: `handleInstansi(req, res)`
  - `get_instansi`: Daftar instansi terdaftar.
  - `add_instansi`: Mendaftarkan instansi baru.
  - `update_instansi`: Mengedit data/password instansi.
  - `delete_instansi`: Menghapus data instansi.
  - `get_all_users`: Mengambil daftar user yang belum terafiliasi ke instansi manapun (`instansi_id IS NULL`).
  - `assign_user`: Menautkan user ke instansi tertentu.
  - `remove_user_from_instansi`: Melepaskan user dari instansi.
  - `get_users_by_instansi`: Daftar user dan perangkat binaan instansi tertentu.
  - `add_user`: Membuat user baru langsung di bawah instansi.
  - `get_instansi_token`: Membuat token akses instansi berdurasi 1 tahun untuk integrasi API instansi.

#### [routes/admin/set_rec.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/admin/set_rec.js) & [controllers/admin/set_rec.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/admin/set_rec.js)
Manajemen Data Recorder Perangkat (`/api-app/admin/set_rec.php`).
- **Fungsi**: `handleSetRec(req, res)`
  - Mengelola konfigurasi perekaman data sensor yang disimpan dalam file fisik JSON:
    `rekam-data/py/1.json`
  - Operasi:
    - `GET`: Membaca struktur file JSON.
    - `POST (add_device_type)`: Menambah jenis perangkat baru.
    - `POST (update_def_topic)`: Mengatur default topik MQTT yang harus direkam untuk jenis perangkat tertentu.
    - `POST (add_device)`: Menambahkan ID perangkat ke daftar rekam.
    - `POST (update_device)`: Mengatur apakah perangkat menggunakan default topik atau topik kustom.
    - `POST (delete_device)`: Menghapus perangkat dari recorder.
    - `POST (delete_device_type)`: Menghapus kategori perangkat dari recorder.

---

### 6.11. Rute & Controller EWS (Early Warning System)

#### [routes/ews.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/routes/ews.js)
Mengatur rute peringatan dini dan penanganan file audio multipart/form-data menggunakan `multer`.
- **Konfigurasi Penyimpanan Multer**:
  - Direktori tujuan: `uploads/`.
  - Penamaan file unik: `audio-<timestamp>-<random><ekstensi>`.

#### [controllers/ews.js](file:///home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins/controllers/ews.js)
Mengelola komunikasi perintah kontrol EWS via MQTT.
- **Koneksi MQTT Broker**:
  - Menginisialisasi koneksi klien MQTT ke broker (`process.env.MQTT_BROKER_URL || 'wss://karsacerdasinovatif.web.id:8081'`).
- **Fungsi**: `ewsControl(req, res)`
  - Menerima payload JSON kontrol (wajib memuat `device_id`).
  - Mengirim payload ke topik MQTT:
    `temins_iot/<device_id>/control`
  - Contoh payload yang dipublikasikan: `{ "cmd": "siren_on", "duration": 30 }`.
- **Fungsi**: `uploadAudioControl(req, res)`
  - Menerima upload file audio dan form-data `device_id` serta `target`.
  - Menyusun URL publik file: `${process.env.BASE_URL}/uploads/${req.file.filename}`.
  - Mempublikasikan perintah OTA Audio via MQTT ke topik `temins_iot/<device_id>/control`:
    ```json
    {
      "cmd": "ota_audio",
      "url": "https://be-dash.temins.id/node/uploads/audio-1724912345.mp3",
      "target": "speaker_1"
    }
    ```

---

## 7. Matriks Endpoint API

| Method | URL Endpoint | Auth / Role | Keterangan & Parameter Utama |
| :--- | :--- | :--- | :--- |
| `GET` | `/` | Publik | Status health check server |
| `POST` | `/api-app/auth/login.php` | Publik | Login pengguna / instansi (`?login=user` atau `instansi`) |
| `GET` | `/api-app/user/timeout.php` | Publik | Daftar ID perangkat dengan timeout khusus |
| `GET` | `/api-app/user/info.php` | User / JWT | Informasi user dan seluruh perangkat miliknya |
| `GET` | `/api-app/user/multi/devices.php` | User / JWT | Daftar ringkas perangkat untuk akun multi-device |
| `GET` | `/api-app/user/multi/aws/ds.php` | User / JWT | Telemetri realtime alat tertentu (`?device_id=...`) |
| `GET` | `/api-app/user/aws/ds.php` | User / JWT | Dashboard utama perangkat AWS |
| `GET` | `/api-app/user/aws/power.php` | User / JWT | Data tegangan, arus, dan daya AWS |
| `GET` | `/api-app/user/aws/power-mobile.php` | User / JWT | Data power AWS untuk mobile |
| `GET` | `/api-app/user/aws/history.php` | User / JWT | Opsi sensor riwayat AWS (Web) |
| `GET` | `/api-app/user/aws/history-mobile.php` | User / JWT | Opsi sensor riwayat AWS (Mobile) |
| `GET` | `/api-app/user/aws/cuaca.php` | User / JWT | Topik MQTT cuaca AWS |
| `GET` | `/api-app/user/aws/wind.php` | User / JWT | Topik MQTT arah & kecepatan angin AWS |
| `GET` | `/api-app/user/awlr/ds.php` | User / JWT | Dashboard AWLR & visual muka air |
| `GET` | `/api-app/user/awlr/power.php` | User / JWT | Data daya perangkat AWLR |
| `GET` | `/api-app/user/awlr/history.php` | User / JWT | Opsi sensor riwayat AWLR |
| `GET` | `/api-app/user/sf/ds.php` | User / JWT | Dashboard Smart Farm (tanah, NPK, lingkungan) |
| `GET` | `/api-app/user/sf/power.php` | User / JWT | Data daya perangkat Smart Farm |
| `GET` | `/api-app/user/sf/history.php` | User / JWT | Opsi sensor riwayat Smart Farm |
| `GET` | `/api-app/instansi/aws/ds.php` | Instansi / JWT | Monitoring agregasi alat di bawah instansi |
| `ALL` | `/api-app/admin/ds.php` | Admin / JWT | CRUD User, konfigurasi sensor alat, demo proteksi |
| `ALL` | `/api-app/admin/admin-set.php` | Admin / JWT | Kelola admin, app config, kirim push notif, SMTP |
| `ALL` | `/api-app/admin/dev.php` | Admin / JWT | Kelola template perangkat & sensor bawaan |
| `ALL` | `/api-app/admin/instansi.php` | Admin / JWT | Kelola instansi & penautan akun user |
| `ALL` | `/api-app/admin/set_rec.php` | Admin / JWT | Kelola recorder data JSON (`rekam-data/py/1.json`) |
| `POST` | `/api/v1/ews` | Publik / Key | Publikasi perintah kontrol EWS ke MQTT |
| `POST` | `/api/v1/ews/upload-audio` | Publik / Key | Upload audio OTA dan perintah putar audio via MQTT |

---

## 8. Integrasi IoT & Protokol MQTT

Komunikasi dua arah antara perangkat fisik (Hardware NodeMCU/ESP32/STM32) dan backend terjalin melalui protokol MQTT:

```text
+-------------------+                   +------------------------+                   +--------------------+
|  Perangkat Fisik  |  --- Telemetri -> |      MQTT Broker       |  --- Simpan log-> |  Database MySQL    |
| (AWS, AWLR, SF)   |  <-  Kontrol ---  | (WS:8081 / TCP:1883)   |                   |  (sensor_logs)     |
+-------------------+                   +------------------------+                   +--------------------+
                                                     ^
                                                     | Subscribe / Publish
                                        +------------------------+
                                        |   Backend Node-Temins  |
                                        |      (Controllers)     |
                                        +------------------------+
```

### Konvensi Topik MQTT:
1. **Penerimaan Data Telemetri**:
   - Format: `temins_iot/<device_unique_id>/data/<parameter_code>`
   - Contoh:
     - `temins_iot/0035/data/su` (Suhu Udara)
     - `temins_iot/0035/data/ku` (Kelembapan Udara)
     - `temins_iot/0035/data/tsp` (Tegangan Solar Panel)
2. **Pengiriman Perintah Kontrol (EWS / Sirine / OTA)**:
   - Format: `temins_iot/<device_unique_id>/control`
   - QoS: `1` (At least once delivery)
   - Format Pesan: JSON Object

---

## 9. Panduan Instalasi & Pengoperasian

### 9.1. Prasyarat Sistem
- **Node.js**: Versi `>= 18.0.0` (direkomendasikan LTS)
- **NPM**: Versi `>= 9.0.0`
- **Database**: MySQL Server versi 8.0 atau MariaDB 10.5+
- **Broker MQTT**: EMQX / Mosquitto yang mengaktifkan listener WebSocket & TCP

### 9.2. Langkah Instalasi
1. Buka direktori proyek:
   ```bash
   cd /home/aris/Dokumen/projeck/Temins/backend-api-temins/backend-node-temins
   ```
2. Pasang semua dependensi:
   ```bash
   npm install
   ```
3. Konfigurasi file `.env`:
   Salin file contoh dan sesuaikan kredensial basis data Anda:
   ```bash
   cp ".env copy" .env
   ```
   Pastikan variabel `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, dan `JWT_SECRET` telah terisi dengan benar.

4. Jalankan migrasi basis data (jika diperlukan kolom `is_demo`):
   ```bash
   node migrations/add_is_demo.js
   ```

5. Uji koneksi MQTT:
   ```bash
   node test_mqtt.js
   ```

6. Jalankan server:
   - Mode Pengembangan / Standar:
     ```bash
     node index.js
     ```
   - Mode Produksi (menggunakan Process Manager PM2):
     ```bash
     pm2 start index.js --name "backend-node-temins"
     pm2 save
     ```

---

## 10. Catatan Pemeliharaan & Rekomendasi

1. **Rekomendasi Helper `getSensorStyle` pada `controllers/sf/history.js`**:
   - Pada file `controllers/sf/history.js` di dalam fungsi `getHistoryMobile`, terdapat pemanggilan fungsi `getSensorStyle(row.parameter_name)`. Saat ini fungsi tersebut belum dideklarasikan secara lokal di file tersebut (berbeda dengan `controllers/awlr/history.js` yang sudah memiliki deklarasi fungsi tersebut).
   - *Saran*: Tambahkan helper fungsi `getSensorStyle` atau impor dari modul utilitas bersama untuk mencegah potensi `ReferenceError` saat endpoint mobile Smart Farm diakses.

2. **Pengamanan Endpoint EWS**:
   - Endpoint `/api/v1/ews` dan `/api/v1/ews/upload-audio` saat ini belum dipasangi middleware `verifyToken`.
   - *Saran*: Jika endpoint ini diakses dari sistem eksternal atau aplikasi mobile, pertimbangkan penambahan validasi API Key atau token autentikasi khusus agar tidak dapat ditembak oleh pihak yang tidak bertanggung jawab.

3. **Pembersihan Berkas Upload**:
   - File rekaman suara EWS yang diunggah ke folder `uploads/` akan terus bertambah. Disarankan membuat rutinitas cron (*cron job*) berkala untuk mengarsipkan atau menghapus file audio usang yang sudah lewat dari jangka waktu tertentu.

---

*Dokumentasi ini disusun secara komprehensif untuk memudahkan pengembangan, integrasi, dan pemeliharaan backend Temins IoT.*
