package main

import (
	"database/sql"
	"encoding/json"
	"log"
	"os"
	"time"
)

// ==========================
// CH STATE PERSISTENCE
// ==========================

// loadCHState membaca state curah hujan dari file JSON (CH_STATE_FILE).
// Jika file tidak ada atau rusak, state diinisialisasi kosong.
// Dipanggil sekali saat program start.
func loadCHState() {
	chState.Lock()
	defer chState.Unlock()

	if _, err := os.Stat(CH_STATE_FILE); os.IsNotExist(err) {
		// Inisialisasi state kosong
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

// saveCHState menyimpan state curah hujan ke file JSON secara aman (baca dulu, lalu tulis).
// Dipanggil oleh autoSaveCHState dan saat shutdown.
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

// autoSaveCHState berjalan sebagai goroutine background yang menyimpan
// state curah hujan setiap 10 detik untuk mencegah kehilangan data jika program crash.
func autoSaveCHState() {
	ticker := time.NewTicker(10 * time.Second)
	for range ticker.C {
		saveCHState()
	}
}

// ==========================
// CURAH HUJAN PROCESSOR
// ==========================

// getLastValueFromDB mengambil nilai parameter terakhir dari sensor_logs PostgreSQL
// untuk deviceID pada tanggal tertentu. Digunakan untuk recovery state setelah restart.
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

// processCurahHujan memproses nilai CH (curah hujan mentah dari sensor) dan
// menghitung nilai CHA (accumulated) dengan mempertimbangkan:
//   - Hari baru → reset dan recovery dari database jika program baru restart
//   - Sensor restart (nilai turun) → akumulasi tetap dilanjutkan
//   - Normal naik → hitung selisih dan tambah ke akumulasi
//
// Mengembalikan (nilai_cha, restart_terdeteksi).
func processCurahHujan(deviceID string, chValue float64) (float64, bool) {
	now := getWIBTime()
	currentDate := formatWIBDate(now)

	chState.Lock()
	defer chState.Unlock()

	lastDate, dateExists := chState.LastDate[deviceID]

	// HARI BARU → RESET (atau startup baru)
	if !dateExists || lastDate != currentDate {
		log.Printf("🌅 [CH] Init state for %s on %s", deviceID, currentDate)

		var initialAccum float64

		// Coba recovery dari database (kasus: program restart tapi masih hari sama)
		lastCHA := getLastValueFromDB(deviceID, "cha", currentDate)
		lastRawCH := getLastValueFromDB(deviceID, "ch", currentDate)

		if lastCHA > 0 {
			log.Printf("📥 [CH] Recovered CHA state for %s: %.2f mm (Last Raw CH: %.2f)", deviceID, lastCHA, lastRawCH)

			if chValue < lastRawCH {
				// Sensor sudah restart (nilai lebih kecil dari data terakhir di DB)
				// → chValue murni tambahan baru, langsung tambahkan ke lastCHA
				initialAccum = lastCHA + chValue
			} else {
				// Sensor tidak restart → hitung selisih dari lastRawCH
				diff := chValue - lastRawCH
				initialAccum = lastCHA + diff
			}
		} else {
			// Tidak ada data hari ini di DB → mulai dari 0 (hari baru sungguhan)
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

	// SENSOR RESTART (nilai turun dibanding sebelumnya)
	// Artinya sensor mati/restart dan mulai menghitung ulang dari 0.
	// → Tambahkan chValue ke CHA (hujan sejak sensor nyala kembali)
	// → Reset lastValue ke 0 (bukan ke chValue!), karena sensor sekarang
	//   menghitung dari 0, sehingga interval berikutnya diff dihitung dari 0.
	//
	// Contoh:
	//   lastValue=0.20 → restart → chValue=0.10
	//   CHA += 0.10, lastValue = 0
	//   Next ch=0.20: diff = 0.20 - 0 = 0.20 ✅ (bukan 0.20 - 0.10 = 0.10 ❌)
	if chValue < lastValue {
		stateLock.Lock()
		restartDetected++
		stateLock.Unlock()

		newAccumulation := currentAccumulation + chValue
		chState.Accumulated[deviceID] = newAccumulation
		chState.LastValue[deviceID] = 0 // reset ke 0, bukan ke chValue

		log.Printf("🔄 [CH] Restart detected → +%.2f mm (lastValue reset ke 0)", chValue)
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
