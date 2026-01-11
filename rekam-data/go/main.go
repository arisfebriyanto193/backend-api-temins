package main

import (
	"crypto/tls"
	"database/sql"
	"encoding/json"
	"fmt"
	"io/ioutil"
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
)

var (
	CONFIG_JSON_PATH string
	BUFFER_FILE_PATH string
)

// Database configurations
const (
	POSTGRES_DSN = "host=127.0.0.1 port=5432 user=postgres password=example dbname=temins sslmode=disable"
	MYSQL_DSN    = "root:06ec30fa@tcp(127.0.0.1:3306)/temins?parseTime=true&timeout=10s"
)

// ==========================
// GLOBAL DATABASE POOLS
// ==========================
var (
	pgDB    *sql.DB // PostgreSQL global connection pool
	mysqlDB *sql.DB // MySQL global connection pool
)

// ==========================
// GLOBAL STATE & LOCKS
// ==========================
var (
	fileLock            sync.Mutex
	mapLock             sync.RWMutex
	cacheLock           sync.RWMutex
	subscribedTopics    = make(map[string]bool)
	deviceMap           = make(map[string]string) // device_id -> device_type
	sensorHeightCache   = make(map[string]float64)
	lastFileModTime     time.Time
	mqttMessageCount    int64
	awlrCalculationCount int64
	mqttConnected       bool
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
// UTILITY FUNCTIONS
// ==========================
func getWIBTime() time.Time {
	return time.Now().UTC().Add(WIB_OFFSET)
}

func formatWIBTimestamp(t time.Time) string {
	return t.Format("2006-01-02 15:04:05")
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
	
	secondsToWait := nextInterval.Sub(nowWIB)
	return nextInterval, secondsToWait
}

func getRounded5MinTimestamp() time.Time {
	nowWIB := getWIBTime()
	currentMinute := nowWIB.Minute()
	roundedMinute := (currentMinute / 5) * 5
	
	return time.Date(nowWIB.Year(), nowWIB.Month(), nowWIB.Day(),
		nowWIB.Hour(), roundedMinute, 0, 0, nowWIB.Location())
}

// ==========================
// DATABASE INITIALIZATION
// ==========================

// initPostgres initializes global PostgreSQL connection pool
func initPostgres() error {
	var err error
	pgDB, err = sql.Open("postgres", POSTGRES_DSN)
	if err != nil {
		return fmt.Errorf("failed to open postgres: %w", err)
	}
	
	// Connection pool settings for rekam-data (max 5 connections)
	pgDB.SetMaxOpenConns(5)
	pgDB.SetMaxIdleConns(2)
	pgDB.SetConnMaxLifetime(5 * time.Minute)
	pgDB.SetConnMaxIdleTime(2 * time.Minute)
	
	// Test connection
	if err := pgDB.Ping(); err != nil {
		pgDB.Close()
		return fmt.Errorf("failed to ping postgres: %w", err)
	}
	
	log.Println("✅ [PostgreSQL] Global connection pool initialized (Max: 5 conns)")
	return nil
}

// initMySQL initializes global MySQL connection pool
func initMySQL() error {
	var err error
	mysqlDB, err = sql.Open("mysql", MYSQL_DSN)
	if err != nil {
		return fmt.Errorf("failed to open mysql: %w", err)
	}
	
	// Connection pool settings
	mysqlDB.SetMaxOpenConns(3)
	mysqlDB.SetMaxIdleConns(1)
	mysqlDB.SetConnMaxLifetime(5 * time.Minute)
	mysqlDB.SetConnMaxIdleTime(2 * time.Minute)
	
	// Test connection
	if err := mysqlDB.Ping(); err != nil {
		mysqlDB.Close()
		return fmt.Errorf("failed to ping mysql: %w", err)
	}
	
	log.Println("✅ [MySQL] Global connection pool initialized (Max: 3 conns)")
	return nil
}

// ==========================
// DATABASE FUNCTIONS (FIXED)
// ==========================

// checkPostgresConnection uses global pool (no new connections)
func checkPostgresConnection() bool {
	if pgDB == nil {
		log.Println("❌ [PostgreSQL] Global pool not initialized")
		return false
	}
	
	// Just ping the existing pool
	if err := pgDB.Ping(); err != nil {
		log.Printf("❌ [PostgreSQL] Ping FAILED: %v\n", err)
		return false
	}
	
	log.Println("✅ [PostgreSQL] Database Connected")
	return true
}

// checkMySQLConnection uses global pool (no new connections)
func checkMySQLConnection() bool {
	if mysqlDB == nil {
		log.Println("❌ [MySQL] Global pool not initialized")
		return false
	}
	
	// Just ping the existing pool
	if err := mysqlDB.Ping(); err != nil {
		log.Printf("❌ [MySQL] Ping FAILED: %v\n", err)
		return false
	}
	
	log.Println("✅ [MySQL] Database Connected")
	return true
}

// getAllSensorHeightsFromMySQL uses global MySQL pool
func getAllSensorHeightsFromMySQL() map[string]float64 {
	if mysqlDB == nil {
		log.Println("❌ [MySQL] Global pool not initialized")
		return make(map[string]float64)
	}
	
	// Use global pool - no sql.Open()
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
	
	log.Printf("✅ [MySQL] Successfully loaded %d devices into cache\n", len(mysqlData))
	
	// Print cache contents for debugging
	if len(mysqlData) > 0 {
		log.Println("📋 [MySQL] Cache contents:")
		for devID, height := range mysqlData {
			log.Printf("   - %s: %.2f cm\n", devID, height)
		}
	}
	
	return mysqlData
}

func getSensorHeightFromCache(deviceID string) (float64, bool) {
	cacheLock.RLock()
	defer cacheLock.RUnlock()
	height, exists := sensorHeightCache[deviceID]
	return height, exists
}

// ==========================
// BUFFER FILE HANDLER
// ==========================
func saveToBufferFile(deviceID, parameter string, value float64) {
	fileLock.Lock()
	defer fileLock.Unlock()
	
	data := make(map[string]BufferData)
	
	if fileData, err := ioutil.ReadFile(BUFFER_FILE_PATH); err == nil {
		json.Unmarshal(fileData, &data)
	}
	
	mapLock.RLock()
	deviceType := deviceMap[deviceID]
	if deviceType == "" {
		deviceType = "Unknown Type"
	}
	mapLock.RUnlock()
	
	key := fmt.Sprintf("%s|%s", deviceID, parameter)
	data[key] = BufferData{
		DeviceID:   deviceID,
		DeviceType: deviceType,
		Parameter:  parameter,
		Value:      value,
		Timestamp:  formatWIBTimestamp(getWIBTime()),
	}
	
	// Log setiap data yang masuk
	log.Printf("📥 [BUFFER] Saved: Device=%s, Type=%s, Param=%s, Value=%.2f\n", 
		deviceID, deviceType, parameter, value)
	
	// AWLR Calculation - ENHANCED LOGGING
	if strings.ToLower(deviceType) == "awlr" && parameter == "tuc" {
		// Check cache first
		cacheLock.RLock()
		cacheSize := len(sensorHeightCache)
		_, existsInCache := sensorHeightCache[deviceID]
		cacheLock.RUnlock()
		
		log.Printf("🔍 [AWLR-DEBUG] Checking for device %s: cache_size=%d, exists=%v\n", 
			deviceID, cacheSize, existsInCache)
		
		if tinggiSensor, exists := getSensorHeightFromCache(deviceID); exists {
			tinggiAir := tinggiSensor - value
			
			airKey := fmt.Sprintf("%s|result_tinggi_air", deviceID)
			data[airKey] = BufferData{
				DeviceID:   deviceID,
				DeviceType: deviceType,
				Parameter:  "result_tinggi_air",
				Value:      tinggiAir,
				Timestamp:  formatWIBTimestamp(getWIBTime()),
			}
			
			awlrCalculationCount++
			
			log.Printf("🌊 [AWLR] Calculation #%d SUCCESS:\n", awlrCalculationCount)
			log.Printf("   Device: %s\n", deviceID)
			log.Printf("   TUC: %.2f cm\n", value)
			log.Printf("   Sensor Height: %.2f cm\n", tinggiSensor)
			log.Printf("   Water Level: %.2f cm\n", tinggiAir)
			log.Printf("   Buffer Key: %s\n", airKey)
		} else {
			log.Printf("⚠️ [AWLR] FAILED: Device=%s not found in cache\n", deviceID)
			log.Printf("   Available devices in cache: %d\n", cacheSize)
			
			// Print all cache keys for debugging
			cacheLock.RLock()
			if cacheSize > 0 && cacheSize <= 10 {
				log.Println("   Cache contains:")
				for id := range sensorHeightCache {
					log.Printf("     - %s\n", id)
				}
			}
			cacheLock.RUnlock()
		}
	}
	
	if jsonData, err := json.MarshalIndent(data, "", "    "); err == nil {
		if err := ioutil.WriteFile(BUFFER_FILE_PATH, jsonData, 0644); err != nil {
			log.Printf("❌ [BUFFER] Write error: %v\n", err)
		} else {
			log.Printf("💾 [BUFFER] File updated successfully, total entries: %d\n", len(data))
		}
	}
}

func readAndClearBuffer() []BufferData {
	fileLock.Lock()
	defer fileLock.Unlock()
	
	if _, err := os.Stat(BUFFER_FILE_PATH); os.IsNotExist(err) {
		log.Println("⚠️ [BUFFER] File does not exist")
		return []BufferData{}
	}
	
	fileData, err := ioutil.ReadFile(BUFFER_FILE_PATH)
	if err != nil {
		log.Printf("❌ [BUFFER] Read error: %v\n", err)
		return []BufferData{}
	}
	
	// Log buffer content before clearing
	log.Printf("📄 [BUFFER] Content before flush:\n%s\n", string(fileData))
	
	dataMap := make(map[string]BufferData)
	if err := json.Unmarshal(fileData, &dataMap); err != nil {
		log.Printf("❌ [BUFFER] Parse error: %v\n", err)
		return []BufferData{}
	}
	
	log.Printf("📊 [BUFFER] Entries to flush: %d\n", len(dataMap))
	
	// Log each entry
	for key, entry := range dataMap {
		log.Printf("   - Key: %s | Device: %s | Param: %s | Value: %.2f\n", 
			key, entry.DeviceID, entry.Parameter, entry.Value)
	}
	
	// Clear file
	emptyData, _ := json.Marshal(map[string]interface{}{})
	if err := ioutil.WriteFile(BUFFER_FILE_PATH, emptyData, 0644); err != nil {
		log.Printf("❌ [BUFFER] Clear error: %v\n", err)
	} else {
		log.Println("🧹 [BUFFER] File cleared successfully")
	}
	
	// Convert map to slice
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
	
	fileData, err := ioutil.ReadFile(CONFIG_JSON_PATH)
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
		log.Printf("   🔧 Device Type: %s (%d devices)\n", typeName, len(typeData.Devices))
		
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
			
			log.Printf("      📱 Device ID: %s | Type: %s | Parameters: %v\n", 
				dev.DevID, typeName, getKeys(params))
			
			for param := range params {
				topic := fmt.Sprintf("temins_iot/%s/data/%s", dev.DevID, param)
				newTopics[topic] = true
			}
		}
	}
	
	log.Printf("\n✅ [CONFIG] Total: %d devices, %d topics configured\n\n", len(newMap), len(newTopics))
	
	return newTopics, newMap
}

