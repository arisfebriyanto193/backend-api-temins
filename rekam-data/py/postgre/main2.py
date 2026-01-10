import json
import ssl
import time
import threading
import psycopg2
from paho.mqtt import client as mqtt
import sys
import os

# ==========================
# CONFIG
# ==========================
BROKER_HOST = "karsacerdasinovatif.web.id"
BROKER_PORT = 8081
FLUSH_INTERVAL = 300  # 5 menit
DEVICE_JSON_PATH = "../1.json"
JSON_CHECK_INTERVAL = 5  # detik

DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 5432,
    "user": "postgres",
    "password": "example",
    "dbname": "temins"
}

# ==========================
# DB CHECK
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
        print("❌ Database connection FAILED:", e)
        return False

if not check_db_connection():
    sys.exit(1)

# ==========================
# GLOBAL STATE
# ==========================
data_buffer = {}
buffer_lock = threading.Lock()

current_topics = set()
last_json_mtime = 0
mqtt_client = None

# ==========================
# LOAD & PARSE DEVICE JSON
# ==========================
def load_device_topics():
    """
    return:
    {
        dev_id: [param1, param2, ...]
    }
    """
    with open(DEVICE_JSON_PATH, "r") as f:
        config = json.load(f)

    device_map = {}

    for dtype, dtype_data in config["device_type"].items():
        def_topic = dtype_data.get("def_topic", [])

        print(f"🔍 Parsing device type: {dtype}")

        for dev in dtype_data["devices"]:
            dev_id = dev["dev_id"]
            use_default = dev.get("use_default", True)

            if use_default:
                topics = def_topic
                print(f"  📌 {dev_id} → default topics ({len(topics)})")
            else:
                topics = dev.get("topic", [])
                print(f"  📌 {dev_id} → custom topics ({len(topics)})")

            if not topics:
                print(f"  ⚠️ WARNING: {dev_id} has NO topics")

            device_map[dev_id] = topics

    return device_map

# ==========================
# BUILD MQTT TOPICS
# ==========================
def build_mqtt_topics(device_map):
    topics = set()
    for dev_id, params in device_map.items():
        for p in params:
            topics.add(f"temins_iot/{dev_id}/data/{p}")
    return topics

# ==========================
# MQTT CALLBACKS
# ==========================
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        print("✅ MQTT Connected")
        resubscribe_all()
    else:
        print("❌ MQTT Connect failed rc =", rc)

def on_disconnect(client, userdata, rc):
    print("🔌 MQTT Disconnected rc =", rc)

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

        print(f"📥 {device_id} | {parameter} = {value}")

    except Exception as e:
        print("⚠️ Message error:", e)

# ==========================
# DB FLUSH THREAD
# ==========================
def flush_to_db():
    while True:
        time.sleep(FLUSH_INTERVAL)

        with buffer_lock:
            if not data_buffer:
                continue
            rows = list(data_buffer.values())
            data_buffer.clear()

        try:
            conn = psycopg2.connect(**DB_CONFIG)
            cur = conn.cursor()

            sql = """
                INSERT INTO sensor_logs
                (device_unique_id, parameter_name, value, topic)
                VALUES (%s, %s, %s, %s)
            """

            cur.executemany(sql, [
                (r["device_id"], r["parameter"], r["value"], None)
                for r in rows
            ])

            conn.commit()
            cur.close()
            conn.close()

            print(f"💾 DB INSERT OK → {len(rows)} rows")

        except Exception as e:
            print("❌ DB insert error:", e)

# ==========================
# SUBSCRIBE HANDLER
# ==========================
def resubscribe_all():
    global current_topics

    device_map = load_device_topics()
    new_topics = build_mqtt_topics(device_map)

    # Unsubscribe removed topics
    removed = current_topics - new_topics
    for t in removed:
        mqtt_client.unsubscribe(t)
        print(f"➖ UNSUB {t}")

    # Subscribe new topics
    added = new_topics - current_topics
    for t in added:
        mqtt_client.subscribe(t)
        print(f"➕ SUB {t}")

    current_topics = new_topics
    print(f"📡 Active topics: {len(current_topics)}")

# ==========================
# JSON WATCHER THREAD
# ==========================
def watch_json_changes():
    global last_json_mtime

    while True:
        try:
            mtime = os.path.getmtime(DEVICE_JSON_PATH)
            if mtime != last_json_mtime:
                last_json_mtime = mtime
                print("📝 devices.json changed → reload")
                resubscribe_all()
        except Exception as e:
            print("⚠️ JSON watch error:", e)

        time.sleep(JSON_CHECK_INTERVAL)

# ==========================
# MQTT SETUP
# ==========================
mqtt_client = mqtt.Client(
    client_id=f"temins_ws_{int(time.time())}",
    transport="websockets",
    protocol=mqtt.MQTTv311
)

mqtt_client.on_connect = on_connect
mqtt_client.on_disconnect = on_disconnect
mqtt_client.on_message = on_message

mqtt_client.tls_set(cert_reqs=ssl.CERT_NONE)
mqtt_client.tls_insecure_set(True)
mqtt_client.ws_set_options(path="/mqtt")

# ==========================
# START
# ==========================
print("🚀 Starting MQTT Collector")

threading.Thread(target=flush_to_db, daemon=True).start()
threading.Thread(target=watch_json_changes, daemon=True).start()

mqtt_client.connect(BROKER_HOST, BROKER_PORT, keepalive=120)
mqtt_client.loop_forever()
