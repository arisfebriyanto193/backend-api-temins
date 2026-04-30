package main

import (
	"crypto/tls"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"time"

	mqtt "github.com/eclipse/paho.mqtt.golang"
	_ "github.com/go-sql-driver/mysql"
	_ "github.com/lib/pq"
)

// ==========================
// MAIN
// ==========================

func main() {
	log.SetFlags(log.Ldate | log.Ltime)

	// Setup path file-file pendukung berdasarkan lokasi executable
	baseDir, _ := filepath.Abs(filepath.Dir(os.Args[0]))
	CONFIG_JSON_PATH = filepath.Join(baseDir, "../py/1.json")
	BUFFER_FILE_PATH = filepath.Join(baseDir, "buf2.json")
	CH_STATE_FILE    = filepath.Join(baseDir, "ch_state.json")
	ERROR_LOG_PATH   = filepath.Join(baseDir, "error.log")

	log.Println("\n🚀 TEMINS IoT Logger - Refactored Version")
	log.Printf("🕐 Current Time (WIB): %s\n", formatWIBTimestamp(getWIBTime()))

	// ── 1. Inisialisasi Database ─────────────────────────────────────────────
	log.Println("🔄 [INIT] Initializing databases...")
	if err := initPostgres(); err != nil {
		log.Fatalf("❌ PostgreSQL init failed: %v\n", err)
	}
	if err := initMySQL(); err != nil {
		log.Fatalf("❌ MySQL init failed: %v\n", err)
	}
	if !checkPostgresConnection() || !checkMySQLConnection() {
		log.Fatal("❌ Database connection failed")
	}

	// ── 2. Crash Recovery: Load Buffer dari File (data sebelum crash) ─────────
	log.Println("🔄 [INIT] Checking buffer file for crash recovery...")
	LoadBufferFromFile()

	// ── 3. Load CH State (curah hujan akumulasi) ─────────────────────────────
	log.Println("🔄 [INIT] Loading CH state from file...")
	loadCHState()

	// ── 4. Load Konfigurasi Perangkat ────────────────────────────────────────
	log.Println("🔄 [INIT] Loading configuration...")
	newTopics, newMap := updateConfigFromJSON()
	stateLock.Lock()
	deviceMap = newMap
	subscribedTopics = newTopics
	stateLock.Unlock()

	// ── 5. Load Cache Tinggi Sensor dari MySQL ───────────────────────────────
	log.Println("🔄 [INIT] Loading sensor height cache...")
	initialData := getAllSensorHeightsFromMySQL()
	stateLock.Lock()
	sensorHeightCache = initialData
	stateLock.Unlock()

	// ── 6. Setup MQTT Client ─────────────────────────────────────────────────
	mqtt.ERROR = log.New(os.Stdout, "[MQTT-ERROR] ", log.LstdFlags)

	opts := mqtt.NewClientOptions()
	opts.AddBroker(fmt.Sprintf("wss://%s:%d/mqtt", BROKER_HOST, BROKER_PORT))
	opts.SetClientID(fmt.Sprintf("temins_logger_go_%d", time.Now().Unix()))
	opts.SetTLSConfig(&tls.Config{InsecureSkipVerify: true})
	opts.SetOnConnectHandler(onConnect)
	opts.SetDefaultPublishHandler(onMessage)
	opts.SetConnectionLostHandler(connectionLostHandler)
	opts.SetReconnectingHandler(reconnectHandler)
	opts.SetKeepAlive(60 * time.Second)
	opts.SetAutoReconnect(true)
	opts.SetMaxReconnectInterval(10 * time.Second)

	client := mqtt.NewClient(opts)

	// ── 7. Jalankan Goroutine Background ────────────────────────────────────
	go flushToDB()              // Flush buffer → PostgreSQL setiap 5 menit
	go periodicBufferSync()    // Sync in-memory buffer → JSON file setiap 15 detik
	go autoRefreshSensorCache() // Refresh cache tinggi sensor dari MySQL setiap 60 detik
	go statusMonitor()          // Print status ringkasan setiap 1 menit
	go autoSaveCHState()        // Simpan state CH setiap 10 detik
	go cleanOldLogs()           // Hapus log lama setiap 6 jam
	go StartLogServer(WEBSOCKET_LOG_PORT) // WebSocket dashboard monitor

	time.Sleep(1 * time.Second)

	// ── 8. Koneksi ke MQTT Broker ────────────────────────────────────────────
	log.Printf("🔌 Connecting to MQTT broker...\n")
	if token := client.Connect(); token.Wait() && token.Error() != nil {
		log.Fatalf("❌ MQTT connection failed: %v\n", token.Error())
	}

	log.Println("✅ System ready\n")

	// ── 9. Cleanup saat shutdown ─────────────────────────────────────────────
	defer func() {
		log.Println("\n🛑 Shutting down...")
		saveCHState() // Simpan state CH sebelum keluar
		if pgDB != nil {
			pgDB.Close()
		}
		if mysqlDB != nil {
			mysqlDB.Close()
		}
	}()

	// Blocking: pantau perubahan file konfigurasi (berjalan selamanya)
	configWatcher(client)
}
