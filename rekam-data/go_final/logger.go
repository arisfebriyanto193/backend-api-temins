package main

import (
	"fmt"
	"log"
	"os"
	"strings"
	"time"
)

// ==========================
// LOG FILE HANDLER
// ==========================

// writeLogToFile menulis log level + message ke file error.log dengan timestamp WIB.
// Aman dipanggil dari goroutine mana pun (dilindungi errorLogMutex).
func writeLogToFile(level, message string) {
	errorLogMutex.Lock()
	defer errorLogMutex.Unlock()

	if ERROR_LOG_PATH == "" {
		return
	}

	now := getWIBTime()
	logLine := fmt.Sprintf("[%s] %s: %s\n", formatWIBTimestamp(now), level, message)

	f, err := os.OpenFile(ERROR_LOG_PATH, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	if err != nil {
		log.Printf("⚠️ Failed to open error.log: %v", err)
		return
	}
	defer f.Close()
	f.WriteString(logLine)
}

// cleanOldLogs berjalan sebagai goroutine background, membersihkan entri log yang
// lebih dari 48 jam (2 hari) setiap 6 jam sekali.
func cleanOldLogs() {
	for {
		time.Sleep(6 * time.Hour)

		errorLogMutex.Lock()
		if ERROR_LOG_PATH != "" {
			data, err := os.ReadFile(ERROR_LOG_PATH)
			if err == nil {
				lines := strings.Split(string(data), "\n")
				var newLines []string
				twoDaysAgo := getWIBTime().Add(-48 * time.Hour)

				for _, line := range lines {
					line = strings.TrimSpace(line)
					if len(line) < 21 {
						if len(line) > 0 {
							newLines = append(newLines, line)
						}
						continue
					}
					if strings.HasPrefix(line, "[") {
						timestampStr := line[1:20]
						t, err := time.Parse("2006-01-02 15:04:05", timestampStr)
						if err == nil {
							if t.After(twoDaysAgo) {
								newLines = append(newLines, line)
							}
						} else {
							newLines = append(newLines, line)
						}
					} else {
						newLines = append(newLines, line)
					}
				}

				if len(newLines) > 0 {
					os.WriteFile(ERROR_LOG_PATH, []byte(strings.Join(newLines, "\n")+"\n"), 0644)
				} else {
					os.WriteFile(ERROR_LOG_PATH, []byte(""), 0644)
				}
			}
		}
		errorLogMutex.Unlock()
	}
}
