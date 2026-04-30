# 📡 TEMINS IoT Logger — Dokumentasi Program `go_final`

> Program Go untuk menerima data sensor IoT via MQTT, memproses, dan menyimpannya ke database PostgreSQL secara batch setiap 5 menit.

---

## 🗂️ Struktur File

```
go_final/
├── main.go           → Entry point, inisialisasi, dan orkestrasi goroutine
├── globals.go        → Konstanta, variabel global, dan struct data
├── utils.go          → Fungsi utilitas waktu (WIB timezone)
├── logger.go         → Penulisan log ke file error.log
├── database.go       → Koneksi & query PostgreSQL dan MySQL
├── ch_state.go       → State & akumulasi curah hujan (CH/CHA)
├── buffer.go         → In-memory buffer + sinkronisasi ke file JSON
├── config.go         → Loader konfigurasi JSON + config file watcher
├── mqtt_handler.go   → Handler koneksi & pesan MQTT
├── scheduler.go      → Goroutine background (flush DB, cache refresh, dll.)
├── ws.go             → WebSocket log server & dashboard monitoring
├── go.mod            → Definisi module dan dependensi
└── dokumentasi.md    → File ini
```

---

## ⚙️ Konfigurasi (`globals.go`)

### Konstanta

| Konstanta | Nilai | Keterangan |
|---|---|---|
| `BROKER_HOST` | `karsacerdasinovatif.web.id` | Host MQTT broker |
| `BROKER_PORT` | `8081` | Port WebSocket MQTT |
| `FLUSH_INTERVAL_MINUTES` | `5` | Interval flush ke DB (menit) |
| `CACHE_REFRESH_INTERVAL` | `30s` | Interval refresh cache sensor (tidak aktif, diganti 60s) |
| `WIB_OFFSET` | `7 * time.Hour` | Offset zona waktu WIB (UTC+7) |
| `WEBSOCKET_LOG_PORT` | `8230` | Port HTTP/WebSocket dashboard |
| `POSTGRES_DSN` | `host=127.0.0.1 ...` | Connection string PostgreSQL |
| `MYSQL_DSN` | `root:...@tcp(...)` | Connection string MySQL |

### Path File (diset saat startup di `main.go`)

| Variabel | Default | Keterangan |
|---|---|---|
| `CONFIG_JSON_PATH` | `../py/1.json` | File konfigurasi perangkat |
| `BUFFER_FILE_PATH` | `buf2.json` | File buffer JSON (crash recovery) |
| `CH_STATE_FILE` | `ch_state.json` | File state curah hujan |
| `ERROR_LOG_PATH` | `error.log` | File log error/sukses |

### Struct Data

```go
// Data satu entri sensor yang akan di-flush ke PostgreSQL
type BufferData struct {
    DeviceID   string  // ID unik perangkat
    DeviceType string  // Tipe: "awlr", "arr", dll.
    Parameter  string  // Nama parameter: "tuc", "ch", "cha", dll.
    Value      float64 // Nilai sensor
    Timestamp  string  // Waktu penerimaan data (WIB)
}

// Konfigurasi satu perangkat dari file JSON
type DeviceConfig struct {
    DevID      string   // ID perangkat
    UseDefault bool     // Pakai default topic dari tipe?
    Topic      []string // Custom topic/parameter
}
```

---

## 🕐 Utilitas Waktu (`utils.go`)

| Fungsi | Return | Keterangan |
|---|---|---|
| `getWIBTime()` | `time.Time` | Waktu sekarang dalam zona WIB (UTC+7) |
| `formatWIBTimestamp(t)` | `string` | Format: `"2006-01-02 15:04:05"` |
| `formatWIBDate(t)` | `string` | Format: `"2006-01-02"` |
| `getNext5MinInterval()` | `(time.Time, time.Duration)` | Waktu interval 5 menit berikutnya + selisihnya |
| `getRounded5MinTimestamp()` | `time.Time` | Timestamp dibulatkan ke 5 menit terdekat ke bawah |

