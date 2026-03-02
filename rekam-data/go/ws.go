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

var (
	upgrader = websocket.Upgrader{
		CheckOrigin: func(r *http.Request) bool {
			return true
		},
	}
	
	clients   = make(map[*websocket.Conn]bool)
	clientsMu sync.RWMutex
	
	flushStats = struct {
		sync.RWMutex
		TotalFlushes   int64
		TotalRecords   int64
		LastFlushTime  string
		LastFlushCount int
	}{}
)

type LogMessage struct {
	Type      string                 `json:"type"`
	Level     string                 `json:"level"`
	Message   string                 `json:"message"`
	Data      map[string]interface{} `json:"data,omitempty"`
	Timestamp string                 `json:"timestamp"`
}

func broadcastLog(msg LogMessage) {
	msg.Timestamp = formatWIBTimestamp(getWIBTime())
	
	data, err := json.Marshal(msg)
	if err != nil {
		return
	}
	
	clientsMu.RLock()
	defer clientsMu.RUnlock()
	
	for client := range clients {
		if err := client.WriteMessage(websocket.TextMessage, data); err != nil {
			client.Close()
			delete(clients, client)
		}
	}
}

func LogInfo(msgType, message string, data map[string]interface{}) {
	broadcastLog(LogMessage{
		Type:    msgType,
		Level:   "info",
		Message: message,
		Data:    data,
	})
}

func LogSuccess(msgType, message string, data map[string]interface{}) {
	broadcastLog(LogMessage{
		Type:    msgType,
		Level:   "success",
		Message: message,
		Data:    data,
	})
}

func LogWarning(msgType, message string, data map[string]interface{}) {
	broadcastLog(LogMessage{
		Type:    msgType,
		Level:   "warning",
		Message: message,
		Data:    data,
	})
}

func LogError(msgType, message string, data map[string]interface{}) {
	broadcastLog(LogMessage{
		Type:    msgType,
		Level:   "error",
		Message: message,
		Data:    data,
	})
}

func updateFlushStats(recordCount int) {
	flushStats.Lock()
	defer flushStats.Unlock()
	
	flushStats.TotalFlushes++
	flushStats.TotalRecords += int64(recordCount)
	flushStats.LastFlushTime = formatWIBTimestamp(getWIBTime())
	flushStats.LastFlushCount = recordCount
}

func handleWebSocket(w http.ResponseWriter, r *http.Request) {
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Printf("❌ [WS] Upgrade error: %v\n", err)
		return
	}
	
	clientsMu.Lock()
	clients[conn] = true
	clientsMu.Unlock()
	
	log.Printf("✅ [WS] New client connected (total: %d)\n", len(clients))
	
	// Send initial stats
	flushStats.RLock()
	conn.WriteJSON(map[string]interface{}{
		"type":             "stats",
		"total_flushes":    flushStats.TotalFlushes,
		"total_records":    flushStats.TotalRecords,
		"last_flush_time":  flushStats.LastFlushTime,
		"last_flush_count": flushStats.LastFlushCount,
	})
	flushStats.RUnlock()
	
	// Keep connection alive
	for {
		if _, _, err := conn.ReadMessage(); err != nil {
			clientsMu.Lock()
			delete(clients, conn)
			clientsMu.Unlock()
			conn.Close()
			log.Printf("🔌 [WS] Client disconnected (remaining: %d)\n", len(clients))
			break
		}
	}
}

func handleStats(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	
	flushStats.RLock()
	stats := map[string]interface{}{
		"total_flushes":    flushStats.TotalFlushes,
		"total_records":    flushStats.TotalRecords,
		"last_flush_time":  flushStats.LastFlushTime,
		"last_flush_count": flushStats.LastFlushCount,
		"connected_clients": len(clients),
	}
	flushStats.RUnlock()
	
	stateLock.RLock()
	stats["mqtt_messages"] = mqttMessageCount
	stats["awlr_calculations"] = awlrCalculationCount
	stats["restart_detected"] = restartDetected
	stats["mqtt_connected"] = mqttConnected
	stats["devices"] = len(deviceMap)
	stats["sensor_cache"] = len(sensorHeightCache)
	stateLock.RUnlock()
	
	chState.RLock()
	stats["ch_tracked_devices"] = len(chState.LastValue)
	chState.RUnlock()
	
	json.NewEncoder(w).Encode(stats)
}

