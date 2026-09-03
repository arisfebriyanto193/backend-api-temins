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
// dan mengembalikan map deviceID -> tipeDevice beserta daftar topic MQTT (dengan wildcard #).
func updateConfigFromMySQL() (map[string]bool, map[string]string) {
	newTopics := make(map[string]bool)
	newMap := make(map[string]string)

	if mysqlDB == nil {
		log.Println("⚠️ [CONFIG] MySQL connection is nil. Cannot load config.")
		return newTopics, newMap
	}

	rows, err := mysqlDB.Query("SELECT device_unique_id, device_type FROM user_devices WHERE status = 1")
	if err != nil {
		log.Printf("❌ [CONFIG] Query error: %v\n", err)
		return newTopics, newMap
	}
	defer rows.Close()

	log.Println("\n📋 [CONFIG] Loading device configuration from MySQL:")

	for rows.Next() {
		var devID, devType string
		if err := rows.Scan(&devID, &devType); err != nil {
			log.Printf("❌ [CONFIG] Scan error: %v\n", err)
			continue
		}

		// Jika devID kosong, skip
		if devID == "" {
			continue
		}

		newMap[devID] = devType
		
		// Subscribe menggunakan wildcard # untuk menangkap semua parameter dari device tersebut
		topic := fmt.Sprintf("temins_iot/%s/data/#", devID)
		newTopics[topic] = true

		log.Printf("   📱 Device: %s | Type: %s\n", devID, devType)
	}

	log.Printf("\n✅ [CONFIG] Total: %d devices loaded from database\n\n", len(newMap))
	return newTopics, newMap
}

// ==========================
// CONFIG FILE WATCHER
// ==========================

// configWatcher secara periodik (setiap 30 detik) mengambil ulang konfigurasi dari MySQL.
// Jika ada penambahan device baru, sistem akan otomatis melakukan subscribe ke topic-nya.
func configWatcher(client mqtt.Client) {
	for {
		time.Sleep(30 * time.Second)

		newTopics, newMap := updateConfigFromMySQL()
		if len(newMap) == 0 {
			// Jika kosong (mungkin query error atau db reconnecting), jangan timpa map lama
			continue
		}

		stateLock.Lock()
		deviceMap = newMap

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
