package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

// ==========================
// LOG WEBSOCKET SERVER
// ==========================

var (
	upgrader = websocket.Upgrader{
		ReadBufferSize:  1024,
		WriteBufferSize: 1024,
		CheckOrigin: func(r *http.Request) bool {
			// Allow all origins (untuk development)
			// Untuk production, batasi origin yang diizinkan
			return true
		},
	}

	// Broadcast channel untuk mengirim log ke semua client
	logBroadcast = make(chan LogMessage, 100)

	// Daftar client yang terhubung
	clients     = make(map[*websocket.Conn]bool)
	clientsLock sync.RWMutex

	// Stats untuk monitoring
	logStats = &LogStats{
		StartTime: time.Now(),
	}
	statsLock sync.RWMutex
)

// LogMessage struktur untuk log message
type LogMessage struct {
	Timestamp string      `json:"timestamp"`
	Level     string      `json:"level"`     // INFO, SUCCESS, WARNING, ERROR, DEBUG
	Category  string      `json:"category"`  // MQTT, DB, BUFFER, CONFIG, AWLR, CH, etc.
	Message   string      `json:"message"`
	Data      interface{} `json:"data,omitempty"`
}

// LogStats untuk statistik sistem
type LogStats struct {
	StartTime            time.Time `json:"start_time"`
	MqttMessages         int64     `json:"mqtt_messages"`
	AwlrCalculations     int64     `json:"awlr_calculations"`
	ChRestarts           int64     `json:"ch_restarts"`
	DeviceCount          int       `json:"device_count"`
	CacheCount           int       `json:"cache_count"`
	ChTrackingCount      int       `json:"ch_tracking_count"`
	MqttConnected        bool      `json:"mqtt_connected"`
	LastFlushTime        string    `json:"last_flush_time"`
	TotalRecordsFlushed  int64     `json:"total_records_flushed"`
	ConnectedClients     int       `json:"connected_clients"`
}

// StatusResponse untuk endpoint status
type StatusResponse struct {
	Status  string    `json:"status"`
	Stats   LogStats  `json:"stats"`
	Uptime  string    `json:"uptime"`
}

// ==========================
// LOGGING FUNCTIONS
// ==========================

// LogInfo logs info level message
func LogInfo(category, message string, data interface{}) {
	sendLog("INFO", category, message, data)
	log.Printf("ℹ️ [%s] %s\n", category, message)
}

// LogSuccess logs success level message
func LogSuccess(category, message string, data interface{}) {
	sendLog("SUCCESS", category, message, data)
	log.Printf("✅ [%s] %s\n", category, message)
}

// LogWarning logs warning level message
func LogWarning(category, message string, data interface{}) {
	sendLog("WARNING", category, message, data)
	log.Printf("⚠️ [%s] %s\n", category, message)
}

// LogError logs error level message
func LogError(category, message string, data interface{}) {
	sendLog("ERROR", category, message, data)
	log.Printf("❌ [%s] %s\n", category, message)
}

// LogDebug logs debug level message
func LogDebug(category, message string, data interface{}) {
	sendLog("DEBUG", category, message, data)
	log.Printf("🔍 [%s] %s\n", category, message)
}

// sendLog mengirim log ke broadcast channel
func sendLog(level, category, message string, data interface{}) {
	logMsg := LogMessage{
		Timestamp: formatWIBTimestamp(getWIBTime()),
		Level:     level,
		Category:  category,
		Message:   message,
		Data:      data,
	}

	// Non-blocking send
	select {
	case logBroadcast <- logMsg:
	default:
		// Channel full, skip this log
	}
}

// ==========================
// WEBSOCKET HANDLERS
// ==========================

// handleWebSocket handles WebSocket connections
func handleWebSocket(w http.ResponseWriter, r *http.Request) {
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Printf("❌ [WebSocket] Upgrade error: %v\n", err)
		return
	}

	// Register client
	clientsLock.Lock()
	clients[conn] = true
	clientCount := len(clients)
	clientsLock.Unlock()

	log.Printf("✅ [WebSocket] New client connected from %s (total: %d)\n", 
		r.RemoteAddr, clientCount)

	// Send welcome message
	welcomeMsg := LogMessage{
		Timestamp: formatWIBTimestamp(getWIBTime()),
		Level:     "SUCCESS",
		Category:  "WebSocket",
		Message:   fmt.Sprintf("Connected to TEMINS IoT Logger. Total clients: %d", clientCount),
		Data: map[string]interface{}{
			"client_count": clientCount,
			"server_time":  formatWIBTimestamp(getWIBTime()),
		},
	}
	conn.WriteJSON(welcomeMsg)

	// Send current stats
	go sendStatsToClient(conn)

	// Handle client messages (for ping/pong)
	go func() {
		defer func() {
			clientsLock.Lock()
			delete(clients, conn)
			clientCount := len(clients)
			clientsLock.Unlock()
			
			conn.Close()
			log.Printf("🔌 [WebSocket] Client disconnected (remaining: %d)\n", clientCount)
		}()

		for {
			_, _, err := conn.ReadMessage()
			if err != nil {
				break
			}
		}
	}()
}

