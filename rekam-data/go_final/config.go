package main

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"time"

	mqtt "github.com/eclipse/paho.mqtt.golang"
)

// ==========================
// CONFIG LOADER
// ==========================

// updateConfigFromJSON membaca file konfigurasi JSON (1.json) dan mengembalikan
// dua map: daftar topic MQTT yang harus di-subscribe, dan mapping deviceID → tipeDevice.
// Dipanggil saat startup dan setiap kali file konfigurasi berubah (oleh configWatcher).
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
// CONFIG FILE WATCHER
// ==========================

// configWatcher memantau perubahan file konfigurasi JSON setiap 10 detik.
// Jika file berubah (berdasarkan mod time), konfigurasi akan di-reload
// dan topic MQTT baru akan di-subscribe secara otomatis.
// Fungsi ini berjalan sebagai goroutine utama di akhir main() (blocking).
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

			// Subscribe ke topic baru yang belum di-subscribe sebelumnya
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