> **Contoh:** Jika jam sekarang 08:47, maka `getNext5MinInterval()` = `08:50`, dan `getRounded5MinTimestamp()` = `08:45`.

---

## 📝 Logger File (`logger.go`)

### `writeLogToFile(level, message string)`
Menulis log ke `error.log` dengan format:
```
[2026-04-30 08:30:00] SUCCESS: Berhasil menyimpan 12 data...
[2026-04-30 08:30:01] ERROR: Transaction error: ...
```
- Thread-safe (dilindungi `errorLogMutex`)
- Tidak menulis jika `ERROR_LOG_PATH` belum diset

### `cleanOldLogs()`
Goroutine background yang membersihkan entri log lebih dari **48 jam** setiap **6 jam** sekali. Menjaga ukuran file `error.log` agar tidak terus membesar.

---

## 🗄️ Database (`database.go`)

### Inisialisasi

| Fungsi | Keterangan |
|---|---|
| `initPostgres()` | Membuka pool koneksi PostgreSQL (Max: 5 koneksi, idle: 2) |
| `initMySQL()` | Membuka pool koneksi MySQL (Max: 3 koneksi, idle: 1) |

### Health Check

| Fungsi | Return | Keterangan |
|---|---|---|
| `checkPostgresConnection()` | `bool` | Ping ke PostgreSQL, log hasilnya |
| `checkMySQLConnection()` | `bool` | Ping ke MySQL, log hasilnya |

### Query Data

#### `getAllSensorHeightsFromMySQL() map[string]float64`
Mengambil semua `tinggi_sensor` dari tabel `device_settings` di MySQL.
- Skip data yang null, kosong, ≤ 0, atau > 10.000
- Hasilnya disimpan ke `sensorHeightCache` (in-memory)

#### `getSensorHeightFromCache(deviceID string) (float64, bool)`
Mengambil `tinggi_sensor` dari in-memory cache secara thread-safe.
Digunakan oleh kalkulasi AWLR di `buffer.go`.

#### `getLastValueFromDB(deviceID, parameter, date string) float64`
Query ke `sensor_logs` PostgreSQL untuk mengambil nilai terakhir parameter tertentu pada tanggal tertentu.
Digunakan oleh `processCurahHujan` saat recovery setelah restart program.

---

## 🌧️ Curah Hujan / CH State (`ch_state.go`)

### Konsep
Sensor curah hujan (ARR) mengirimkan nilai **counter tip** yang terus naik. Program menghitung nilai **terakumulasi harian (CHA)** dengan memperhitungkan:
- **Hari baru** → reset counter, recovery dari database jika masih hari sama
- **Sensor restart** (nilai turun tiba-tiba) → tambahkan nilai baru ke akumulasi
- **Normal naik** → hitung selisih dan tambahkan ke akumulasi

### State yang disimpan (per device)

| Field | Keterangan |
|---|---|
| `LastValue` | Nilai CH terakhir yang diterima |
| `Accumulated` | Total akumulasi CHA hari ini |
| `LastDate` | Tanggal terakhir pemrosesan (`YYYY-MM-DD`) |

### Fungsi

| Fungsi | Keterangan |
|---|---|
| `loadCHState()` | Load state dari `ch_state.json` saat startup |
| `saveCHState()` | Simpan state ke `ch_state.json` |
| `autoSaveCHState()` | Goroutine: auto-save setiap **10 detik** |
| `processCurahHujan(deviceID, chValue)` | Kalkulasi CHA, return `(cha_value, restart_detected)` |

### Alur `processCurahHujan`

```
Terima chValue
    │
    ├─ Hari baru / device baru?
    │       ├─ Ada data CHA di DB hari ini?
    │       │       ├─ chValue < lastRawCH → Sensor restart: CHA = lastCHA + chValue
    │       │       └─ chValue ≥ lastRawCH → Normal: CHA = lastCHA + selisih
    │       └─ Tidak ada data → CHA = chValue (mulai dari 0)
    │
    ├─ chValue < lastValue? → Restart sensor: CHA += chValue
    │
    └─ Normal naik → CHA += (chValue - lastValue)
```