// sendStatsToClient mengirim stats awal ke client
func sendStatsToClient(conn *websocket.Conn) {
	statsMsg := LogMessage{
		Timestamp: formatWIBTimestamp(getWIBTime()),
		Level:     "INFO",
		Category:  "Stats",
		Message:   "Current system statistics",
		Data:      getCurrentStats(),
	}
	conn.WriteJSON(statsMsg)
}

// broadcastLogs broadcasts log messages to all connected clients
func broadcastLogs() {
	for {
		msg := <-logBroadcast

		clientsLock.RLock()
		for client := range clients {
			err := client.WriteJSON(msg)
			if err != nil {
				log.Printf("⚠️ [WebSocket] Write error: %v\n", err)
				client.Close()
				clientsLock.RUnlock()
				clientsLock.Lock()
				delete(clients, client)
				clientsLock.Unlock()
				clientsLock.RLock()
			}
		}
		clientsLock.RUnlock()
	}
}

// ==========================
// HTTP API HANDLERS
// ==========================

// handleStatus handles /api/status endpoint
func handleStatus(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

	if r.Method == "OPTIONS" {
		w.WriteHeader(http.StatusOK)
		return
	}

	stats := getCurrentStats()
	uptime := time.Since(logStats.StartTime)

	response := StatusResponse{
		Status: "running",
		Stats:  stats,
		Uptime: formatDuration(uptime),
	}

	json.NewEncoder(w).Encode(response)
}

// handleLogs handles /api/logs endpoint (returns recent logs)
func handleLogs(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

	if r.Method == "OPTIONS" {
		w.WriteHeader(http.StatusOK)
		return
	}

	// Return basic info since we're using WebSocket for real-time logs
	response := map[string]interface{}{
		"message": "Use WebSocket at /ws for real-time logs",
		"ws_url":  "ws://localhost:8011/ws",
		"stats":   getCurrentStats(),
	}

	json.NewEncoder(w).Encode(response)
}

// handleHealth handles /api/health endpoint
func handleHealth(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*")

	response := map[string]interface{}{
		"status":    "healthy",
		"timestamp": formatWIBTimestamp(getWIBTime()),
		"mqtt":      mqttConnected,
	}

	json.NewEncoder(w).Encode(response)
}

// ==========================
// UTILITY FUNCTIONS
// ==========================

// getCurrentStats returns current system statistics
func getCurrentStats() LogStats {
	mapLock.RLock()
	deviceCount := len(deviceMap)
	mapLock.RUnlock()

	cacheLock.RLock()
	cacheCount := len(sensorHeightCache)
	cacheLock.RUnlock()

	chLock.RLock()
	chDeviceCount := len(lastChValue)
	chLock.RUnlock()

	statsLock.RLock()
	lastFlush := logStats.LastFlushTime
	totalFlushed := logStats.TotalRecordsFlushed
	statsLock.RUnlock()

	clientsLock.RLock()
	connectedClients := len(clients)
	clientsLock.RUnlock()

	return LogStats{
		StartTime:           logStats.StartTime,
		MqttMessages:        mqttMessageCount,
		AwlrCalculations:    awlrCalculationCount,
		ChRestarts:          restartDetected,
		DeviceCount:         deviceCount,
		CacheCount:          cacheCount,
		ChTrackingCount:     chDeviceCount,
		MqttConnected:       mqttConnected,
		LastFlushTime:       lastFlush,
		TotalRecordsFlushed: totalFlushed,
		ConnectedClients:    connectedClients,
	}
}

// updateFlushStats updates flush statistics
func updateFlushStats(recordCount int) {
	statsLock.Lock()
	logStats.LastFlushTime = formatWIBTimestamp(getWIBTime())
	logStats.TotalRecordsFlushed += int64(recordCount)
	statsLock.Unlock()
}

