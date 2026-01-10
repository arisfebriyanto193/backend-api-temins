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
	BROKER_HOST            = "karsacerdasinovatif.web.id"
	BROKER_PORT            = 8081
	FLUSH_INTERVAL         = 300 * time.Second // 5 menit
	CACHE_REFRESH_INTERVAL = 30 * time.Second  // 30 detik
)

var (
	CONFIG_JSON_PATH string
	BUFFER_FILE_PATH string
)

// Database configs
var (
	postgresConnStr = "host=127.0.0.1 port=5432 user=postgres password=example dbname=temins sslmode=disable"
	mysqlConnStr    = "temins:YFBmEzBBtty6hBC7@tcp(127.0.0.1:3306)/temins?timeout=10s"
)

// ==========================
// GLOBAL STATE & LOCKS
// ==========================
var (
	fileLock           sync.Mutex
	mapLock            sync.RWMutex
	cacheLock          sync.RWMutex
	subscribedTopics   = make(map[string]bool)
	deviceMap          = make(map[string]string) // device_id -> device_type
	sensorHeightCache  = make(map[string]float64)
	lastFileMtime      time.Time
)

// ==========================
// DATA STRUCTURES
// ==========================
type BufferData struct {
	DeviceID   string  `json:"device_id"`
	DeviceType string  `json:"device_type"`
	Parameter  string  `json:"parameter"`
	Value      float64 `json:"value"`
	Timestamp  string  `json:"timestamp"`
}

type DeviceConfig struct {
	DevID      string   `json:"dev_id"`
	UseDefault bool     `json:"use_default"`
	Topic      []string `json:"topic"`
}

type DeviceType struct {
	DefTopic []string       `json:"def_topic"`
	Devices  []DeviceConfig `json:"devices"`
}

type ConfigData struct {
	DeviceType map[string]DeviceType `json:"device_type"`
}

// ==========================
// DATABASE FUNCTIONS
// ==========================
func checkPostgresConnection() bool {
	db, err := sql.Open("postgres", postgresConnStr)
	if err != nil {
		log.Printf("❌ [PostgreSQL] Connection FAILED: %v", err)
		return false
	}
	defer db.Close()

	if err := db.Ping(); err != nil {
		log.Printf("❌ [PostgreSQL] Ping FAILED: %v", err)
		return false
	}

	log.Println("✅ [PostgreSQL] Database Connected")
	return true
}

func checkMySQLConnection() bool {
	log.Printf("🔍 [MySQL] Attempting connection to 127.0.0.1:3306...")
	log.Printf("    User: temins")
	log.Printf("    Database: temins")

	db, err := sql.Open("mysql", mysqlConnStr)
	if err != nil {
		log.Printf("❌ [MySQL] Connection FAILED: %v", err)
		return false
	}
	defer db.Close()

	if err := db.Ping(); err != nil {
		log.Printf("❌ [MySQL] Ping FAILED: %v", err)
		return false
	}

	log.Println("✅ [MySQL] Database Connected Successfully")
	return true
}