func getKeys(m map[string]bool) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	return keys
}

// ==========================
// MQTT HANDLERS
// ==========================
var connectionLostHandler mqtt.ConnectionLostHandler = func(client mqtt.Client, err error) {
	mqttConnected = false
	log.Printf("⚠️ [MQTT] Connection Lost: %v\n", err)
}

var reconnectHandler mqtt.ReconnectHandler = func(client mqtt.Client, opts *mqtt.ClientOptions) {
	log.Println("🔄 [MQTT] Attempting to reconnect...")
}

func onConnect(client mqtt.Client) {
	mqttConnected = true
	log.Println("✅ [MQTT] Connected to Broker")
	
	// Subscribe to all topics
	client.Subscribe("temins_iot/#", 0, nil)
	log.Println("📡 [MQTT] Subscribed to temins_iot/#")
	
	// Subscribe to specific device topics if configured
	mapLock.RLock()
	deviceCount := len(deviceMap)
	mapLock.RUnlock()
	
	if deviceCount > 0 {
		log.Printf("📡 [MQTT] Monitoring %d configured devices\n", deviceCount)
	}
}

func onMessage(client mqtt.Client, msg mqtt.Message) {
	mqttMessageCount++
	payload := string(msg.Payload())
	topic := msg.Topic()
	
	// Log setiap message yang diterima
	log.Printf("\n📨 [MQTT] Message #%d received:\n", mqttMessageCount)
	log.Printf("   Topic: %s\n", topic)
	log.Printf("   Payload: %s\n", payload)
	
	parts := strings.Split(topic, "/")
	if len(parts) < 4 || parts[2] != "data" {
		log.Printf("   ⚠️ Invalid topic format, skipping\n")
		return
	}
	
	deviceID := parts[1]
	parameter := parts[len(parts)-1]
	
	log.Printf("   Device ID: %s\n", deviceID)
	log.Printf("   Parameter: %s\n", parameter)
	
	// Check device type
	mapLock.RLock()
	deviceType, deviceExists := deviceMap[deviceID]
	mapLock.RUnlock()
	
	if deviceExists {
		log.Printf("   Device Type: %s\n", deviceType)
	} else {
		log.Printf("   ⚠️ Device not in config map\n")
	}
	
	var value float64
	
	// Try JSON parsing first
	var msgData MQTTMessage
	if err := json.Unmarshal([]byte(payload), &msgData); err == nil {
		switch v := msgData.Value.(type) {
		case float64:
			value = v
		case string:
			value, _ = strconv.ParseFloat(v, 64)
		default:
			log.Printf("   ⚠️ Unknown value type: %T\n", v)
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
				log.Printf("   ❌ Cannot parse value: %v\n", err)
				return
			}
			value = parsedValue
		}
	}
	
	log.Printf("   ✅ Parsed Value: %.2f\n", value)
	
	saveToBufferFile(deviceID, parameter, value)
}

