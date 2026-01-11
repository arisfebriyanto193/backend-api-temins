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
	BROKER_HOST           = "karsacerdasinovatif.web.id"
	BROKER_PORT           = 8081
	FLUSH_INTERVAL_MINUTES = 5
	CACHE_REFRESH_INTERVAL = 30 * time.Second
	WIB_OFFSET            = 7 * time.Hour
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
// GLOBAL STATE & LOCKS
// ==========================
var (
	// Database Pools (Global)
	pgDB *sql.DB
	myDB *sql.DB

	fileLock             sync.Mutex
	mapLock              sync.RWMutex
	cacheLock            sync.RWMutex
	subscribedTopics     = make(map[string]bool)
	deviceMap            = make(map[string]string) 
	sensorHeightCache    = make(map[string]float64)
	lastFileModTime      time.Time
	mqttMessageCount     int64
	awlrCalculationCount int64
	mqttConnected        bool
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
// DATABASE FUNCTIONS (FIXED)
// ==========================

func initPostgres() {
	var err error
	pgDB, err = sql.Open("postgres", POSTGRES_DSN)
	if err != nil {
		log.Fatalf("❌ [PostgreSQL] Critical Error: %v\n", err)
	}

	// Limit connections sesuai instruksi (Rekam Data: 5)
	pgDB.SetMaxOpenConns(5)
	pgDB.SetMaxIdleConns(2)
	pgDB.SetConnMaxLifetime(1 * time.Hour)

	if err := pgDB.Ping(); err != nil {
		log.Fatalf("❌ [PostgreSQL] Ping FAILED: %v\n", err)
	}
	log.Println("✅ [PostgreSQL] Pool Initialized (Max: 5 Conns)")
}

func initMySQL() {
	var err error
	myDB, err = sql.Open("mysql", MYSQL_DSN)
	if err != nil {
		log.Fatalf("❌ [MySQL] Critical Error: %v\n", err)
	}
	
	myDB.SetMaxOpenConns(3)
	myDB.SetMaxIdleConns(1)

	if err := myDB.Ping(); err != nil {
		log.Fatalf("❌ [MySQL] Ping FAILED: %v\n", err)
	}
	log.Println("✅ [MySQL] Pool Initialized")
}

func checkPostgresConnection() bool {
	// TIDAK memakai sql.Open lagi, gunakan Ping pada pool global
	if pgDB == nil {
		return false
	}
	err := pgDB.Ping()
	if err != nil {
		log.Printf("❌ [PostgreSQL] Health Check FAILED: %v\n", err)
		return false
	}
	return true
}

func checkMySQLConnection() bool {
	if myDB == nil {
		return false
	}
	err := myDB.Ping()
	if err != nil {
		log.Printf("❌ [MySQL] Health Check FAILED: %v\n", err)
		return false
	}
	return true
}

func getAllSensorHeightsFromMySQL() map[string]float64 {
	// Menggunakan pool global myDB
	rows, err := myDB.Query("SELECT device_unique_id, tinggi_sensor FROM device_settings")
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
		
		mysqlData[deviceID] = tinggiSensor.Float64
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
				Timestamp:  formatWIBTimestamp(getWIBTime()),
			}
			awlrCalculationCount++
		}
	}
	
	if jsonData, err := json.MarshalIndent(data, "", "    "); err == nil {
		ioutil.WriteFile(BUFFER_FILE_PATH, jsonData, 0644)
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
	json.Unmarshal(fileData, &dataMap)
	
	// Clear file
	emptyData, _ := json.Marshal(map[string]interface{}{})
	ioutil.WriteFile(BUFFER_FILE_PATH, emptyData, 0644)
	
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
	
	fileData, err := ioutil.ReadFile(CONFIG_JSON_PATH)
	if err != nil {
		return newTopics, newMap
	}
	
	var config Config
	if err := json.Unmarshal(fileData, &config); err != nil {
		return newTopics, newMap
	}
	
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
			for param := range params {
				topic := fmt.Sprintf("temins_iot/%s/data/%s", dev.DevID, param)
				newTopics[topic] = true
			}
		}
	}
	return newTopics, newMap
}

// ==========================
// MQTT HANDLERS
// ==========================
var connectionLostHandler mqtt.ConnectionLostHandler = func(client mqtt.Client, err error) {
	mqttConnected = false
	log.Printf("⚠️ [MQTT] Connection Lost: %v\n", err)
}

func onConnect(client mqtt.Client) {
	mqttConnected = true
	log.Println("✅ [MQTT] Connected to Broker")
	client.Subscribe("temins_iot/#", 0, nil)
}

func onMessage(client mqtt.Client, msg mqtt.Message) {
	mqttMessageCount++
	payload := string(msg.Payload())
	topic := msg.Topic()
	
	parts := strings.Split(topic, "/")
	if len(parts) < 4 || parts[2] != "data" {
		return
	}
	
	deviceID := parts[1]
	parameter := parts[len(parts)-1]
	
	var value float64
	var msgData MQTTMessage
	if err := json.Unmarshal([]byte(payload), &msgData); err == nil {
		switch v := msgData.Value.(type) {
		case float64:
			value = v
		case string:
			value, _ = strconv.ParseFloat(v, 64)
		}
	} else {
		if strings.Contains(payload, " - ") {
			parts := strings.Split(payload, " - ")
			value, _ = strconv.ParseFloat(parts[0], 64)
		} else {
			value, _ = strconv.ParseFloat(payload, 64)
		}
	}
	
	saveToBufferFile(deviceID, parameter, value)
}