func getAllSensorHeightsFromMySQL() map[string]float64 {
	db, err := sql.Open("mysql", mysqlConnStr)
	if err != nil {
		log.Printf("❌ [MySQL] Error opening connection: %v", err)
		return make(map[string]float64)
	}
	defer db.Close()

	rows, err := db.Query("SELECT device_unique_id, tinggi_sensor FROM device_settings")
	if err != nil {
		log.Printf("❌ [MySQL] Error querying sensor heights: %v", err)
		return make(map[string]float64)
	}
	defer rows.Close()

	mysqlData := make(map[string]float64)
	for rows.Next() {
		var deviceID string
		var tinggiSensor sql.NullFloat64

		if err := rows.Scan(&deviceID, &tinggiSensor); err != nil {
			continue
		}

		if tinggiSensor.Valid {
			mysqlData[deviceID] = tinggiSensor.Float64
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

	if _, err := os.Stat(BUFFER_FILE_PATH); err == nil {
		fileData, err := ioutil.ReadFile(BUFFER_FILE_PATH)
		if err == nil {
			json.Unmarshal(fileData, &data)
		}
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
		Timestamp:  time.Now().Format("2006-01-02 15:04:05"),
	}

	// Hitung tinggi air untuk AWLR
	if strings.ToLower(deviceType) == "awlr" && parameter == "tuc" {
		if tinggiSensor, exists := getSensorHeightFromCache(deviceID); exists {
			tinggiAir := tinggiSensor - value
			airKey := fmt.Sprintf("%s|result_tinggi_air", deviceID)
			data[airKey] = BufferData{
				DeviceID:   deviceID,
				DeviceType: deviceType,
				Parameter:  "result_tinggi_air",
				Value:      tinggiAir,
				Timestamp:  time.Now().Format("2006-01-02 15:04:05"),
			}
		}
	}

	jsonData, err := json.MarshalIndent(data, "", "    ")
	if err != nil {
		log.Printf("❌ [FILE] Failed to marshal JSON: %v", err)
		return
	}

	if err := ioutil.WriteFile(BUFFER_FILE_PATH, jsonData, 0644); err != nil {
		log.Printf("❌ [FILE] Failed to write buffer: %v", err)
	}
}

func readAndClearBuffer() []BufferData {
	fileLock.Lock()
	defer fileLock.Unlock()

	if _, err := os.Stat(BUFFER_FILE_PATH); os.IsNotExist(err) {
		return []BufferData{}
	}

	fileData, err := ioutil.ReadFile(BUFFER_FILE_PATH)
	if err != nil {
		return []BufferData{}
	}

	dataMap := make(map[string]BufferData)
	if err := json.Unmarshal(fileData, &dataMap); err != nil {
		return []BufferData{}
	}

	// Clear buffer file
	emptyData, _ := json.Marshal(map[string]interface{}{})
	ioutil.WriteFile(BUFFER_FILE_PATH, emptyData, 0644)

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
		log.Printf("⚠️ [CONFIG] File not found: %s", CONFIG_JSON_PATH)
		return newTopics, newMap
	}

	fileData, err := ioutil.ReadFile(CONFIG_JSON_PATH)
	if err != nil {
		log.Printf("❌ [CONFIG] Error reading file: %v", err)
		return newTopics, newMap
	}

	var config ConfigData
	if err := json.Unmarshal(fileData, &config); err != nil {
		log.Printf("❌ [CONFIG] Error parsing JSON: %v", err)
		return newTopics, newMap
	}

	for typeName, typeData := range config.DeviceType {
		log.Printf("🔧 [CONFIG] Loading device type: %s with %d devices", typeName, len(typeData.Devices))

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

			for param := range params {
				topic := fmt.Sprintf("temins_iot/%s/data/%s", dev.DevID, param)
				newTopics[topic] = true
			}

			log.Printf("   📱 Device ID: %s | Type: %s | Topics: %d", dev.DevID, typeName, len(params))
		}
	}

	return newTopics, newMap
}

// ==========================
// FILE WATCHER
// ==========================
func configWatcher(client mqtt.Client) {
	for {
		time.Sleep(10 * time.Second)

		fileInfo, err := os.Stat(CONFIG_JSON_PATH)
		if err != nil {
			continue
		}

		currentMtime := fileInfo.ModTime()
		if currentMtime.Equal(lastFileMtime) {
			continue
		}

		lastFileMtime = currentMtime
		log.Println("\n🔄 [WATCHER] Config file changed, reloading...")

		newTopics, newMap := updateConfigFromJSON()

		mapLock.Lock()
		deviceMap = newMap
		mapLock.Unlock()

		// Subscribe/Unsubscribe
		for topic := range newTopics {
			if !subscribedTopics[topic] {
				client.Subscribe(topic, 0, nil)
				log.Printf("   ➕ Subscribed: %s", topic)
			}
		}

		for topic := range subscribedTopics {
			if !newTopics[topic] {
				client.Unsubscribe(topic)
				log.Printf("   ➖ Unsubscribed: %s", topic)
			}
		}

		subscribedTopics = newTopics
		log.Printf("✅ [WATCHER] Config Updated. Monitoring %d devices with %d topics.\n", len(deviceMap), len(subscribedTopics))
	}
}

