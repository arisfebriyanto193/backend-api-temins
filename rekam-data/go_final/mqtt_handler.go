package main

import (
	"encoding/json"
	"log"
	"strconv"
	"strings"

	mqtt "github.com/eclipse/paho.mqtt.golang"
)

// ==========================
// MQTT EVENT HANDLERS
// ==========================

// connectionLostHandler dipanggil oleh library MQTT saat koneksi ke broker terputus.
var connectionLostHandler mqtt.ConnectionLostHandler = func(client mqtt.Client, err error) {
	stateLock.Lock()
	mqttConnected = false
	stateLock.Unlock()
	log.Printf("⚠️ [MQTT] Connection Lost: %v\n", err)
}

// reconnectHandler dipanggil oleh library MQTT saat sedang mencoba reconnect.
var reconnectHandler mqtt.ReconnectHandler = func(client mqtt.Client, opts *mqtt.ClientOptions) {
	log.Println("🔄 [MQTT] Attempting to reconnect...")
}

// onConnect dipanggil setelah koneksi ke broker berhasil (termasuk setelah reconnect).
// Subscribe ulang ke wildcard topic agar tidak ada data yang terlewat.
func onConnect(client mqtt.Client) {
	stateLock.Lock()
	mqttConnected = true
	stateLock.Unlock()

	log.Println("✅ [MQTT] Connected to Broker")
	client.Subscribe("temins_iot/#", 0, nil)
	log.Println("📡 [MQTT] Subscribed to temins_iot/#")
}

// onMessage adalah handler default untuk semua pesan MQTT yang masuk.
// Melakukan parsing topic untuk mengekstrak deviceID dan parameter,
// kemudian parsing payload (JSON atau raw) untuk mendapat nilai float,
// lalu menyimpannya ke buffer.
func onMessage(client mqtt.Client, msg mqtt.Message) {
	stateLock.Lock()
	mqttMessageCount++
	stateLock.Unlock()

	payload := string(msg.Payload())
	topic := msg.Topic()

	// Format topic: temins_iot/<deviceID>/data/<parameter>
	parts := strings.Split(topic, "/")
	if len(parts) < 4 || parts[2] != "data" {
		return
	}

	deviceID := parts[1]
	parameter := parts[len(parts)-1]

	var value float64

	// Coba parse sebagai JSON {"value": ...}
	var msgData MQTTMessage
	if err := json.Unmarshal([]byte(payload), &msgData); err == nil {
		switch v := msgData.Value.(type) {
		case float64:
			value = v
		case string:
			value, _ = strconv.ParseFloat(v, 64)
		default:
			return
		}
	} else {
		// Fallback: coba parse sebagai plain text
		if strings.Contains(payload, " - ") {
			// Format: "123.45 - some description"
			parts := strings.Split(payload, " - ")
			value, _ = strconv.ParseFloat(parts[0], 64)
		} else {
			parsedValue, err := strconv.ParseFloat(payload, 64)
			if err != nil {
				return
			}
			value = parsedValue
		}
	}

	saveToBuffer(deviceID, parameter, value)
}