// ==========================
// BACKGROUND THREADS
// ==========================

// flushToDB - FIXED VERSION (uses global pool + proper transaction handling)
func flushToDB() {
	log.Printf("⏱️ [DB] Flush Thread Active (Every %d minutes) - WIB\n", FLUSH_INTERVAL_MINUTES)
	
	nextInterval, secondsToWait := getNext5MinInterval()
	log.Printf("⏰ [DB] Next flush at: %s WIB\n", formatWIBTimestamp(nextInterval))
	log.Printf("⏳ [DB] Waiting %.0f seconds...\n", secondsToWait.Seconds())
	
	time.Sleep(secondsToWait)
	
	for {
		batchTimestamp := getRounded5MinTimestamp()
		batchTimestampStr := formatWIBTimestamp(batchTimestamp)
		
		log.Println("\n" + strings.Repeat("=", 70))
		log.Printf("⏰ [DB] FLUSH TRIGGERED at %s WIB\n", batchTimestampStr)
		log.Println(strings.Repeat("=", 70))
		
		dataToSave := readAndClearBuffer()
		
		if len(dataToSave) == 0 {
			log.Printf("\nℹ️ [DB] No data to flush at %s WIB\n", batchTimestampStr)
			log.Printf("   📊 MQTT Messages received: %d\n", mqttMessageCount)
			log.Printf("   🌊 AWLR Calculations: %d\n", awlrCalculationCount)
			log.Printf("   🔌 MQTT Connected: %v\n", mqttConnected)
		} else {
			// CRITICAL FIX: Use global pgDB pool - NO sql.Open()
			if pgDB == nil {
				log.Printf("❌ [DB] Global PostgreSQL pool not initialized\n")
			} else {
				// Start transaction with proper error handling
				tx, err := pgDB.Begin()
				if err != nil {
					log.Printf("❌ [DB] Transaction error: %v\n", err)
				} else {
					// CRITICAL: Always rollback on panic or error
					defer tx.Rollback()
					
					stmt, err := tx.Prepare("INSERT INTO sensor_logs (device_unique_id, parameter_name, value, recorded_at) VALUES ($1, $2, $3, $4)")
					if err != nil {
						log.Printf("❌ [DB] Prepare error: %v\n", err)
						// tx.Rollback() will be called by defer
					} else {
						defer stmt.Close()
						
						successCount := 0
						failCount := 0
						
						for _, d := range dataToSave {
							_, err := stmt.Exec(d.DeviceID, d.Parameter, d.Value, batchTimestampStr)
							if err != nil {
								log.Printf("❌ [DB] Insert failed for %s|%s: %v\n", d.DeviceID, d.Parameter, err)
								failCount++
							} else {
								successCount++
							}
						}
						
						// Only commit if no errors
						if failCount == 0 {
							if err := tx.Commit(); err != nil {
								log.Printf("❌ [DB] Commit error: %v\n", err)
							} else {
								log.Println("\n💾 DATABASE PERSISTENCE REPORT")
								log.Printf("🕐 Timestamp (WIB): %s\n", batchTimestampStr)
								log.Printf("📊 Total: %d records (%d success, %d failed)\n", len(dataToSave), successCount, failCount)
								
								for _, d := range dataToSave {
									emoji := "✅"
									if strings.Contains(d.Parameter, "result_tinggi_air") {
										emoji = "🌊"
									}
									log.Printf("   %s %s | %s | %s = %.2f\n", emoji, d.DeviceID, d.DeviceType, d.Parameter, d.Value)
								}
								
								log.Println(strings.Repeat("=", 70) + "\n")
							}
						} else {
							log.Printf("⚠️ [DB] Transaction rolled back due to %d failures\n", failCount)
							// tx.Rollback() will be called by defer
						}
					}
				}
			}
		}
		
		nextInterval, secondsToWait = getNext5MinInterval()
		log.Printf("⏰ [DB] Next flush at: %s WIB (in %.0f seconds)\n\n", 
			formatWIBTimestamp(nextInterval), secondsToWait.Seconds())
		time.Sleep(secondsToWait)
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
			
			mapLock.Lock()
			deviceMap = newMap
			mapLock.Unlock()
			
			// Update subscriptions
			for topic := range newTopics {
				if !subscribedTopics[topic] {
					client.Subscribe(topic, 0, nil)
					log.Printf("   ➕ Subscribed: %s\n", topic)
				}
			}
			
			subscribedTopics = newTopics
			log.Printf("✅ [WATCHER] Monitoring %d devices with %d topics\n", len(deviceMap), len(subscribedTopics))
		}
	}
}

