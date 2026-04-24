package main

import (
	"crypto/tls"
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"

	mqtt "github.com/eclipse/paho.mqtt.golang"
	_ "github.com/go-sql-driver/mysql"
	_ "github.com/lib/pq"
)

// ==========================
// CONFIGURATION
// ==========================
const (
	BROKER_HOST              = "karsacerdasinovatif.web.id"
	BROKER_PORT              = 8081
	FLUSH_INTERVAL_MINUTES   = 5
	CACHE_REFRESH_INTERVAL   = 30 * time.Second
	WIB_OFFSET               = 7 * time.Hour
	WEBSOCKET_LOG_PORT       = 8230
	
	// PostgreSQL
	POSTGRES_DSN = "host=127.0.0.1 port=5432 user=postgres password=example dbname=temins sslmode=disable application_name=rekam-data"
	
	// MySQL
	MYSQL_DSN = "root:06ec30fa@tcp(127.0.0.1:3306)/temins?parseTime=true&timeout=10s"
)

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
	// Locks - dikurangi granularitas untuk performa
	stateLock         sync.RWMutex // Single lock untuk semua state
	deviceMap         map[string]string
	subscribedTopics  map[string]bool
	sensorHeightCache map[string]float64
	
	// Counters - atomic operations via stateLock
	mqttMessageCount     int64
	awlrCalculationCount int64
	restartDetected      int64
	mqttConnected        bool
	
	// CH state - sekarang di-persist ke file
	chState struct {
		sync.RWMutex
		LastValue    map[string]float64 `json:"last_value"`
		Accumulated  map[string]float64 `json:"accumulated"`
		LastDate     map[string]string  `json:"last_date"`
	}
	
	lastFileModTime time.Time
	
	errorLogMutex sync.Mutex
)

// ==========================
// DATA STRUCTURES
// ==========================
type BufferData struct {
	DeviceID   string    `json:"device_id"`
	DeviceType string    `json:"device_type"`
	Parameter  string    `json:"parameter"`
	Value      float64   `json:"value"`
	Timestamp  string    `json:"timestamp"`
}

type DeviceConfig struct {
	DevID      string   `json:"dev_id"`
	UseDefault bool     `json:"use_default"`
	Topic      []string `json:"topic"`
}

type DeviceTypeConfig struct {
	DefTopic []string       `json:"def_topic"`
	Devices  []DeviceConfig `json:"devices"`
}

type Config struct {
	DeviceType map[string]DeviceTypeConfig `json:"device_type"`
}

type MQTTMessage struct {
	Value interface{} `json:"value"`
}

// ==========================
// LOG FILE HANDLER
// ==========================