// ==========================
// MQTT HANDLERS
// ==========================
var connectHandler mqtt.OnConnectHandler = func(client mqtt.Client) {
	log.Println("✅ [MQTT] Connected to Broker")
	client.Subscribe("temins_iot/#", 0, nil)
	log.Println("📡 [MQTT] Subscribed to temins_iot/#")
}

var messageHandler mqtt.MessageHandler = func(client mqtt.Client, msg mqtt.Message) {
	topic := msg.Topic()
	payload := string(msg.Payload())

	parts := strings.Split(topic, "/")
	if len(parts) < 4 || parts[2] != "data" {
		return
	}

	deviceID := parts[1]
	parameter := parts[len(parts)-1]

	// Parse value
	var value float64
	var dataJSON map[string]interface{}

	if err := json.Unmarshal([]byte(payload), &dataJSON); err == nil {
		if v, ok := dataJSON["value"]; ok {
			if val, ok := v.(float64); ok {
				value = val
			} else if val, ok := v.(string); ok {
				value, _ = strconv.ParseFloat(val, 64)
			}
		}
	} else {
		// Try parsing as raw number or "number - text" format
		parts := strings.Split(payload, " - ")
		value, _ = strconv.ParseFloat(strings.TrimSpace(parts[0]), 64)
	}

	saveToBufferFile(deviceID, parameter, value)
}

// ==========================
// FLUSH TO DB
// ==========================
func flushToDB() {
	log.Printf("⏱️ [DB] Flush Thread Active (Every %v)", FLUSH_INTERVAL)

	for {
		time.Sleep(FLUSH_INTERVAL)

		dataToSave := readAndClearBuffer()
		if len(dataToSave) == 0 {
			log.Println("ℹ️ [DB] No data to flush")
			continue
		}

		db, err := sql.Open("postgres", postgresConnStr)
		if err != nil {
			log.Printf("❌ [DB] Failed to connect: %v", err)
			continue
		}

		tx, err := db.Begin()
		if err != nil {
			db.Close()
			log.Printf("❌ [DB] Failed to begin transaction: %v", err)
			continue
		}

		stmt, err := tx.Prepare("INSERT INTO sensor_logs (device_unique_id, parameter_name, value) VALUES ($1, $2, $3)")
		if err != nil {
			tx.Rollback()
			db.Close()
			log.Printf("❌ [DB] Failed to prepare statement: %v", err)
			continue
		}

		log.Println("\n" + strings.Repeat("=", 60))
		log.Println("💾 DATABASE PERSISTENCE REPORT")
		log.Println(strings.Repeat("=", 60))

		for _, d := range dataToSave {
			_, err := stmt.Exec(d.DeviceID, d.Parameter, d.Value)
			if err != nil {
				log.Printf("⚠️ [DB] Failed to insert record: %v", err)
				continue
			}

			statusIcon := "✅"
			if d.Parameter == "result_tinggi_air" {
				statusIcon = "🌊"
			}
			log.Printf("%s STORED: ID:[%s] Type:[%s] Param:[%s] Value:[%f]",
				statusIcon, d.DeviceID, d.DeviceType, d.Parameter, d.Value)
		}

		stmt.Close()
		if err := tx.Commit(); err != nil {
			log.Printf("❌ [DB] Failed to commit: %v", err)
		} else {
			log.Printf("\n📊 Total: %d records successfully saved to PostgreSQL.", len(dataToSave))
		}

		log.Println(strings.Repeat("=", 60) + "\n")
		db.Close()
	}
}

