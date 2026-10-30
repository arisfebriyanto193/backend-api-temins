package main

import (
	"fmt"
	"log"
	"time"

	mqtt "github.com/eclipse/paho.mqtt.golang"
)

// ==========================
// CONFIG LOADER
// ==========================

// updateConfigFromMySQL mengambil daftar device aktif dari tabel user_devices di MySQL
// dan mengembalikan map topic MQTT, map deviceID -> tipeDevice, serta map deviceID -> recordInterval (menit).
func updateConfigFromMySQL() (map[string]bool, map[string]string, map[string]int) {
	newTopics := make(map[string]bool)
	newMap := make(map[string]string)
	newIntervalMap := make(map[string]int)

	if mysqlDB == nil {
		log.Println("⚠️ [CONFIG] MySQL connection is nil. Cannot load config.")
		return newTopics, newMap, newIntervalMap
	}

	rows, err := mysqlDB.Query("SELECT device_unique_id, device_type, COALESCE(record_interval, 5) FROM user_devices WHERE status = 1")
	if err != nil {
		// Fallback jika kolom record_interval belum ada di database
		log.Printf("⚠️ [CONFIG] Query with record_interval failed: %v. Retrying without record_interval...\n", err)
		var fallbackErr error
		rows, fallbackErr = mysqlDB.Query("SELECT device_unique_id, device_type FROM user_devices WHERE status = 1")
		if fallbackErr != nil {
			log.Printf("❌ [CONFIG] Fallback query error: %v\n", fallbackErr)
			return newTopics, newMap, newIntervalMap
		}
		defer rows.Close()

		for rows.Next() {
			var devID, devType string
			if err := rows.Scan(&devID, &devType); err != nil {
				continue
			}
			if devID == "" {
				continue
			}
			newMap[devID] = devType
			newIntervalMap[devID] = 5
			topic := fmt.Sprintf("temins_iot/%s/data/#", devID)
			newTopics[topic] = true
			log.Printf("   📱 Device: %s | Type: %s | Interval: 5m (fallback)\n", devID, devType)
		}
		log.Printf("\n✅ [CONFIG] Total: %d devices loaded from database\n\n", len(newMap))
		return newTopics, newMap, newIntervalMap
	}
	defer rows.Close()

	log.Println("\n📋 [CONFIG] Loading device configuration from MySQL:")

	for rows.Next() {
		var devID, devType string
		var interval int
		if err := rows.Scan(&devID, &devType, &interval); err != nil {
			log.Printf("❌ [CONFIG] Scan error: %v\n", err)
			continue
		}

		if devID == "" {
			continue
		}

		if interval <= 0 {
			interval = 5
		}

		newMap[devID] = devType
		newIntervalMap[devID] = interval
		
		topic := fmt.Sprintf("temins_iot/%s/data/#", devID)
		newTopics[topic] = true

		log.Printf("   📱 Device: %s | Type: %s | Interval: %dm\n", devID, devType, interval)
	}

	log.Printf("\n✅ [CONFIG] Total: %d devices loaded from database\n\n", len(newMap))
	return newTopics, newMap, newIntervalMap
}

// ==========================
// CONFIG FILE WATCHER
// ==========================

// configWatcher secara periodik (setiap 30 detik) mengambil ulang konfigurasi dari MySQL.
// Jika ada penambahan device baru, sistem akan otomatis melakukan subscribe ke topic-nya.
func configWatcher(client mqtt.Client) {
	for {
		time.Sleep(30 * time.Second)

		newTopics, newMap, newIntervalMap := updateConfigFromMySQL()
		if len(newMap) == 0 {
			// Jika kosong (mungkin query error atau db reconnecting), jangan timpa map lama
			continue
		}

		stateLock.Lock()
		deviceMap = newMap
		deviceIntervalMap = newIntervalMap

		// Subscribe ke topic baru yang belum di-subscribe sebelumnya
		for topic := range newTopics {
			if !subscribedTopics[topic] {
				client.Subscribe(topic, 0, nil)
				log.Printf("🔄 [WATCHER] Subscribed to new topic: %s\n", topic)
			}
		}
		subscribedTopics = newTopics
		stateLock.Unlock()
	}
}
