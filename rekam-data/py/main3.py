import json
import ssl
import time
import threading
import math
import mysql.connector
from mysql.connector import Error
from paho.mqtt import client as mqtt

# ==========================
# CONFIG
# ==========================
BROKER_HOST = "karsacerdasinovatif.web.id"
BROKER_PORT = 8081
FLUSH_INTERVAL = 300  # 5 menit

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
    try:
        with open("devices.json", "r") as f:
            return json.load(f).get("devices", [])
    except Exception as e:
        print("❌ Gagal load devices.json:", e)
        return []

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

# ==========================
# MQTT CALLBACK
# ==========================
def on_connect(client, userdata, flags, rc):
    global flush_thread_started

    if rc == 0:
        print("✅ Connected to MQTT WebSocket")

        if not flush_thread_started:
            threading.Thread(target=flush_to_db, daemon=True).start()
            flush_thread_started = True
            print("⏱️ Flush thread started")

        for device in load_devices():
            for topic in generate_topics(device):
                client.subscribe(topic)
                print("📡 Subscribed:", topic)
    else:
        print("❌ MQTT Connect failed, rc =", rc)

def on_message(client, userdata, msg):
    topic = msg.topic
    payload = msg.payload.decode(errors="ignore")

    try:
        parts = topic.split("/")
        if len(parts) < 4:
            print("⚠️ FORMAT TOPIC TIDAK VALID:", topic)
            return

        device_id = parts[1]
        parameter = parts[-1]

        # ======================
        # PARSE VALUE (AMAN SEMUA FORMAT)
        # ======================
        try:
            data = json.loads(payload)

            if isinstance(data, dict):
                if "value" not in data:
                    print("❌ JSON TANPA KEY 'value'")
                    print("   Topic   :", topic)
                    print("   Payload :", payload)
                    return
                value = float(data["value"])

            elif isinstance(data, (int, float)):
                value = float(data)

            elif isinstance(data, str):
                value = float(data)

            else:
                print("❌ FORMAT JSON TIDAK DIDUKUNG")
                print("   Topic   :", topic)
                print("   Payload :", payload)
                print("   Type    :", type(data))
                return

        except json.JSONDecodeError:
            # payload bukan JSON
            try:
                value = float(payload)
            except ValueError as e:
                print("❌ PAYLOAD BUKAN ANGKA")
                print("   Topic   :", topic)
                print("   Payload :", payload)
                print("   Error   :", e)
                return

        except (TypeError, ValueError) as e:
            print("❌ VALUE PARSE ERROR")
            print("   Topic   :", topic)
            print("   Payload :", payload)
            print("   Error   :", e)
            return

        # ======================
        # VALIDASI NILAI
        # ======================
        if math.isnan(value) or math.isinf(value):
            print("❌ INVALID NUMERIC VALUE")
            print("   Device  :", device_id)
            print("   Param   :", parameter)
            print("   Topic   :", topic)
            print("   Payload :", payload)
            return

        with buffer_lock:
            data_buffer[(device_id, parameter)] = {
                "device_id": device_id,
                "parameter": parameter,
                "value": value
            }

    except Exception as e:
        print("❌ UNEXPECTED MQTT ERROR")
        print("   Topic   :", topic)
        print("   Payload :", payload)
        print("   Error   :", e)

# ==========================
# FLUSH DATA TO DATABASE
# ==========================
def flush_to_db():
    while True:
        time.sleep(FLUSH_INTERVAL)

        with buffer_lock:
            if not data_buffer:
                print("ℹ️ Tidak ada data dalam buffer.")
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

            rows = []
            for d in data_to_save:
                v = d["value"]

                if math.isnan(v) or math.isinf(v):
                    print("⚠️ SKIP DB INSERT (NaN/Inf):", d)
                    continue

                rows.append((
                    d["device_id"],
                    d["parameter"],
                    v,
                    None
                ))

            if not rows:
                print("⚠️ Semua data invalid, tidak ada yang disimpan.")
                continue

            cursor.executemany(sql, rows)
            conn.commit()

            print(f"💾 {len(rows)} data berhasil disimpan ke DB")

            cursor.close()
            conn.close()

        except Error as e:
            print("❌ MYSQL ERROR")
            print("   errno     :", e.errno)
            print("   sqlstate  :", e.sqlstate)
            print("   message   :", e.msg)

        except Exception as e:
            print("❌ GENERAL DB ERROR:", e)

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
print("🔌 Connecting MQTT...")
client.connect(BROKER_HOST, BROKER_PORT, 60)
client.loop_forever()