---

## 💾 Buffer Data (`buffer.go`)

### Strategi Buffer (Hybrid Memory + File)

```
MQTT Message
     │
     ▼
saveToBuffer()  ←── in-memory map (cepat, tanpa disk I/O)
     │
     │  setiap 15 detik (periodicBufferSync)
     ▼
buf2.json  ←── backup untuk crash recovery
     │
     │  setiap 5 menit (flushToDB)
     ▼
readAndClearBuffer() → sync terakhir → baca memory → clear file & memory
     │
     ▼
PostgreSQL (sensor_logs)
```

### Fungsi

| Fungsi | Keterangan |
|---|---|
| `saveToBuffer(deviceID, parameter, value)` | Simpan data ke in-memory buffer |
| `readAndClearBuffer()` | Ambil semua data buffer, kosongkan memory + file |
| `writeBufferToFile(data)` | Tulis in-memory map ke `buf2.json` (harus dalam lock) |
| `clearBufferFile()` | Reset file buffer ke `{}` setelah flush |
| `LoadBufferFromFile()` | Load `buf2.json` ke memory saat startup (crash recovery) |

### Kalkulasi AWLR (di dalam `saveToBuffer`)
Jika `deviceType == "awlr"` dan `parameter == "tuc"`:
```
tinggi_air = tinggi_sensor - nilai_tuc
```
Hasilnya disimpan sebagai parameter `result_tinggi_air` di buffer.

### Key Buffer
Buffer menggunakan map dengan key format `deviceID|parameter`:
```
"ARR001|ch"              → nilai CH mentah
"ARR001|cha"             → nilai CHA terakumulasi
"AWLR001|tuc"            → nilai sensor ultrasonik
"AWLR001|result_tinggi_air" → hasil kalkulasi tinggi air
```
Setiap key hanya menyimpan **nilai terbaru** — data lama di interval yang sama di-overwrite.

---

## 📋 Konfigurasi Perangkat (`config.go`)

### Format File `1.json`
```json
{
  "device_type": {
    "awlr": {
      "def_topic": ["tuc", "bat"],
      "devices": [
        { "dev_id": "AWLR001", "use_default": true, "topic": [] }
      ]
    },
    "arr": {
      "def_topic": ["ch", "bat"],
      "devices": [
        { "dev_id": "ARR001", "use_default": true, "topic": ["suhu"] }
      ]
    }
  }
}
```

### `updateConfigFromJSON() (topics, deviceMap)`
- Membaca file `1.json`
- Membangun map `deviceID → tipeDevice`
- Membangun daftar topic MQTT: `temins_iot/<devID>/data/<parameter>`
- Return kedua map untuk update state global

### `configWatcher(client mqtt.Client)`
Goroutine blocking yang berjalan selamanya di akhir `main()`.
Setiap **10 detik** cek `ModTime` file `1.json`:
- Jika berubah → reload config → subscribe topic baru yang belum di-subscribe
- Topic lama tidak di-unsubscribe (menggunakan wildcard `temins_iot/#`)

---

## 📡 MQTT Handler (`mqtt_handler.go`)

### Event Handlers

| Handler | Trigger | Aksi |
|---|---|---|
| `onConnect` | Berhasil terkoneksi ke broker | Set `mqttConnected = true`, subscribe `temins_iot/#` |
| `connectionLostHandler` | Koneksi terputus | Set `mqttConnected = false`, log warning |
| `reconnectHandler` | Sedang mencoba reconnect | Log info |

### `onMessage(client, msg)`
Handler untuk setiap pesan MQTT yang masuk.

