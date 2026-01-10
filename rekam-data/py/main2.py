import json
import ssl
import time
import threading
import mysql.connector
from paho.mqtt import client as mqtt

# ==========================
# CONFIG
# ==========================
BROKER_HOST = "karsacerdasinovatif.web.id"
BROKER_PORT = 8081
FLUSH_INTERVAL = 300  # 5 menit (detik)

DB_CONFIG = {
    "host": "localhost",
    "user": "temins",
    "password": "YFBmEzBBtty6hBC7",
    "database": "temins"
}

# ==========================
# Load devices
# ==========================
def load_devices():
    with open("devices.json", "r") as f:
        return json.load(f)["devices"]

# ==========================
# Generate topics
# ==========================
def generate_topics(device_id):
    params = [
        "su", "ku", "tu", "su2", "tu2", "ku2",
        "su3", "ku3", "tt", "ka", "aa",
        "rm", "ch", "kam", "cha", "chr",
        "tsp", "tb", "ac", "da", "sm", "sur"
    ]
    return [f"temins_iot/{device_id}/data/{p}" for p in params]

# ==========================
# MEMORY STORAGE
# key = (device_id, parameter)
# ==========================
data_buffer = {}
buffer_lock = threading.Lock()

# ==========================
# MQTT CALLBACK
# ==========================
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        print("✅ Connected to MQTT WebSocket")
        for device in load_devices():
            for topic in generate_topics(device):
                client.subscribe(topic)
                print("📡 Subscribed:", topic)
    else:
        print("❌ MQTT Connect failed:", rc)

def on_message(client, userdata, msg):
    try:
        topic = msg.topic
        payload = msg.payload.decode()

        parts = topic.split("/")
        device_id = parts[1]
        parameter = parts[-1]

        # value bisa angka atau JSON
        try:
            data = json.loads(payload)
            value = float(data.get("value", 0))
        except:
            value = float(payload)

        with buffer_lock:
            data_buffer[(device_id, parameter)] = {
                "device_id": device_id,
                "parameter": parameter,
                "value": value
            }

        #print(f"📥 {device_id} | {parameter} = {value}")

    except Exception as e:
        print("⚠️ Error parsing message:", e)

# ==========================
# FLUSH DATA TO DATABASE
# ==========================
def flush_to_db():
    while True:
        time.sleep(FLUSH_INTERVAL)

        with buffer_lock:
            if not data_buffer:
                continue

            data_to_save = list(data_buffer.values())
            data_buffer.clear()

        try:
            conn = mysql.connector.connect(**DB_CONFIG)
            cursor = conn.cursor()

            sql = """
                INSERT INTO sensor_logs
                (device_unique_id, parameter_name, value, topic)
                VALUES (%s, %s, %s, %s)
            """

            rows = [
                (
                    d["device_id"],
                    d["parameter"],
                    d["value"],
                    None  # topic NULL
                )
                for d in data_to_save
            ]

            cursor.executemany(sql, rows)
            conn.commit()

            print(f"💾 {len(rows)} data disimpan ke DB")

            cursor.close()
            conn.close()

        except Exception as e:
            print("❌ DB Error:", e)

# ==========================
# MQTT SETUP (WebSocket)
# ==========================
client = mqtt.Client(
    client_id="temins_ws_logger",
    transport="websockets"
)

client.on_connect = on_connect
client.on_message = on_message

client.tls_set(cert_reqs=ssl.CERT_NONE)
client.tls_insecure_set(True)
client.ws_set_options(path="/mqtt")

# ==========================
# START
# ==========================
threading.Thread(target=flush_to_db, daemon=True).start()

print("🔌 Connecting MQTT...")
client.connect(BROKER_HOST, BROKER_PORT, 60)
client.loop_forever()
