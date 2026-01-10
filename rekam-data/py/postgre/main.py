import json
import ssl
import time
import threading
import psycopg2
from paho.mqtt import client as mqtt
import sys

# ==========================
# CONFIG
# ==========================
BROKER_HOST = "karsacerdasinovatif.web.id"
BROKER_PORT = 8081
FLUSH_INTERVAL = 300  # 5 menit

DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 5432,
    "user": "postgres",
    "password": "example",
    "dbname": "temins"
}

# ==========================
# CEK KONEKSI DATABASE (WAJIB DI AWAL)
# ==========================
def check_db_connection():
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT 1")
        cur.close()
        conn.close()
        print("✅ Database PostgreSQL connected")
        return True
    except Exception as e:
        print("❌ Database connection FAILED")
        print(e)
        return False


if not check_db_connection():
    print("⛔ Program dihentikan karena DB tidak tersedia")
    sys.exit(1)

# ==========================
# Load devices
# ==========================
def load_devices():
    with open("../devices.json", "r") as f:
        return json.load(f)["devices"]

# ==========================
# Generate topics
# ==========================
PARAMS = [
    "su", "ku", "tu", "su2", "tu2", "ku2",
    "su3", "ku3", "tt", "ka", "aa",
    "rm", "ch", "kam", "cha", "chr",
    "tsp", "tb", "ac", "da", "sm", "sur"
]

def generate_topics(device_id):
    return [f"temins_iot/{device_id}/data/{p}" for p in PARAMS]

# ==========================
# MEMORY STORAGE
# ==========================
data_buffer = {}
buffer_lock = threading.Lock()
flush_thread_started = False
subscribed = False

# ==========================
# MQTT CALLBACK
# ==========================
def on_connect(client, userdata, flags, rc):
    global flush_thread_started, subscribed

    if rc == 0:
        print("✅ MQTT Connected")

        if not flush_thread_started:
            threading.Thread(target=flush_to_db, daemon=True).start()
            flush_thread_started = True
            print("⏱️ Flush thread started")

        if not subscribed:
            for device in load_devices():
                for topic in generate_topics(device):
                    client.subscribe(topic)
            subscribed = True
            print("📡 Subscribed all topics")

    else:
        print("❌ MQTT Connect failed, rc =", rc)

def on_disconnect(client, userdata, rc):
    print("🔌 MQTT Disconnected, rc =", rc)

def on_message(client, userdata, msg):
    try:
        topic = msg.topic
        payload = msg.payload.decode()

        parts = topic.split("/")
        device_id = parts[1]
        parameter = parts[-1]

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

    except Exception as e:
        print("⚠️ Message error:", e)

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
            conn = psycopg2.connect(**DB_CONFIG)
            cur = conn.cursor()

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
                    None
                )
                for d in data_to_save
            ]

            cur.executemany(sql, rows)
            conn.commit()

            print(f"💾 {len(rows)} rows inserted")

            cur.close()
            conn.close()

        except Exception as e:
            print("❌ DB insert error:", e)

# ==========================
# MQTT SETUP
# ==========================
client = mqtt.Client(
    client_id=f"temins_ws_{int(time.time())}",
    transport="websockets",
    protocol=mqtt.MQTTv311
)

client.on_connect = on_connect
client.on_disconnect = on_disconnect
client.on_message = on_message

client.tls_set(cert_reqs=ssl.CERT_NONE)
client.tls_insecure_set(True)
client.ws_set_options(path="/mqtt")

# ==========================
# START
# ==========================
print("🔌 Connecting to MQTT...")
client.connect(BROKER_HOST, BROKER_PORT, keepalive=120)
client.loop_forever()