**Parsing Topic:**
```
temins_iot / <deviceID> / data / <parameter>
     [0]         [1]       [2]       [3]
```
- Abaikan jika format tidak sesuai atau `parts[2] != "data"`

**Parsing Payload** (dua format didukung):
```
Format JSON  : {"value": 123.45}  atau  {"value": "123.45"}
Format plain : 123.45
Format mixed : 123.45 - some description  (ambil bagian sebelum " - ")
```

---

## ⏱️ Scheduler & Background Workers (`scheduler.go`)

### `flushToDB()`
Goroutine utama yang mem-flush buffer ke PostgreSQL.

**Alur:**
1. Tunggu hingga interval 5 menit berikutnya + 2 detik toleransi
2. `readAndClearBuffer()` → ambil semua data dari buffer
3. Jika kosong → log status saja
4. Jika ada data → panggil `flushBatch()`
5. Ulangi dari langkah 1

### `flushBatch(dataToSave, batchTimestampStr)`
Melakukan INSERT batch ke PostgreSQL dalam **satu transaksi**.
- Gunakan `prepared statement` untuk efisiensi
- `defer tx.Rollback()` dan `defer stmt.Close()` mencegah resource leak
- Jika ada error parsial → tetap commit yang berhasil, log yang gagal
- Tulis hasil ke `error.log` (SUCCESS atau ERROR)

```sql
INSERT INTO sensor_logs (device_unique_id, parameter_name, value, recorded_at)
VALUES ($1, $2, $3, $4)
```

### `periodicBufferSync()`
Goroutine yang menyinkronkan in-memory buffer ke `buf2.json` setiap **15 detik**.
- Tidak menulis jika buffer kosong (hemat I/O disk)
- Mencegah kehilangan data jika program crash

### `autoRefreshSensorCache()`
Goroutine yang memperbarui `sensorHeightCache` dari MySQL setiap **60 detik**.
- Skip update jika query mengembalikan data kosong
- Interval 60 detik (diperpanjang dari 30 detik untuk hemat CPU)

### `statusMonitor()`
Goroutine yang mencetak ringkasan status sistem setiap **1 menit**:
```
📊 [STATUS] Messages: 1204 | AWLR: 48 | CH-Restart: 2 | Devices: 12 | Cache: 12 | CH-Track: 5 | Connected: true
```

---

## 🌐 WebSocket Dashboard (`ws.go`)

### Endpoint HTTP

| Route | Method | Keterangan |
|---|---|---|
| `/` | GET | Dashboard HTML monitoring real-time |
| `/ws` | WebSocket | Stream log real-time ke browser |
| `/stats` | GET | JSON snapshot statistik sistem |

### Dashboard (`http://localhost:8230`)
Antarmuka web dark-mode yang menampilkan:
- **Stats box:** Total flushes, total records, MQTT messages, AWLR calculations, CH restarts, devices, last flush time
- **Live log panel:** 50 log terakhir yang diterima via WebSocket

### `broadcastLog(msg LogMessage)`
Mengirim pesan ke semua client WebSocket yang terkoneksi.
Client yang error (disconnect) langsung dihapus dari daftar.

### Fungsi Log Broadcast

| Fungsi | Level |
|---|---|
| `LogInfo(type, msg, data)` | `info` (biru) |
| `LogSuccess(type, msg, data)` | `success` (hijau) |
| `LogWarning(type, msg, data)` | `warning` (oranye) |
| `LogError(type, msg, data)` | `error` (merah) |

---

## 🚀 Alur Startup (`main.go`)