func autoRefreshSensorCache() {
	log.Printf("🔄 [AUTO-REFRESH] Cache refresh thread started (Every %v)\n", CACHE_REFRESH_INTERVAL)
	
	iteration := 0
	for {
		time.Sleep(CACHE_REFRESH_INTERVAL)
		iteration++
		
		log.Printf("\n🔄 [AUTO-REFRESH #%d] Refreshing cache...\n", iteration)
		
		// Uses global mysqlDB pool - safe
		mysqlData := getAllSensorHeightsFromMySQL()
		if len(mysqlData) == 0 {
			log.Printf("⚠️ [AUTO-REFRESH #%d] No data from MySQL\n", iteration)
			continue
		}
		
		cacheLock.Lock()
		sensorHeightCache = mysqlData
		cacheLock.Unlock()
		
		log.Printf("✓ [AUTO-REFRESH #%d] Cache updated (%d devices)\n\n", iteration, len(mysqlData))
	}
}

// Status monitor
func statusMonitor() {
	ticker := time.NewTicker(1 * time.Minute)
	for range ticker.C {
		mapLock.RLock()
		deviceCount := len(deviceMap)
		mapLock.RUnlock()
		
		cacheLock.RLock()
		cacheCount := len(sensorHeightCache)
		cacheLock.RUnlock()
		
		log.Printf("\n📊 [STATUS] Messages: %d | AWLR Calc: %d | Devices: %d | Cache: %d | Connected: %v\n\n",
			mqttMessageCount, awlrCalculationCount, deviceCount, cacheCount, mqttConnected)
	}
}

