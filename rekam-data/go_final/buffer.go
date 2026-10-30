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
	if parameter == "ta" {
		if strings.ToLower(deviceType) == "awlr" {
			if tinggiSensor, exists := getSensorHeightFromCache(deviceID); exists {
				tinggiAir := tinggiSensor - value
				
				// Konversi ke meter (m) khusus untuk device 0048
				if deviceID == "0048" {
					tinggiAir = tinggiAir / 100.0 // dibagi 100 untuk dari cm ke m
				}
				
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
				

			}
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

// readAndClearBuffer membaca data dari in-memory buffer HANYA untuk device yang jatuh tempo (dueDeviceIDs),
// lalu menghapus data tersebut dari buffer. Data untuk device yang belum jatuh tempo tetap aman di memory.
func readAndClearBuffer(dueDeviceIDs map[string]bool) []BufferData {
	bufferMu.Lock()
	defer bufferMu.Unlock()

	if len(bufferData) == 0 || len(dueDeviceIDs) == 0 {
		return []BufferData{}
	}

	result := make([]BufferData, 0)
	for k, v := range bufferData {
		if dueDeviceIDs[v.DeviceID] {
			result = append(result, v)
			delete(bufferData, k)
		}
	}

	if len(result) > 0 {
		log.Printf("📊 [BUFFER] Entries to flush: %d (Sisa di buffer: %d)\n", len(result), len(bufferData))
	}

	// Sinkronkan sisa buffer ke file agar crash recovery tetap konsisten
	if len(bufferData) > 0 {
		writeBufferToFile(bufferData)
	} else {
		clearBufferFile()
	}

	return result
}
