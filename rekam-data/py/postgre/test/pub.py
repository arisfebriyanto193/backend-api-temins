import paho.mqtt.client as mqtt
import time
import threading
import random

# Konfigurasi broker MQTT
BROKER = "karsacerdasinovatif.web.id"
PORT = 8081  # Port WSS

# Setiap topik dengan tipe nilai dan interval
TOPICS_CONFIG = {
    "temins_iot/3345/data/tsp": {"type": "random", "min": 10, "max": 14, "interval": 1},
    "temins_iot/3345/data/tuc": {"type": "constant", "value": 546, "interval": 2},
   # "topic/3": {"type": "random", "min": 100, "max": 200, "interval": 1.5},
   # "topic/4": {"type": "constant", "value": 5, "interval": 0.5},
   # "topic/5": {"type": "random", "min": -50, "max": 50, "interval": 3},
   # "topic/6": {"type": "constant", "value": 12, "interval": 1},
}

# Callback saat koneksi berhasil
def on_connect(client, userdata, flags, rc, properties=None):
    if rc == 0:
        print("Terhubung ke broker MQTT!")
    else:
        print("Gagal terhubung, kode:", rc)

# Callback saat publish berhasil
def on_publish(client, userdata, mid):
    print(f"Pesan berhasil dipublish dengan mid: {mid}")

# Membuat client MQTT
client = mqtt.Client(client_id="pub_ws_client", transport="websockets", protocol=mqtt.MQTTv311)
client.on_connect = on_connect
client.on_publish = on_publish

# Aktifkan TLS untuk WSS
client.tls_set()
# client.tls_insecure_set(True)  # gunakan jika broker pakai self-signed certificate

# Koneksi ke broker
client.connect(BROKER, PORT)

# Mulai loop MQTT
client.loop_start()

# Fungsi publish per topic
def publish_topic(topic, cfg):
    while True:
        if cfg["type"] == "random":
            value = random.uniform(cfg["min"], cfg["max"])
        else:
            value = cfg["value"]
        result = client.publish(topic, value)
        if result[0] == 0:
            print(f"Mengirim {value:.2f} ke topik '{topic}'")
        else:
            print(f"Gagal mengirim pesan ke topik {topic}")
        time.sleep(cfg["interval"])

# Jalankan thread per topic
for topic, cfg in TOPICS_CONFIG.items():
    threading.Thread(
        target=publish_topic,
        args=(topic, cfg),
        daemon=True
    ).start()

# Agar main thread tidak berhenti
while True:
    time.sleep(1)