```
1. Setup path file (CONFIG_JSON_PATH, BUFFER_FILE_PATH, dll.)
2. initPostgres() + initMySQL()
3. checkPostgresConnection() + checkMySQLConnection()
4. LoadBufferFromFile()          ← crash recovery
5. loadCHState()                 ← load akumulasi CH
6. updateConfigFromJSON()        ← load mapping device
7. getAllSensorHeightsFromMySQL() ← load cache AWLR
8. Setup MQTT client options
9. go flushToDB()                ← flush ke DB tiap 5 menit
10. go periodicBufferSync()      ← sync buffer ke file tiap 15 detik
11. go autoRefreshSensorCache()  ← refresh cache tiap 60 detik
12. go statusMonitor()           ← status log tiap 1 menit
13. go autoSaveCHState()         ← save CH state tiap 10 detik
14. go cleanOldLogs()            ← hapus log lama tiap 6 jam
15. go StartLogServer(8230)      ← WebSocket dashboard
16. client.Connect()             ← konek ke MQTT broker
17. configWatcher(client)        ← blocking, pantau perubahan config
```

### Shutdown (defer)
```
saveCHState()   ← simpan state CH sebelum keluar
pgDB.Close()
mysqlDB.Close()
```

---

## 🔒 Thread Safety

| Resource | Lock yang digunakan |
|---|---|
| `deviceMap`, `sensorHeightCache`, counters, `mqttConnected` | `stateLock` (sync.RWMutex) |
| `bufferData` (in-memory map) | `bufferMu` (sync.Mutex) |
| `chState` (LastValue, Accumulated, LastDate) | `chState.RWMutex` (embedded) |
| `error.log` | `errorLogMutex` (sync.Mutex) |
| WebSocket clients map | `clientsMu` (sync.RWMutex) |
| flush stats | `flushStats.RWMutex` (embedded) |

---

## 📊 Tabel Database

### PostgreSQL — `sensor_logs`
```sql
CREATE TABLE sensor_logs (
    id              SERIAL PRIMARY KEY,
    device_unique_id VARCHAR(50),
    parameter_name  VARCHAR(50),
    value           FLOAT,
    recorded_at     TIMESTAMP
);
```

### MySQL — `device_settings`
```sql
-- Kolom yang digunakan:
-- device_unique_id VARCHAR(50)
-- tinggi_sensor    FLOAT  (tinggi pemasangan sensor AWLR dalam cm)
```

---

## 📦 Dependensi (`go.mod`)

| Package | Versi | Fungsi |
|---|---|---|
| `github.com/eclipse/paho.mqtt.golang` | v1.4.3 | MQTT client library |
| `github.com/go-sql-driver/mysql` | v1.7.1 | MySQL driver |
| `github.com/lib/pq` | v1.10.9 | PostgreSQL driver |
| `github.com/gorilla/websocket` | v1.5.0 | WebSocket server |

---

## 🛠️ Build & Run

```bash
# Masuk ke folder
cd rekam-data/go_final

# Download dependensi
go mod tidy

# Build executable
go build -o rekam-data.exe .

# Jalankan
./rekam-data.exe
```

### Syarat
- Go 1.21+
- PostgreSQL berjalan di `127.0.0.1:5432`
- MySQL berjalan di `127.0.0.1:3306`
- File `../py/1.json` tersedia (konfigurasi perangkat)

---

## 🐛 Troubleshooting

| Masalah | Kemungkinan Penyebab | Solusi |
|---|---|---|
| `MQTT connection failed` | Broker tidak bisa diakses | Cek koneksi internet / status broker |
| `PostgreSQL init failed` | DB tidak jalan / DSN salah | Cek service PostgreSQL, cek kredensial di `globals.go` |
| `MySQL init failed` | DB tidak jalan / DSN salah | Cek service MySQL, cek kredensial di `globals.go` |
| Data CH tidak akurat | State file `ch_state.json` corrupt | Hapus file, program akan auto-reset state |
| Data tidak masuk DB tiap 5 menit | Buffer kosong / MQTT tidak terima data | Cek log MQTT connection, cek topic config di `1.json` |
| CPU tinggi | Terlalu banyak query DB saat startup CH | Normal hanya di awal; setelah state loaded akan normal |

---

*Dokumentasi ini dibuat untuk `go_final` — versi refactoring dari `rekam-data/go/main.go`.*
*Dibuat: 2026-04-30*
