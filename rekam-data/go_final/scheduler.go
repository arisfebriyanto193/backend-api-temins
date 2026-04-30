package main

import (
	"fmt"
	"log"
	"strings"
	"time"
)


// ==========================
// DATABASE FLUSH (Scheduler)
// ==========================

// flushBatch melakukan INSERT batch ke PostgreSQL dalam satu transaksi.
// Dipisahkan dari flushToDB agar defer tx.Rollback() dan stmt.Close()
// dieksekusi setiap kali pemanggilan, bukan terkena defer-in-loop bug.
func flushBatch(dataToSave []BufferData, batchTimestampStr string) {
	tx, err := pgDB.Begin()
	if err != nil {
		msg := fmt.Sprintf("Transaction error: %v", err)
		log.Printf("❌ [DB] %s\n", msg)
		writeLogToFile("ERROR", msg)
		return
	}
	defer tx.Rollback()

	stmt, err := tx.Prepare("INSERT INTO sensor_logs (device_unique_id, parameter_name, value, recorded_at) VALUES ($1, $2, $3, $4)")
	if err != nil {
		msg := fmt.Sprintf("Prepare error: %v", err)
		log.Printf("❌ [DB] %s\n", msg)
		writeLogToFile("ERROR", msg)
		return
	}
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

	if successCount > 0 {
		if err := tx.Commit(); err != nil {
			msg := fmt.Sprintf("Commit error: %v", err)
			log.Printf("❌ [DB] %s\n", msg)
			writeLogToFile("ERROR", msg)
		} else {
			msg := fmt.Sprintf("Berhasil menyimpan %d data dari total %d data pada %s", successCount, len(dataToSave), batchTimestampStr)
			log.Printf("💾 [DB] Flushed %d records at %s\n", successCount, batchTimestampStr)
			writeLogToFile("SUCCESS", msg)

			// Log setiap record yang berhasil
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

// flushToDB adalah goroutine background yang menunggu interval 5 menit berikutnya,
// lalu setiap 5 menit membaca buffer dan mem-flush-nya ke PostgreSQL.
// Ditambahkan 2 detik toleransi agar timestamp tidak meleset ke interval sebelumnya.
func flushToDB() {
	nextInterval, secondsToWait := getNext5MinInterval()
	log.Printf("⏰ [DB] Next flush at: %s WIB\n", formatWIBTimestamp(nextInterval))
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
				flushBatch(dataToSave, batchTimestampStr)
			}
		}

		nextInterval, secondsToWait = getNext5MinInterval()
		time.Sleep(secondsToWait + 2*time.Second)
	}
}

// ==========================
// BACKGROUND WORKERS
// ==========================

// periodicBufferSync menyinkronkan in-memory buffer ke file JSON setiap 15 detik.
// Ini memastikan data tidak hilang jika program crash di antara interval 5 menit.
// Tidak menulis ke file jika buffer kosong (hemat I/O disk).
func periodicBufferSync() {
	ticker := time.NewTicker(15 * time.Second)
	for range ticker.C {
		bufferMu.Lock()
		if len(bufferData) > 0 {
			writeBufferToFile(bufferData)
			log.Printf("💾 [BUFFER-FILE] Synced %d entries to file\n", len(bufferData))
		}
		bufferMu.Unlock()
	}
}

// autoRefreshSensorCache memperbarui cache tinggi sensor dari MySQL secara periodik.
// Interval diperpanjang ke 60 detik untuk mengurangi beban CPU & koneksi MySQL.
func autoRefreshSensorCache() {
	iteration := 0
	for {
		time.Sleep(60 * time.Second) // Diperpanjang dari 30s → 60s untuk hemat CPU
		iteration++

		log.Printf("\n🔄 [AUTO-REFRESH #%d] Refreshing sensor cache...\n", iteration)

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

// statusMonitor mencetak ringkasan status sistem setiap 1 menit ke stdout.
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