// ==========================
// BACKGROUND THREADS (FIXED)
// ==========================
func flushToDB() {
	nextInterval, secondsToWait := getNext5MinInterval()
	time.Sleep(secondsToWait)
	
	for {
		batchTimestamp := getRounded5MinTimestamp()
		batchTimestampStr := formatWIBTimestamp(batchTimestamp)
		
		dataToSave := readAndClearBuffer()
		
		if len(dataToSave) > 0 {
			// MENGGUNAKAN GLOBAL POOL (pgDB)
			tx, err := pgDB.Begin()
			if err != nil {
				log.Printf("❌ [DB] Transaction Begin Error: %v\n", err)
			} else {
				// DEFER ROLLBACK: Sangat penting untuk mencegah kebocoran koneksi
				// Jika Commit() berhasil dipanggil, Rollback() tidak akan berefek apa-apa.
				defer tx.Rollback()

				stmt, err := tx.Prepare("INSERT INTO sensor_logs (device_unique_id, parameter_name, value, recorded_at) VALUES ($1, $2, $3, $4)")
				if err != nil {
					log.Printf("❌ [DB] Prepare error: %v\n", err)
				} else {
					successCount := 0
					for _, d := range dataToSave {
						_, err := stmt.Exec(d.DeviceID, d.Parameter, d.Value, batchTimestampStr)
						if err == nil {
							successCount++
						}
					}
					stmt.Close()
					
					// COMMIT HANYA DI AKHIR
					if err := tx.Commit(); err != nil {
						log.Printf("❌ [DB] Commit error: %v\n", err)
					} else {
						log.Printf("💾 [DB] Flushed %d records successfully at %s\n", successCount, batchTimestampStr)
					}
				}
			}
		}
		
		nextInterval, secondsToWait = getNext5MinInterval()
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
			newTopics, newMap := updateConfigFromJSON()
			mapLock.Lock()
			deviceMap = newMap
			mapLock.Unlock()
			for topic := range newTopics {
				if !subscribedTopics[topic] {
					client.Subscribe(topic, 0, nil)
				}
			}
			subscribedTopics = newTopics
		}
	}
}

func autoRefreshSensorCache() {
	for {
		time.Sleep(CACHE_REFRESH_INTERVAL)
		mysqlData := getAllSensorHeightsFromMySQL()
		if len(mysqlData) > 0 {
			cacheLock.Lock()
			sensorHeightCache = mysqlData
			cacheLock.Unlock()
		}
	}
}

func statusMonitor() {
	ticker := time.NewTicker(1 * time.Minute)
	for range ticker.C {
		log.Printf("📊 [STATUS] Msg: %d | Calc: %d | DB Conn: %d/%d\n",
			mqttMessageCount, awlrCalculationCount, pgDB.Stats().InUse, pgDB.Stats().OpenConnections)
	}
}

// ==========================
// MAIN
// ==========================
func main() {
	log.SetFlags(log.Ldate | log.Ltime)
	
	baseDir, _ := filepath.Abs(filepath.Dir(os.Args[0]))
	CONFIG_JSON_PATH = filepath.Join(baseDir, "../py/1.json")
	BUFFER_FILE_PATH = filepath.Join(baseDir, "buf2.json")
	
	// 1. INISIALISASI POOL DATABASE (Hanya sekali seumur hidup aplikasi)
	initPostgres()
	initMySQL()
	
	// Pastikan pool ditutup saat aplikasi mati
	defer pgDB.Close()
	defer myDB.Close()

	// 2. Load config & cache
	newTopics, newMap := updateConfigFromJSON()
	mapLock.Lock()
	deviceMap = newMap
	subscribedTopics = newTopics
	mapLock.Unlock()
	
	sensorHeightCache = getAllSensorHeightsFromMySQL()
	
	// 3. MQTT Setup
	opts := mqtt.NewClientOptions()
	opts.AddBroker(fmt.Sprintf("wss://%s:%d/mqtt", BROKER_HOST, BROKER_PORT))
	opts.SetClientID(fmt.Sprintf("temins_logger_go_%d", time.Now().Unix()))
	opts.SetTLSConfig(&tls.Config{InsecureSkipVerify: true})
	opts.SetOnConnectHandler(onConnect)
	opts.SetDefaultPublishHandler(onMessage)
	opts.SetConnectionLostHandler(connectionLostHandler)
	opts.SetAutoReconnect(true)
	
	client := mqtt.NewClient(opts)
	
	// 4. Start Goroutines
	go flushToDB()
	go autoRefreshSensorCache()
	go statusMonitor()
	
	if token := client.Connect(); token.Wait() && token.Error() != nil {
		log.Fatalf("❌ MQTT connection failed: %v\n", token.Error())
	}
	
	configWatcher(client)
}