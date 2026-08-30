package main

import (
	"database/sql"
	"fmt"
	"log"
	"time"
)

// ==========================
// DATABASE INITIALIZATION
// ==========================

// initPostgres membuka koneksi pool ke PostgreSQL dan melakukan ping awal.
func initPostgres() error {
	var err error
	pgDB, err = sql.Open("postgres", POSTGRES_DSN)
	if err != nil {
		return fmt.Errorf("failed to open postgres: %w", err)
	}

	pgDB.SetMaxOpenConns(5)
	pgDB.SetMaxIdleConns(2)
	pgDB.SetConnMaxLifetime(5 * time.Minute)
	pgDB.SetConnMaxIdleTime(2 * time.Minute)

	if err := pgDB.Ping(); err != nil {
		pgDB.Close()
		return fmt.Errorf("failed to ping postgres: %w", err)
	}

	log.Println("✅ [PostgreSQL] Connection pool initialized (Max: 5 conns)")
	return nil
}

// initMySQL membuka koneksi pool ke MySQL dan melakukan ping awal.
func initMySQL() error {
	var err error
	mysqlDB, err = sql.Open("mysql", MYSQL_DSN)
	if err != nil {
		return fmt.Errorf("failed to open mysql: %w", err)
	}

	mysqlDB.SetMaxOpenConns(3)
	mysqlDB.SetMaxIdleConns(1)
	mysqlDB.SetConnMaxLifetime(5 * time.Minute)
	mysqlDB.SetConnMaxIdleTime(2 * time.Minute)

	if err := mysqlDB.Ping(); err != nil {
		mysqlDB.Close()
		return fmt.Errorf("failed to ping mysql: %w", err)
	}

	log.Println("✅ [MySQL] Connection pool initialized (Max: 3 conns)")
	return nil
}

// ==========================
// DATABASE HEALTH CHECK
// ==========================

// checkPostgresConnection melakukan ping ke PostgreSQL dan mengembalikan true jika berhasil.
func checkPostgresConnection() bool {
	if pgDB == nil {
		log.Println("❌ [PostgreSQL] Pool not initialized")
		return false
	}

	if err := pgDB.Ping(); err != nil {
		log.Printf("❌ [PostgreSQL] Ping FAILED: %v\n", err)
		return false
	}

	log.Println("✅ [PostgreSQL] Database Connected")
	return true
}

// checkMySQLConnection melakukan ping ke MySQL dan mengembalikan true jika berhasil.
func checkMySQLConnection() bool {
	if mysqlDB == nil {
		log.Println("❌ [MySQL] Pool not initialized")
		return false
	}

	if err := mysqlDB.Ping(); err != nil {
		log.Printf("❌ [MySQL] Ping FAILED: %v\n", err)
		return false
	}

	log.Println("✅ [MySQL] Database Connected")
	return true
}

// ==========================
// SENSOR CACHE QUERY
// ==========================

// getAllSensorHeightsFromMySQL mengambil semua data tinggi_sensor dari MySQL
// dan mengembalikannya sebagai map[deviceID]tinggiSensor.
// Data yang tidak valid (kosong, null, <=0, atau >10000) akan di-skip.
func getAllSensorHeightsFromMySQL() map[string]float64 {
	if mysqlDB == nil {
		log.Println("❌ [MySQL] Pool not initialized")
		return make(map[string]float64)
	}

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

		if tinggiSensor.Float64 <= 0 {
			skippedCount++
			continue
		}

		mysqlData[deviceID] = tinggiSensor.Float64
	}

	if skippedCount > 0 {
		log.Printf("⚠️ [MySQL] Skipped %d devices with invalid tinggi_sensor\n", skippedCount)
	}

	log.Printf("✅ [MySQL] Loaded %d devices into cache\n", len(mysqlData))
	return mysqlData
}

// getSensorHeightFromCache mengambil tinggi sensor dari in-memory cache secara thread-safe.
func getSensorHeightFromCache(deviceID string) (float64, bool) {
	stateLock.RLock()
	defer stateLock.RUnlock()
	height, exists := sensorHeightCache[deviceID]
	return height, exists
}