func StartLogServer(port int) {
	http.HandleFunc("/ws", handleWebSocket)
	http.HandleFunc("/stats", handleStats)
	
	// Serve static HTML dashboard
	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/html")
		w.Write([]byte(`
<!DOCTYPE html>
<html>
<head>
    <title>TEMINS IoT Monitor</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background: #1a1a1a; color: #e0e0e0; }
        h1 { color: #4CAF50; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-box { background: #2d2d2d; padding: 15px; border-radius: 8px; border-left: 4px solid #4CAF50; }
        .stat-label { font-size: 12px; color: #888; text-transform: uppercase; }
        .stat-value { font-size: 24px; font-weight: bold; color: #4CAF50; margin-top: 5px; }
        .log-container { background: #2d2d2d; padding: 15px; border-radius: 8px; max-height: 600px; overflow-y: auto; }
        .log-entry { padding: 10px; margin: 5px 0; border-radius: 4px; font-family: 'Courier New', monospace; font-size: 13px; }
        .log-info { background: #1e3a5f; border-left: 4px solid #2196F3; }
        .log-success { background: #1e3d1e; border-left: 4px solid #4CAF50; }
        .log-warning { background: #3d2e1e; border-left: 4px solid #FF9800; }
        .log-error { background: #3d1e1e; border-left: 4px solid #f44336; }
        .timestamp { color: #888; font-size: 11px; }
        .connected { color: #4CAF50; }
        .disconnected { color: #f44336; }
    </style>
</head>
<body>
    <h1>🚀 TEMINS IoT Monitor</h1>
    
    <div class="stats" id="stats"></div>
    
    <h2>📊 Live Logs <span id="connection-status" class="disconnected">● Disconnected</span></h2>
    <div class="log-container" id="logs"></div>
    
    <script>
        const ws = new WebSocket('ws://' + window.location.host + '/ws');
        const logsDiv = document.getElementById('logs');
        const statusSpan = document.getElementById('connection-status');
        const statsDiv = document.getElementById('stats');
        
        ws.onopen = () => {
            statusSpan.textContent = '● Connected';
            statusSpan.className = 'connected';
            addLog('info', 'WebSocket connected');
        };
        
        ws.onclose = () => {
            statusSpan.textContent = '● Disconnected';
            statusSpan.className = 'disconnected';
            addLog('error', 'WebSocket disconnected');
        };
        
        ws.onmessage = (event) => {
            const msg = JSON.parse(event.data);
            
            if (msg.type === 'stats') {
                updateStats(msg);
            } else {
                addLog(msg.level, msg.message, msg.data, msg.timestamp);
            }
        };
        
        function updateStats(data) {
            statsDiv.innerHTML = '';
            const stats = [
                { label: 'Total Flushes', value: data.total_flushes || 0 },
                { label: 'Total Records', value: data.total_records || 0 },
                { label: 'MQTT Messages', value: data.mqtt_messages || 0 },
                { label: 'AWLR Calculations', value: data.awlr_calculations || 0 },
                { label: 'CH Restarts', value: data.restart_detected || 0 },
                { label: 'Devices', value: data.devices || 0 },
                { label: 'Last Flush', value: data.last_flush_time || 'N/A', noFormat: true },
            ];
            
            stats.forEach(stat => {
                const box = document.createElement('div');
                box.className = 'stat-box';
                box.innerHTML = '<div class="stat-label">' + stat.label + '</div>' +
                    '<div class="stat-value">' + (stat.noFormat ? stat.value : stat.value.toLocaleString()) + '</div>';
                statsDiv.appendChild(box);
            });
        }
        
        function addLog(level, message, data, timestamp) {
            const entry = document.createElement('div');
            entry.className = 'log-entry log-' + level;
            
            let content = '<span class="timestamp">[' + (timestamp || new Date().toLocaleString()) + ']</span> <strong>' + message + '</strong>';
            
            if (data) {
                content += '<br><pre style="margin: 5px 0; font-size: 11px;">' + JSON.stringify(data, null, 2) + '</pre>';
            }
            
            entry.innerHTML = content;
            logsDiv.insertBefore(entry, logsDiv.firstChild);
            
            // Keep only last 50 logs
            while (logsDiv.children.length > 50) {
                logsDiv.removeChild(logsDiv.lastChild);
            }
        }
        
        // Fetch stats every 10 seconds
        setInterval(() => {
            fetch('/stats')
                .then(r => r.json())
                .then(updateStats)
                .catch(console.error);
        }, 10000);
        
        // Initial stats load
        fetch('/stats').then(r => r.json()).then(updateStats);
    </script>
</body>
</html>
        `))
	})
	
	log.Printf("🌐 [WS] Log server starting on :%d\n", port)
	log.Printf("   Dashboard: http://localhost:%d\n", port)
	
	server := &http.Server{
		Addr:         fmt.Sprintf(":%d", port),
		ReadTimeout:  15 * time.Second,
		WriteTimeout: 15 * time.Second,
		IdleTimeout:  60 * time.Second,
	}
	
	if err := server.ListenAndServe(); err != nil {
		log.Printf("❌ [WS] Server error: %v\n", err)
	}
}