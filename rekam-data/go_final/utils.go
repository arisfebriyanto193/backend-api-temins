package main

import "time"

// ==========================
// UTILITY / TIME FUNCTIONS
// ==========================

// getWIBTime mengembalikan waktu sekarang dalam zona WIB (UTC+7).
func getWIBTime() time.Time {
	return time.Now().UTC().Add(WIB_OFFSET)
}

// formatWIBTimestamp memformat time.Time menjadi string "YYYY-MM-DD HH:MM:SS".
func formatWIBTimestamp(t time.Time) string {
	return t.Format("2006-01-02 15:04:05")
}

// formatWIBDate memformat time.Time menjadi string tanggal "YYYY-MM-DD".
func formatWIBDate(t time.Time) string {
	return t.Format("2006-01-02")
}

// getNext5MinInterval menghitung waktu interval 5 menit berikutnya dan selisihnya dari sekarang.
// Digunakan oleh flushToDB untuk menentukan kapan harus flush ke database.
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

	return nextInterval, nextInterval.Sub(nowWIB)
}

// getRounded5MinTimestamp mengembalikan timestamp yang sudah dibulatkan ke interval 5 menit sebelumnya.
// Digunakan sebagai timestamp batch saat flush ke database.
func getRounded5MinTimestamp() time.Time {
	nowWIB := getWIBTime()
	currentMinute := nowWIB.Minute()
	roundedMinute := (currentMinute / 5) * 5

	return time.Date(nowWIB.Year(), nowWIB.Month(), nowWIB.Day(),
		nowWIB.Hour(), roundedMinute, 0, 0, nowWIB.Location())
}