func writeLogToFile(level, message string) {
	errorLogMutex.Lock()
	defer errorLogMutex.Unlock()
	
	if ERROR_LOG_PATH == "" {
		return
	}
	
	now := getWIBTime()
	logLine := fmt.Sprintf("[%s] %s: %s\n", formatWIBTimestamp(now), level, message)
	
	f, err := os.OpenFile(ERROR_LOG_PATH, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	if err != nil {
		log.Printf("⚠️ Failed to open error.log: %v", err)
		return
	}
	defer f.Close()
	f.WriteString(logLine)
}

func cleanOldLogs() {
	for {
		time.Sleep(6 * time.Hour)
		
		errorLogMutex.Lock()
		if ERROR_LOG_PATH != "" {
			data, err := os.ReadFile(ERROR_LOG_PATH)
			if err == nil {
				lines := strings.Split(string(data), "\n")
				var newLines []string
				twoDaysAgo := getWIBTime().Add(-48 * time.Hour)
				
				for _, line := range lines {
					line = strings.TrimSpace(line)
					if len(line) < 21 {
						if len(line) > 0 {
							newLines = append(newLines, line)
						}
						continue
					}
					if strings.HasPrefix(line, "[") {
						timestampStr := line[1:20]
						t, err := time.Parse("2006-01-02 15:04:05", timestampStr)
						if err == nil {
							if t.After(twoDaysAgo) {
								newLines = append(newLines, line)
							}
						} else {
							newLines = append(newLines, line)
						}
					} else {
						newLines = append(newLines, line)
					}
				}
				
				if len(newLines) > 0 {
					os.WriteFile(ERROR_LOG_PATH, []byte(strings.Join(newLines, "\n")+"\n"), 0644)
				} else {
					os.WriteFile(ERROR_LOG_PATH, []byte(""), 0644)
				}
			}
		}
		errorLogMutex.Unlock()
	}
}

// ==========================
// UTILITY FUNCTIONS
// ==========================
func getWIBTime() time.Time {
	return time.Now().UTC().Add(WIB_OFFSET)
}

func formatWIBTimestamp(t time.Time) string {
	return t.Format("2006-01-02 15:04:05")
}

func formatWIBDate(t time.Time) string {
	return t.Format("2006-01-02")
}

func getNext5MinInterval() (time.Time, time.Duration) {
	nowWIB := getWIBTime()
	currentMinute := nowWIB.Minute()
	nextMinute := ((currentMinute / 5) + 1) * 5
	
	var nextInterval time.Time
	if nextMinute >= 60 {
		nextInterval = nowWIB.Add(time.Hour)
		nextInterval = time.Date(nextInterval.Year(), nextInterval.Month(), nextInterval.Day(),
			nextInterval.Hour(), 0, 0, 0, nextInterval.Location())
	} else {
		nextInterval = time.Date(nowWIB.Year(), nowWIB.Month(), nowWIB.Day(),
			nowWIB.Hour(), nextMinute, 0, 0, nowWIB.Location())
	}
	
	return nextInterval, nextInterval.Sub(nowWIB)
}

func getRounded5MinTimestamp() time.Time {
	nowWIB := getWIBTime()
	currentMinute := nowWIB.Minute()
	roundedMinute := (currentMinute / 5) * 5
	
	return time.Date(nowWIB.Year(), nowWIB.Month(), nowWIB.Day(),
		nowWIB.Hour(), roundedMinute, 0, 0, nowWIB.Location())
}

// ==========================
// CH STATE PERSISTENCE
// ==========================

// loadCHState loads CH state from file
func loadCHState() {
	chState.Lock()
	defer chState.Unlock()
	
	if _, err := os.Stat(CH_STATE_FILE); os.IsNotExist(err) {
		// Initialize empty state
		chState.LastValue = make(map[string]float64)
		chState.Accumulated = make(map[string]float64)
		chState.LastDate = make(map[string]string)
		return
	}
	
	data, err := os.ReadFile(CH_STATE_FILE)
	if err != nil {
		log.Printf("⚠️ [CH-STATE] Failed to read: %v\n", err)
		chState.LastValue = make(map[string]float64)
		chState.Accumulated = make(map[string]float64)
		chState.LastDate = make(map[string]string)
		return
	}
	
	var state struct {
		LastValue   map[string]float64 `json:"last_value"`
		Accumulated map[string]float64 `json:"accumulated"`
		LastDate    map[string]string  `json:"last_date"`
	}
	
	if err := json.Unmarshal(data, &state); err != nil {
		log.Printf("⚠️ [CH-STATE] Failed to parse: %v\n", err)
		chState.LastValue = make(map[string]float64)
		chState.Accumulated = make(map[string]float64)
		chState.LastDate = make(map[string]string)
		return
	}
	
	chState.LastValue = state.LastValue
	chState.Accumulated = state.Accumulated
	chState.LastDate = state.LastDate
	
	log.Printf("✅ [CH-STATE] Loaded %d devices from file\n", len(chState.LastValue))
}

// saveCHState saves CH state to file (async, non-blocking)
func saveCHState() {
	chState.RLock()
	state := struct {
		LastValue   map[string]float64 `json:"last_value"`
		Accumulated map[string]float64 `json:"accumulated"`
		LastDate    map[string]string  `json:"last_date"`
	}{
		LastValue:   chState.LastValue,
		Accumulated: chState.Accumulated,
		LastDate:    chState.LastDate,
	}
	chState.RUnlock()
	
	data, err := json.Marshal(state)
	if err != nil {
		log.Printf("❌ [CH-STATE] Marshal error: %v\n", err)
		return
	}
	
	if err := os.WriteFile(CH_STATE_FILE, data, 0644); err != nil {
		log.Printf("❌ [CH-STATE] Write error: %v\n", err)
	}
}

// Auto-save CH state every 10 seconds (async)
func autoSaveCHState() {
	ticker := time.NewTicker(10 * time.Second)
	for range ticker.C {
		saveCHState()
	}
}

// ==========================
// CURAH HUJAN FUNCTIONS
// ==========================

func getLastValueFromDB(deviceID string, parameter string, date string) float64 {
	if pgDB == nil {
		log.Printf("❌ [DB] PostgreSQL pool not initialized for %s\n", parameter)
		return 0
	}

	var lastValue float64
	query := `
		SELECT value 
		FROM sensor_logs 
		WHERE device_unique_id = $1 
		  AND parameter_name = $2 
		  AND recorded_at::text LIKE $3 || '%' 
		ORDER BY recorded_at DESC 
		LIMIT 1`

	err := pgDB.QueryRow(query, deviceID, parameter, date).Scan(&lastValue)
	if err != nil {
		if err == sql.ErrNoRows {
			log.Printf("⚠️ [DB] No %s data found for %s at %s\n", parameter, deviceID, date)
			return 0
		}
		log.Printf("❌ [DB] Query error in getLastValueFromDB: %v\n", err)
		return 0
	}

	log.Printf("📦 [DB] Last %s from %s for %s = %.2f\n", parameter, date, deviceID, lastValue)
	return lastValue
}

func processCurahHujan(deviceID string, chValue float64) (float64, bool) {
	now := getWIBTime()
	currentDate := formatWIBDate(now)

	chState.Lock()
	defer chState.Unlock()

	lastDate, dateExists := chState.LastDate[deviceID]

	// HARI BARU → RESET (Atau Start Up baru)
	if !dateExists || lastDate != currentDate {
		log.Printf("🌅 [CH] Init state for %s on %s", deviceID, currentDate)

		var initialAccum float64
		
		// Coba ambil dari data terakhir hari ini langsung dari database (jika program restart tapi hari yang sama)
		lastCHA := getLastValueFromDB(deviceID, "cha", currentDate)
		lastRawCH := getLastValueFromDB(deviceID, "ch", currentDate)
		
		if lastCHA > 0 {
			log.Printf("📥 [CH] Recovered CHA state for %s: %.2f mm (Last Raw CH: %.2f)", deviceID, lastCHA, lastRawCH)
			
			if chValue < lastRawCH {
				// Sensor telah restart (mulai dari 0 atau chValue lebih kecil dari lastRawCH).
				// Artinya hujan yang baru (chValue) murni tambahan baru sejak sensor mati lampu.
				initialAccum = lastCHA + chValue
			} else {
				// Sensor tidak restart (nilai tip >= lastRawCH).
				// Kita cari selisih kenaikannya saja lalu tambahkan ke lastCHA.
				diff := chValue - lastRawCH
				initialAccum = lastCHA + diff
			}
		} else {
			// Jika dari API 0 atau hari baru (00:00) yang sebenarnya
			log.Printf("🌅 [CH] Resetting state for %s → RESET", deviceID)
			initialAccum = chValue
		}
		
		chState.LastDate[deviceID] = currentDate
		chState.LastValue[deviceID] = chValue
		chState.Accumulated[deviceID] = initialAccum
		
		return initialAccum, false
	}

	lastValue := chState.LastValue[deviceID]
	currentAccumulation := chState.Accumulated[deviceID]

	// RESTART (nilai turun)
	if chValue < lastValue {
		stateLock.Lock()
		restartDetected++
		stateLock.Unlock()

		newAccumulation := currentAccumulation + chValue
		chState.Accumulated[deviceID] = newAccumulation
		chState.LastValue[deviceID] = chValue

		log.Printf("🔄 [CH] Restart detected → +%.2f mm", chValue)
		return newAccumulation, true
	}

	// NORMAL NAIK
	diff := chValue - lastValue
	newAccumulation := currentAccumulation + diff

	chState.Accumulated[deviceID] = newAccumulation
	chState.LastValue[deviceID] = chValue

	log.Printf("🌧️ [CH] Normal increase → +%.2f mm", diff)
	return newAccumulation, false
}

// ==========================
// DATABASE INITIALIZATION
// ==========================

func initPostgres() error {
	var err error
	pgDB, err = sql.Open("postgres", POSTGRES_DSN)
	if err != nil {
		return fmt.Errorf("failed to open postgres: %w", err)
	}
	
	pgDB.SetMaxOpenConns(5)
	pgDB.SetMaxIdleConns(2)
	pgDB.SetConnMaxLifetime(5 * time.Minute)
	pgDB.SetConnMaxIdleTime(2 * time.Minute)
	
	if err := pgDB.Ping(); err != nil {
		pgDB.Close()
		return fmt.Errorf("failed to ping postgres: %w", err)
	}
	
	log.Println("✅ [PostgreSQL] Connection pool initialized (Max: 5 conns)")
	return nil
}

func initMySQL() error {
	var err error
	mysqlDB, err = sql.Open("mysql", MYSQL_DSN)
	if err != nil {
		return fmt.Errorf("failed to open mysql: %w", err)
	}
	
	mysqlDB.SetMaxOpenConns(3)
	mysqlDB.SetMaxIdleConns(1)
	mysqlDB.SetConnMaxLifetime(5 * time.Minute)
	mysqlDB.SetConnMaxIdleTime(2 * time.Minute)
	
	if err := mysqlDB.Ping(); err != nil {
		mysqlDB.Close()
		return fmt.Errorf("failed to ping mysql: %w", err)
	}
	
	log.Println("✅ [MySQL] Connection pool initialized (Max: 3 conns)")
	return nil
}

// ==========================
// DATABASE FUNCTIONS
// ==========================

func checkPostgresConnection() bool {
	if pgDB == nil {
		log.Println("❌ [PostgreSQL] Pool not initialized")
		return false
	}
	
	if err := pgDB.Ping(); err != nil {
		log.Printf("❌ [PostgreSQL] Ping FAILED: %v\n", err)
		return false
	}
	
	log.Println("✅ [PostgreSQL] Database Connected")
	return true
}

func checkMySQLConnection() bool {
	if mysqlDB == nil {
		log.Println("❌ [MySQL] Pool not initialized")
		return false
	}
	
	if err := mysqlDB.Ping(); err != nil {
		log.Printf("❌ [MySQL] Ping FAILED: %v\n", err)
		return false
	}
	
	log.Println("✅ [MySQL] Database Connected")
	return true
}

func getAllSensorHeightsFromMySQL() map[string]float64 {
	if mysqlDB == nil {
		log.Println("❌ [MySQL] Pool not initialized")
		return make(map[string]float64)
	}
	
	rows, err := mysqlDB.Query("SELECT device_unique_id, tinggi_sensor FROM device_settings")
	if err != nil {
		log.Printf("❌ [MySQL] Query error: %v\n", err)
		return make(map[string]float64)
	}
	defer rows.Close()
	
	mysqlData := make(map[string]float64)
	skippedCount := 0
	
	for rows.Next() {
		var deviceID string
		var tinggiSensor sql.NullFloat64
		
		if err := rows.Scan(&deviceID, &tinggiSensor); err != nil {
			continue
		}
		
		if deviceID == "" || !tinggiSensor.Valid {
			skippedCount++
			continue
		}
		
		if tinggiSensor.Float64 <= 0 || tinggiSensor.Float64 > 10000 {
			skippedCount++
			continue
		}
		
		mysqlData[deviceID] = tinggiSensor.Float64
	}
	
	if skippedCount > 0 {
		log.Printf("⚠️ [MySQL] Skipped %d devices with invalid tinggi_sensor\n", skippedCount)
	}
	
	log.Printf("✅ [MySQL] Loaded %d devices into cache\n", len(mysqlData))
	return mysqlData
}

func getSensorHeightFromCache(deviceID string) (float64, bool) {
	stateLock.RLock()
	defer stateLock.RUnlock()
	height, exists := sensorHeightCache[deviceID]
	return height, exists
}

// ==========================
// BUFFER FILE HANDLER
// ==========================

func saveToBufferFile(deviceID, parameter string, value float64) {
	// Read existing buffer
	data := make(map[string]BufferData)
	if fileData, err := os.ReadFile(BUFFER_FILE_PATH); err == nil {
		json.Unmarshal(fileData, &data)
	}
	
	// Get device type
	stateLock.RLock()
	deviceType := deviceMap[deviceID]
	if deviceType == "" {
		deviceType = "Unknown Type"
	}
	stateLock.RUnlock()
	
	timestamp := formatWIBTimestamp(getWIBTime())
	
	// Handle CH parameter
	if parameter == "ch" {
		// Save original CH value
		key := fmt.Sprintf("%s|%s", deviceID, parameter)
		data[key] = BufferData{
			DeviceID:   deviceID,
			DeviceType: deviceType,
			Parameter:  parameter,
			Value:      value,
			Timestamp:  timestamp,
		}
		
		log.Printf("📥 [BUFFER] Saved CH: Device=%s, Value=%.2f mm\n", deviceID, value)
		
		// Calculate and save CHA
		chaValue, restartDetected := processCurahHujan(deviceID, value)
		chaKey := fmt.Sprintf("%s|cha", deviceID)
		data[chaKey] = BufferData{
			DeviceID:   deviceID,
			DeviceType: deviceType,
			Parameter:  "cha",
			Value:      chaValue,
			Timestamp:  timestamp,
		}
		
		if restartDetected {
			log.Printf("📥 [BUFFER] Saved CHA: Device=%s, Value=%.2f mm (⚠️ RESTART)\n", deviceID, chaValue)
		} else {
			log.Printf("📥 [BUFFER] Saved CHA: Device=%s, Value=%.2f mm\n", deviceID, chaValue)
		}
	} else {
		// Normal parameter
		key := fmt.Sprintf("%s|%s", deviceID, parameter)
		data[key] = BufferData{
			DeviceID:   deviceID,
			DeviceType: deviceType,
			Parameter:  parameter,
			Value:      value,
			Timestamp:  timestamp,
		}
	}
	
	// AWLR Calculation
	if strings.ToLower(deviceType) == "awlr" && parameter == "tuc" {
		if tinggiSensor, exists := getSensorHeightFromCache(deviceID); exists {
			tinggiAir := tinggiSensor - value
			airKey := fmt.Sprintf("%s|result_tinggi_air", deviceID)
			data[airKey] = BufferData{
				DeviceID:   deviceID,
				DeviceType: deviceType,
				Parameter:  "result_tinggi_air",
				Value:      tinggiAir,
				Timestamp:  timestamp,
			}
			
			stateLock.Lock()
			awlrCalculationCount++
			stateLock.Unlock()
		}
	}
	
	// Write to file
	if jsonData, err := json.MarshalIndent(data, "", "    "); err == nil {
		os.WriteFile(BUFFER_FILE_PATH, jsonData, 0644)
	}
}

func readAndClearBuffer() []BufferData {
	if _, err := os.Stat(BUFFER_FILE_PATH); os.IsNotExist(err) {
		return []BufferData{}
	}
	
	fileData, err := os.ReadFile(BUFFER_FILE_PATH)
	if err != nil {
		log.Printf("❌ [BUFFER] Read error: %v\n", err)
		return []BufferData{}
	}
	
	dataMap := make(map[string]BufferData)
	if err := json.Unmarshal(fileData, &dataMap); err != nil {
		log.Printf("❌ [BUFFER] Parse error: %v\n", err)
		return []BufferData{}
	}
	
	log.Printf("📊 [BUFFER] Entries to flush: %d\n", len(dataMap))
	
	// Clear file
	emptyData, _ := json.Marshal(map[string]interface{}{})
	os.WriteFile(BUFFER_FILE_PATH, emptyData, 0644)
	
	// Convert to slice
	result := make([]BufferData, 0, len(dataMap))
	for _, v := range dataMap {
		result = append(result, v)
	}
	
	return result
}

// ==========================
// CONFIG LOADER
// ==========================

func updateConfigFromJSON() (map[string]bool, map[string]string) {
	newTopics := make(map[string]bool)
	newMap := make(map[string]string)
	
	if _, err := os.Stat(CONFIG_JSON_PATH); os.IsNotExist(err) {
		log.Printf("⚠️ [CONFIG] File not found: %s\n", CONFIG_JSON_PATH)
		return newTopics, newMap
	}
	
	fileData, err := os.ReadFile(CONFIG_JSON_PATH)
	if err != nil {
		log.Printf("❌ [CONFIG] Read error: %v\n", err)
		return newTopics, newMap
	}
	
	var config Config
	if err := json.Unmarshal(fileData, &config); err != nil {
		log.Printf("❌ [CONFIG] Parse error: %v\n", err)
		return newTopics, newMap
	}
	
	log.Println("\n📋 [CONFIG] Loading device configuration:")
	
	for typeName, typeData := range config.DeviceType {
		for _, dev := range typeData.Devices {
			newMap[dev.DevID] = typeName
			
			params := make(map[string]bool)
			if dev.UseDefault {
				for _, p := range typeData.DefTopic {
					params[p] = true
				}
			}
			for _, p := range dev.Topic {
				params[p] = true
			}
			
			log.Printf("   📱 Device: %s | Type: %s\n", dev.DevID, typeName)
			
			for param := range params {
				topic := fmt.Sprintf("temins_iot/%s/data/%s", dev.DevID, param)
				newTopics[topic] = true
			}
		}
	}
	
	log.Printf("\n✅ [CONFIG] Total: %d devices, %d topics\n\n", len(newMap), len(newTopics))
	return newTopics, newMap
}

// ==========================
// MQTT HANDLERS
// ==========================

var connectionLostHandler mqtt.ConnectionLostHandler = func(client mqtt.Client, err error) {
	stateLock.Lock()
	mqttConnected = false
	stateLock.Unlock()
	log.Printf("⚠️ [MQTT] Connection Lost: %v\n", err)
}

var reconnectHandler mqtt.ReconnectHandler = func(client mqtt.Client, opts *mqtt.ClientOptions) {
	log.Println("🔄 [MQTT] Attempting to reconnect...")
}

func onConnect(client mqtt.Client) {
	stateLock.Lock()
	mqttConnected = true
	stateLock.Unlock()
	
	log.Println("✅ [MQTT] Connected to Broker")
	client.Subscribe("temins_iot/#", 0, nil)
	log.Println("📡 [MQTT] Subscribed to temins_iot/#")
}

func onMessage(client mqtt.Client, msg mqtt.Message) {
	stateLock.Lock()
	mqttMessageCount++
	stateLock.Unlock()
	
	payload := string(msg.Payload())
	topic := msg.Topic()
	
	parts := strings.Split(topic, "/")
	if len(parts) < 4 || parts[2] != "data" {
		return
	}
	
	deviceID := parts[1]
	parameter := parts[len(parts)-1]
	
	var value float64
	
	// Try JSON parsing
	var msgData MQTTMessage
	if err := json.Unmarshal([]byte(payload), &msgData); err == nil {
		switch v := msgData.Value.(type) {
		case float64:
			value = v
		case string:
			value, _ = strconv.ParseFloat(v, 64)
		default:
			return
		}
	} else {
		// Try direct parsing
		if strings.Contains(payload, " - ") {
			parts := strings.Split(payload, " - ")
			value, _ = strconv.ParseFloat(parts[0], 64)
		} else {
			parsedValue, err := strconv.ParseFloat(payload, 64)
			if err != nil {
				return
			}
			value = parsedValue
		}
	}
	
	saveToBufferFile(deviceID, parameter, value)
}

// ==========================
// BACKGROUND THREADS
// ==========================

func flushToDB() {
	nextInterval, secondsToWait := getNext5MinInterval()
	log.Printf("⏰ [DB] Next flush at: %s WIB\n", formatWIBTimestamp(nextInterval))
	// Tambah 2 detik agar tidak dieksekusi sebelum ms interval berikutnya (mencegah timestamp meleset dan loop kosong)
	time.Sleep(secondsToWait + 2*time.Second)
	
	for {
		batchTimestamp := getRounded5MinTimestamp()
		batchTimestampStr := formatWIBTimestamp(batchTimestamp)
		
		dataToSave := readAndClearBuffer()
		
		if len(dataToSave) == 0 {
			stateLock.RLock()
			log.Printf("📊 [STATUS] MQTT: %d | AWLR: %d | CH-Restart: %d\n",
				mqttMessageCount, awlrCalculationCount, restartDetected)
			stateLock.RUnlock()
		} else {
			if pgDB == nil {
				msg := "PostgreSQL pool not initialized"
				log.Printf("❌ [DB] %s\n", msg)
				writeLogToFile("ERROR", msg)
			} else {
				tx, err := pgDB.Begin()
				if err != nil {
					msg := fmt.Sprintf("Transaction error: %v", err)
					log.Printf("❌ [DB] %s\n", msg)
					writeLogToFile("ERROR", msg)
				} else {
					defer tx.Rollback()
					
					stmt, err := tx.Prepare("INSERT INTO sensor_logs (device_unique_id, parameter_name, value, recorded_at) VALUES ($1, $2, $3, $4)")
					if err != nil {
						msg := fmt.Sprintf("Prepare error: %v", err)
						log.Printf("❌ [DB] %s\n", msg)
						writeLogToFile("ERROR", msg)
					} else {
						defer stmt.Close()
						
						successCount := 0
						var errMessages []string
						
						for _, d := range dataToSave {
							_, err := stmt.Exec(d.DeviceID, d.Parameter, d.Value, batchTimestampStr)
							if err != nil {
								msg := fmt.Sprintf("Insert failed (%s %s): %v", d.DeviceID, d.Parameter, err)
								log.Printf("❌ [DB] %s\n", msg)
								errMessages = append(errMessages, msg)
							} else {
								successCount++
							}
						}
						
						if len(errMessages) > 0 {
							writeLogToFile("ERROR", strings.Join(errMessages, " ; "))
						}
						
						// Jika ada setidaknya 1 insert yang berhasil, maka commit
						if successCount > 0 {
							if err := tx.Commit(); err != nil {
								msg := fmt.Sprintf("Commit error: %v", err)
								log.Printf("❌ [DB] %s\n", msg)
								writeLogToFile("ERROR", msg)
							} else {
								msg := fmt.Sprintf("Berhasil menyimpan %d data dari total %d data pada %s", successCount, len(dataToSave), batchTimestampStr)
								log.Printf("💾 [DB] Flushed %d records at %s\n", successCount, batchTimestampStr)
								writeLogToFile("SUCCESS", msg)
								
								for _, d := range dataToSave {
									emoji := "✅"
									if strings.Contains(d.Parameter, "result_tinggi_air") {
										emoji = "🌊"
									} else if d.Parameter == "cha" {
										emoji = "☔"
									} else if d.Parameter == "ch" {
										emoji = "🌧️"
									}
									log.Printf("   %s %s | %s = %.2f\n", emoji, d.DeviceID, d.Parameter, d.Value)
								}
							}
						} else {
							writeLogToFile("ERROR", "Semua data gagal disimpan ke database")
						}
					}
				}
			}
		}
		
		nextInterval, secondsToWait = getNext5MinInterval()
		time.Sleep(secondsToWait + 2*time.Second)
	}
}

func configWatcher(client mqtt.Client) {
	for {
		time.Sleep(10 * time.Second)
		
		fileInfo, err := os.Stat(CONFIG_JSON_PATH)
		if err != nil {
			continue
		}
		
		if fileInfo.ModTime() != lastFileModTime {
			lastFileModTime = fileInfo.ModTime()
			log.Println("\n🔄 [WATCHER] Config file changed, reloading...")
			
			newTopics, newMap := updateConfigFromJSON()
			
			stateLock.Lock()
			deviceMap = newMap
			
			// Update subscriptions
			for topic := range newTopics {
				if !subscribedTopics[topic] {
					client.Subscribe(topic, 0, nil)
				}
			}
			subscribedTopics = newTopics
			stateLock.Unlock()
		}
	}
}

func autoRefreshSensorCache() {
	iteration := 0
	for {
		time.Sleep(CACHE_REFRESH_INTERVAL)
		iteration++
		
		log.Printf("\n🔄 [AUTO-REFRESH #%d] Refreshing cache...\n", iteration)
		
		mysqlData := getAllSensorHeightsFromMySQL()
		if len(mysqlData) == 0 {
			continue
		}
		
		stateLock.Lock()
		sensorHeightCache = mysqlData
		stateLock.Unlock()
		
		log.Printf("✓ [AUTO-REFRESH #%d] Cache updated (%d devices)\n\n", iteration, len(mysqlData))
	}
}

func statusMonitor() {
	ticker := time.NewTicker(1 * time.Minute)
	for range ticker.C {
		stateLock.RLock()
		deviceCount := len(deviceMap)
		cacheCount := len(sensorHeightCache)
		stateLock.RUnlock()
		
		chState.RLock()
		chDeviceCount := len(chState.LastValue)
		chState.RUnlock()
		
		stateLock.RLock()
		log.Printf("\n📊 [STATUS] Messages: %d | AWLR: %d | CH-Restart: %d | Devices: %d | Cache: %d | CH-Track: %d | Connected: %v\n\n",
			mqttMessageCount, awlrCalculationCount, restartDetected, deviceCount, cacheCount, chDeviceCount, mqttConnected)
		stateLock.RUnlock()
	}
}

// ==========================
// MAIN
// ==========================

func main() {
	log.SetFlags(log.Ldate | log.Ltime)
	
	// Setup paths
	baseDir, _ := filepath.Abs(filepath.Dir(os.Args[0]))
	CONFIG_JSON_PATH = filepath.Join(baseDir, "../py/1.json")
	BUFFER_FILE_PATH = filepath.Join(baseDir, "buf2.json")
	CH_STATE_FILE = filepath.Join(baseDir, "ch_state.json")
	ERROR_LOG_PATH = filepath.Join(baseDir, "error.log")
	
	log.Println("\n🚀 TEMINS IoT Logger - Optimized Version")
	log.Printf("🕐 Current Time (WIB): %s\n", formatWIBTimestamp(getWIBTime()))
	
	// Initialize databases
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
	
	// Load CH state from file
	log.Println("🔄 [INIT] Loading CH state from file...")
	loadCHState()
	
	// Load config
	log.Println("🔄 [INIT] Loading configuration...")
	newTopics, newMap := updateConfigFromJSON()
	stateLock.Lock()
	deviceMap = newMap
	subscribedTopics = newTopics
	stateLock.Unlock()
	
	// Load sensor cache
	log.Println("🔄 [INIT] Loading sensor cache...")
	initialData := getAllSensorHeightsFromMySQL()
	stateLock.Lock()
	sensorHeightCache = initialData
	stateLock.Unlock()
	
	// MQTT setup
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
	
	// Start background threads
	go flushToDB()
	go autoRefreshSensorCache()
	go statusMonitor()
	go autoSaveCHState()
	go cleanOldLogs()
	go StartLogServer(WEBSOCKET_LOG_PORT)
	
	time.Sleep(1 * time.Second)
	
	// Connect MQTT
	log.Printf("🔌 Connecting to MQTT...\n")
	if token := client.Connect(); token.Wait() && token.Error() != nil {
		log.Fatalf("❌ MQTT connection failed: %v\n", token.Error())
	}
	
	log.Println("✅ System ready\n")
	
	// Cleanup
	defer func() {
		log.Println("\n🛑 Shutting down...")
		saveCHState() // Save CH state before exit
		if pgDB != nil {
			pgDB.Close()
		}
		if mysqlDB != nil {
			mysqlDB.Close()
		}
	}()
	
	configWatcher(client)
}