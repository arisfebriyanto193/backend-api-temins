package main

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"strings"
)

// ==========================
// BUFFER HANDLER
// Strategi: in-memory map sebagai primary (cepat untuk MQTT),
// di-sync ke file JSON setiap 15 detik oleh periodicBufferSync.
// Saat 5 menit flush, data dibaca dari file JSON, bukan memory.
// Ini memastikan data tidak hilang jika program crash.
// ==========================

// saveToBuffer menyimpan nilai sensor ke in-memory buffer.
// Untuk parameter "ch" (curah hujan), juga menghitung dan menyimpan "cha" (accumulated).
// Untuk perangkat AWLR dengan parameter "tuc", menghitung "result_tinggi_air".
// Buffer akan di-sync ke file oleh goroutine periodicBufferSync setiap 15 detik,
// lalu di-flush ke database setiap 5 menit oleh flushToDB.
func saveToBuffer(deviceID, parameter string, value float64) {
	// Ambil tipe device dari state (read-only)
	stateLock.RLock()
	deviceType := deviceMap[deviceID]
	if deviceType == "" {
		deviceType = "Unknown Type"
	}
	stateLock.RUnlock()

	timestamp := formatWIBTimestamp(getWIBTime())

	// Hitung CHA SEBELUM acquire bufferMu karena processCurahHujan punya locknya sendiri
	var chaValue float64
	var chRestart bool
	if parameter == "ch" {
		chaValue, chRestart = processCurahHujan(deviceID, value)
	}

	bufferMu.Lock()
	defer bufferMu.Unlock()

	// Simpan data CH + CHA sekaligus
	if parameter == "ch" {
		key := fmt.Sprintf("%s|%s", deviceID, parameter)
		bufferData[key] = BufferData{
			DeviceID:   deviceID,
			DeviceType: deviceType,
			Parameter:  parameter,
			Value:      value,
			Timestamp:  timestamp,
		}
		chaKey := fmt.Sprintf("%s|cha", deviceID)
		bufferData[chaKey] = BufferData{
			DeviceID:   deviceID,
			DeviceType: deviceType,
			Parameter:  "cha",
			Value:      chaValue,
			Timestamp:  timestamp,
		}
		if chRestart {
			log.Printf("📥 [BUFFER] CH=%.2f CHA=%.2f (⚠️ RESTART) dev=%s\n", value, chaValue, deviceID)
		} else {
			log.Printf("📥 [BUFFER] CH=%.2f CHA=%.2f dev=%s\n", value, chaValue, deviceID)
		}
	} else {
		// Parameter selain CH → simpan langsung
		key := fmt.Sprintf("%s|%s", deviceID, parameter)
		bufferData[key] = BufferData{
			DeviceID:   deviceID,
			DeviceType: deviceType,
			Parameter:  parameter,
			Value:      value,
			Timestamp:  timestamp,
		}
	}

	// Kalkulasi AWLR: tinggi air = tinggi sensor - nilai tuc
	if parameter == "tuc" {
		if strings.ToLower(deviceType) == "awlr" {
			if tinggiSensor, exists := getSensorHeightFromCache(deviceID); exists {
				tinggiAir := tinggiSensor - value
				airKey := fmt.Sprintf("%s|result_tinggi_air", deviceID)
				bufferData[airKey] = BufferData{
					DeviceID:   deviceID,
					DeviceType: deviceType,
					Parameter:  "result_tinggi_air",
					Value:      tinggiAir, 
					Timestamp:  timestamp,
				}
				stateLock.Lock()
				awlrCalculationCount++
				stateLock.Unlock()
				
				// Log khusus AWLR
				logStr := fmt.Sprintf("🌊 [AWLR] Waktu: %s | ID: %s | Hitung: %.2f (Tinggi Sensor) - %.2f (Nilai Sensor) = %.2f (Tinggi Air)", timestamp, deviceID, tinggiSensor, value, tinggiAir)
				log.Println(logStr)
				
				// Tulis ke file log
				if f, err := os.OpenFile("awlr-result.log", os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644); err == nil {
					f.WriteString(logStr + "\n")
					f.Close()
				}
			} else {
				log.Printf("⚠️ [AWLR-DEBUG] Skipping ID: %s | DeviceType: %s (is AWLR) tapi tidak ada tinggi_sensor di cache MySQL!\n", deviceID, deviceType)
			}
		} else {
			log.Printf("⚠️ [AWLR-DEBUG] Skipping ID: %s | Terima parameter 'tuc' tapi tipe device-nya bukan 'awlr' (Tipe: '%s')\n", deviceID, deviceType)
		}
	}
}

