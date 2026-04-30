package main

import (
	"database/sql"
	"sync"
	"time"
)

// ==========================
// CONFIGURATION
// ==========================
const (
	BROKER_HOST            = "karsacerdasinovatif.web.id"
	BROKER_PORT            = 8081
	FLUSH_INTERVAL_MINUTES = 5
	CACHE_REFRESH_INTERVAL = 30 * time.Second
	WIB_OFFSET             = 7 * time.Hour
	WEBSOCKET_LOG_PORT     = 8230

	// PostgreSQL
	POSTGRES_DSN = "host=127.0.0.1 port=5432 user=postgres password=example dbname=temins sslmode=disable application_name=rekam-data"

	// MySQL
	MYSQL_DSN = "root:06ec30fa@tcp(127.0.0.1:3306)/temins?parseTime=true&timeout=10s"
)

// ==========================
// PATH VARIABLES (set di main)
// ==========================
var (
	CONFIG_JSON_PATH string
	BUFFER_FILE_PATH string
	CH_STATE_FILE    string // File untuk menyimpan state CH
	ERROR_LOG_PATH   string
)

// ==========================
// GLOBAL DATABASE POOLS
// ==========================
var (
	pgDB    *sql.DB
	mysqlDB *sql.DB
)

// ==========================
// GLOBAL STATE & LOCKS
// ==========================
var (
	// Single lock untuk semua shared state
	stateLock         sync.RWMutex
	deviceMap         map[string]string
	subscribedTopics  map[string]bool
	sensorHeightCache map[string]float64

	// Counters (gunakan stateLock untuk akses)
	mqttMessageCount     int64
	awlrCalculationCount int64
	restartDetected      int64
	mqttConnected        bool

	// In-memory buffer (menggantikan file-based buffer agar tidak ada race condition)
	bufferMu   sync.Mutex
	bufferData = make(map[string]BufferData)

	// CH state — di-persist ke file
	chState struct {
		sync.RWMutex
		LastValue   map[string]float64 `json:"last_value"`
		Accumulated map[string]float64 `json:"accumulated"`
		LastDate    map[string]string  `json:"last_date"`
	}

	lastFileModTime time.Time

	errorLogMutex sync.Mutex
)

// ==========================
// DATA STRUCTURES
// ==========================

// BufferData menyimpan satu entri data sensor yang akan di-flush ke PostgreSQL.
type BufferData struct {
	DeviceID   string  `json:"device_id"`
	DeviceType string  `json:"device_type"`
	Parameter  string  `json:"parameter"`
	Value      float64 `json:"value"`
	Timestamp  string  `json:"timestamp"`
}

// DeviceConfig adalah konfigurasi satu perangkat dari file JSON.
type DeviceConfig struct {
	DevID      string   `json:"dev_id"`
	UseDefault bool     `json:"use_default"`
	Topic      []string `json:"topic"`
}

// DeviceTypeConfig adalah konfigurasi per-tipe perangkat.
type DeviceTypeConfig struct {
	DefTopic []string       `json:"def_topic"`
	Devices  []DeviceConfig `json:"devices"`
}

// Config adalah root struktur file konfigurasi JSON.
type Config struct {
	DeviceType map[string]DeviceTypeConfig `json:"device_type"`
}

// MQTTMessage adalah format payload JSON dari broker MQTT.
type MQTTMessage struct {
	Value interface{} `json:"value"`
}