// ==========================
// MAIN
// ==========================
func main() {
	// Setup logging
	log.SetFlags(log.Ldate | log.Ltime)
	
	// Setup paths
	baseDir, _ := filepath.Abs(filepath.Dir(os.Args[0]))
	CONFIG_JSON_PATH = filepath.Join(baseDir, "../py/1.json")
	BUFFER_FILE_PATH = filepath.Join(baseDir, "buf2.json")
	
	log.Println("\n" + strings.Repeat("=", 70))
	log.Println("🚀 TEMINS IoT Logger - Go Version (PRODUCTION-SAFE)")
	log.Println(strings.Repeat("=", 70))
	log.Printf("🕐 Current Time (WIB): %s\n", formatWIBTimestamp(getWIBTime()))
	log.Printf("📁 Config Path: %s\n", CONFIG_JSON_PATH)
	log.Printf("📁 Buffer Path: %s\n", BUFFER_FILE_PATH)
	log.Println(strings.Repeat("=", 70) + "\n")
	
	// CRITICAL: Initialize global connection pools FIRST
	log.Println("🔄 [INIT] Initializing database connection pools...")
	if err := initPostgres(); err != nil {
		log.Fatalf("❌ Cannot proceed without PostgreSQL: %v\n", err)
	}
	if err := initMySQL(); err != nil {
		log.Fatalf("❌ Cannot proceed without MySQL: %v\n", err)
	}
	
	// Verify connections
	if !checkPostgresConnection() {
		log.Fatal("❌ PostgreSQL connection verification failed")
	}
	if !checkMySQLConnection() {
		log.Fatal("❌ MySQL connection verification failed")
	}
	
	// Load config first
	log.Println("🔄 [INIT] Loading device configuration...")
	newTopics, newMap := updateConfigFromJSON()
	mapLock.Lock()
	deviceMap = newMap
	subscribedTopics = newTopics
	mapLock.Unlock()
	
	// Initial cache load
	log.Println("🔄 [INIT] Loading initial sensor height cache...")
	initialData := getAllSensorHeightsFromMySQL()
	cacheLock.Lock()
	sensorHeightCache = initialData
	cacheLock.Unlock()
	log.Printf("✅ [INIT] Loaded %d devices into cache\n\n", len(initialData))
	
	// MQTT setup with enhanced logging
	mqtt.ERROR = log.New(os.Stdout, "[MQTT-ERROR] ", log.LstdFlags)
	mqtt.CRITICAL = log.New(os.Stdout, "[MQTT-CRITICAL] ", log.LstdFlags)
	mqtt.WARN = log.New(os.Stdout, "[MQTT-WARN] ", log.LstdFlags)
	//mqtt.DEBUG = log.New(os.Stdout, "[MQTT-DEBUG] ", log.LstdFlags)
	
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
	opts.SetConnectRetry(true)
	opts.SetConnectRetryInterval(5 * time.Second)
	
	client := mqtt.NewClient(opts)
	
	// Start background goroutines
	go flushToDB()
	go autoRefreshSensorCache()
	go statusMonitor()
	
	// Connect MQTT
	log.Printf("🔌 Connecting to wss://%s:%d/mqtt...\n", BROKER_HOST, BROKER_PORT)
	if token := client.Connect(); token.Wait() && token.Error() != nil {
		log.Fatalf("❌ MQTT connection failed: %v\n", token.Error())
	}
	
	log.Println("✅ MQTT Connected, starting config watcher...\n")
	log.Println("📡 Waiting for MQTT messages...\n")
	
	// Cleanup on exit
	defer func() {
		log.Println("\n🛑 Shutting down gracefully...")
		if pgDB != nil {
			pgDB.Close()
			log.Println("✅ PostgreSQL pool closed")
		}
		if mysqlDB != nil {
			mysqlDB.Close()
			log.Println("✅ MySQL pool closed")
		}
	}()
	
	configWatcher(client)
}