// ==========================
// AUTO-REFRESH CACHE
// ==========================
func autoRefreshSensorCache() {
	log.Printf("🔄 [AUTO-REFRESH] Cache refresh thread started (Every %v)", CACHE_REFRESH_INTERVAL)

	iteration := 0
	for {
		time.Sleep(CACHE_REFRESH_INTERVAL)
		iteration++

		mysqlData := getAllSensorHeightsFromMySQL()
		if len(mysqlData) == 0 {
			log.Printf("⚠️ [AUTO-REFRESH #%d] No data from MySQL", iteration)
			continue
		}

		changesDetected := false
		newDevices := []string{}
		updatedDevices := []struct {
			ID       string
			OldValue float64
			NewValue float64
		}{}

		cacheLock.Lock()

		// Check for new devices
		for deviceID := range mysqlData {
			if _, exists := sensorHeightCache[deviceID]; !exists {
				newDevices = append(newDevices, deviceID)
				changesDetected = true
			}
		}

		// Check for value changes
		for deviceID, newValue := range mysqlData {
			if oldValue, exists := sensorHeightCache[deviceID]; exists && oldValue != newValue {
				updatedDevices = append(updatedDevices, struct {
					ID       string
					OldValue float64
					NewValue float64
				}{deviceID, oldValue, newValue})
				changesDetected = true
			}
		}

		// Update cache
		for k, v := range mysqlData {
			sensorHeightCache[k] = v
		}

		cacheLock.Unlock()

		if changesDetected {
			log.Println("\n" + strings.Repeat("=", 70))
			log.Printf("🔄 AUTO-REFRESH REPORT #%d - %s", iteration, time.Now().Format("2006-01-02 15:04:05"))
			log.Println(strings.Repeat("=", 70))

			if len(newDevices) > 0 {
				log.Printf("🆕 NEW DEVICES DETECTED: %d", len(newDevices))
				for _, devID := range newDevices {
					log.Printf("   ➕ Device: %s | Sensor Height: %f cm", devID, mysqlData[devID])
				}
			}

			if len(updatedDevices) > 0 {
				log.Printf("📝 UPDATED SENSOR HEIGHTS: %d", len(updatedDevices))
				for _, upd := range updatedDevices {
					log.Printf("   🔄 Device: %s | Old: %f cm → New: %f cm", upd.ID, upd.OldValue, upd.NewValue)
				}
			}

			log.Printf("✅ Cache updated with %d total devices", len(mysqlData))
			log.Println(strings.Repeat("=", 70) + "\n")
		} else {
			log.Printf("✓ [AUTO-REFRESH #%d] No changes detected (%d devices monitored)", iteration, len(mysqlData))
		}
	}
}

// ==========================
// MAIN
// ==========================
func main() {
	log.Println("\n" + strings.Repeat("=", 60))
	log.Println("🚀 TEMINS IoT Logger with Auto-Refresh MySQL Cache (Go)")
	log.Println(strings.Repeat("=", 60) + "\n")

	// Setup paths
	baseDir, _ := filepath.Abs(filepath.Dir(os.Args[0]))
	CONFIG_JSON_PATH = filepath.Join(baseDir, "../1.json")
	BUFFER_FILE_PATH = filepath.Join(baseDir, "../buf2.json")

	// Check database connections
	if !checkPostgresConnection() {
		log.Fatal("❌ Cannot proceed without PostgreSQL connection")
	}

	if !checkMySQLConnection() {
		log.Fatal("❌ Cannot proceed without MySQL connection")
	}

	log.Println()

	// Initial cache load
	log.Println("🔄 [INIT] Loading initial sensor height cache from MySQL...")
	initialData := getAllSensorHeightsFromMySQL()
	cacheLock.Lock()
	for k, v := range initialData {
		sensorHeightCache[k] = v
	}
	cacheLock.Unlock()
	log.Printf("✅ [INIT] Loaded %d devices into cache\n", len(initialData))

	// Setup MQTT
	opts := mqtt.NewClientOptions()
	opts.AddBroker(fmt.Sprintf("wss://%s:%d/mqtt", BROKER_HOST, BROKER_PORT))
	opts.SetClientID(fmt.Sprintf("temins_logger_go_%d", time.Now().Unix()))
	opts.SetTLSConfig(&tls.Config{InsecureSkipVerify: false})
	opts.SetOnConnectHandler(connectHandler)
	opts.SetDefaultPublishHandler(messageHandler)
	opts.SetKeepAlive(60 * time.Second)
	opts.SetAutoReconnect(true)

	client := mqtt.NewClient(opts)

	// Start background goroutines
	go flushToDB()
	go configWatcher(client)
	go autoRefreshSensorCache()

	// Connect to MQTT
	log.Printf("🔌 Connecting to %s:%d...", BROKER_HOST, BROKER_PORT)

	if token := client.Connect(); token.Wait() && token.Error() != nil {
		log.Fatalf("❌ Connection error: %v", token.Error())
	}

	log.Println("✅ Connection established, running...\n")

	// Keep running
	select {}
}