// formatDuration formats duration to human readable string
func formatDuration(d time.Duration) string {
	days := int(d.Hours()) / 24
	hours := int(d.Hours()) % 24
	minutes := int(d.Minutes()) % 60
	seconds := int(d.Seconds()) % 60

	if days > 0 {
		return fmt.Sprintf("%dd %dh %dm %ds", days, hours, minutes, seconds)
	} else if hours > 0 {
		return fmt.Sprintf("%dh %dm %ds", hours, minutes, seconds)
	} else if minutes > 0 {
		return fmt.Sprintf("%dm %ds", minutes, seconds)
	}
	return fmt.Sprintf("%ds", seconds)
}

// periodicStatsUpdate sends stats update every minute
func periodicStatsUpdate() {
	ticker := time.NewTicker(1 * time.Minute)
	for range ticker.C {
		stats := getCurrentStats()
		
		LogInfo("Stats", fmt.Sprintf("System Status Update"), stats)
	}
}

// ==========================
// SERVER INITIALIZATION
// ==========================

// StartLogServer starts the WebSocket log server
func StartLogServer(port int) {
	// Start broadcast goroutine
	go broadcastLogs()

	// Start periodic stats update
	go periodicStatsUpdate()

	// Setup HTTP routes
	http.HandleFunc("/ws", handleWebSocket)
	http.HandleFunc("/api/status", handleStatus)
	http.HandleFunc("/api/logs", handleLogs)
	http.HandleFunc("/api/health", handleHealth)

	// Serve static info page
	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/html")
		html := `
<!DOCTYPE html>
<html>
<head>
    <title>TEMINS IoT Logger - WebSocket API</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        h1 { color: #2c3e50; }
        .endpoint { background: #ecf0f1; padding: 15px; margin: 10px 0; border-radius: 5px; }
        code { background: #34495e; color: #ecf0f1; padding: 2px 6px; border-radius: 3px; }
        .status { color: #27ae60; font-weight: bold; }
    </style>
</head>
<body>
    <h1>🚀 TEMINS IoT Logger - WebSocket API</h1>
    <p class="status">✅ Server is running</p>
    
    <h2>Available Endpoints:</h2>
    
    <div class="endpoint">
        <h3>📡 WebSocket (Real-time Logs)</h3>
        <p><code>ws://localhost:` + fmt.Sprintf("%d", port) + `/ws</code></p>
        <p>Connect to receive real-time log messages</p>
    </div>
    
    <div class="endpoint">
        <h3>📊 GET /api/status</h3>
        <p><code>http://localhost:` + fmt.Sprintf("%d", port) + `/api/status</code></p>
        <p>Get current system status and statistics</p>
    </div>
    
    <div class="endpoint">
        <h3>📋 GET /api/logs</h3>
        <p><code>http://localhost:` + fmt.Sprintf("%d", port) + `/api/logs</code></p>
        <p>Get information about logging system</p>
    </div>
    
    <div class="endpoint">
        <h3>💚 GET /api/health</h3>
        <p><code>http://localhost:` + fmt.Sprintf("%d", port) + `/api/health</code></p>
        <p>Health check endpoint</p>
    </div>
    
    <h2>Next.js Integration Example:</h2>
    <pre><code>
// hooks/useLogStream.ts
import { useEffect, useState } from 'react';

export function useLogStream() {
  const [logs, setLogs] = useState([]);
  const [connected, setConnected] = useState(false);

  useEffect(() => {
    const ws = new WebSocket('ws://localhost:` + fmt.Sprintf("%d", port) + `/ws');
    
    ws.onopen = () => {
      console.log('Connected to log stream');
      setConnected(true);
    };
    
    ws.onmessage = (event) => {
      const logMessage = JSON.parse(event.data);
      setLogs(prev => [...prev, logMessage].slice(-100)); // Keep last 100 logs
    };
    
    ws.onclose = () => {
      console.log('Disconnected from log stream');
      setConnected(false);
    };
    
    return () => ws.close();
  }, []);

  return { logs, connected };
}
    </code></pre>
</body>
</html>
        `
		fmt.Fprint(w, html)
	})

	addr := fmt.Sprintf(":%d", port)
	log.Printf("🌐 [WebSocket] Log server starting on %s\n", addr)
	log.Printf("   - WebSocket: ws://localhost:%d/ws\n", port)
	log.Printf("   - Status API: http://localhost:%d/api/status\n", port)
	log.Printf("   - Health API: http://localhost:%d/api/health\n", port)
	log.Printf("   - Info Page: http://localhost:%d/\n", port)

	if err := http.ListenAndServe(addr, nil); err != nil {
		log.Fatalf("❌ [WebSocket] Server error: %v\n", err)
	}
}