// ==========================
// FILE SYNC (Crash Recovery)
// ==========================

// writeBufferToFile menulis in-memory bufferData ke BUFFER_FILE_PATH dalam format JSON.
// HARUS dipanggil dengan bufferMu sudah terkunci (Lock).
func writeBufferToFile(data map[string]BufferData) {
	if BUFFER_FILE_PATH == "" {
		return
	}

	jsonData, err := json.MarshalIndent(data, "", "  ")
	if err != nil {
		log.Printf("❌ [BUFFER-FILE] Marshal error: %v\n", err)
		return
	}

	if err := os.WriteFile(BUFFER_FILE_PATH, jsonData, 0644); err != nil {
		log.Printf("❌ [BUFFER-FILE] Write error: %v\n", err)
	}
}

// clearBufferFile menghapus / mengosongkan file buffer JSON setelah flush ke DB berhasil.
func clearBufferFile() {
	if BUFFER_FILE_PATH == "" {
		return
	}
	if err := os.WriteFile(BUFFER_FILE_PATH, []byte("{}"), 0644); err != nil {
		log.Printf("⚠️ [BUFFER-FILE] Clear error: %v\n", err)
	}
}

// LoadBufferFromFile membaca file buffer JSON ke in-memory bufferData saat startup.
// Digunakan untuk crash recovery — data yang belum sempat di-flush ke DB akan dipulihkan.
func LoadBufferFromFile() {
	if BUFFER_FILE_PATH == "" {
		return
	}

	data, err := os.ReadFile(BUFFER_FILE_PATH)
	if err != nil {
		// File tidak ada → normal, tidak perlu recovery
		return
	}

	var loaded map[string]BufferData
	if err := json.Unmarshal(data, &loaded); err != nil {
		log.Printf("⚠️ [BUFFER-FILE] Failed to parse buffer file (corrupt?): %v\n", err)
		return
	}

	if len(loaded) == 0 {
		return
	}

	bufferMu.Lock()
	for k, v := range loaded {
		bufferData[k] = v
	}
	bufferMu.Unlock()

	log.Printf("📦 [BUFFER-FILE] Recovered %d entries from buffer file (crash recovery)\n", len(loaded))
}

// ==========================
// READ & CLEAR
// ==========================

// readAndClearBuffer membaca semua data dari file JSON buffer dan mengosongkannya secara atomik.
// Dipanggil oleh flushToDB setiap 5 menit. Mengembalikan slice kosong jika tidak ada data.
// Urutan: sync memory → file → baca file → clear file & memory.
func readAndClearBuffer() []BufferData {
	bufferMu.Lock()
	defer bufferMu.Unlock()

	if len(bufferData) == 0 {
		return []BufferData{}
	}

	log.Printf("📊 [BUFFER] Entries to flush: %d\n", len(bufferData))

	// Paksa sync terakhir ke file sebelum flush (agar file selalu up-to-date)
	writeBufferToFile(bufferData)

	// Kumpulkan hasil dari in-memory map
	result := make([]BufferData, 0, len(bufferData))
	for _, v := range bufferData {
		result = append(result, v)
	}

	// Kosongkan in-memory map
	bufferData = make(map[string]BufferData)

	// Kosongkan file buffer (flush sudah berhasil, file tidak diperlukan lagi)
	clearBufferFile()

	return result
}
