import json
import ssl
import time
import threading
import psycopg2
import os
import sys
from paho.mqtt import client as mqtt

# ==========================
# CONFIGURATION
# ==========================
BROKER_HOST = "karsacerdasinovatif.web.id"
BROKER_PORT = 8081
FLUSH_INTERVAL = 300  # 5 menit

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_JSON_PATH = os.path.join(BASE_DIR, "../1.json") 
BUFFER_FILE_PATH = os.path.join(BASE_DIR, "../buffer.json")

DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 5432,
    "user": "postgres",
    "password": "example",
    "dbname": "temins"
}

# ==========================
# GLOBAL STATE & LOCKS
# ==========================
file_lock = threading.Lock()
map_lock = threading.Lock()
subscribed_topics = set()
device_map = {}  # Format: {"id_alat": "tipe_alat"}
last_file_mtime = 0

# ==========================
# DATABASE CONNECTION
# ==========================
def check_db_connection():
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT 1")
        cur.close()
        conn.close()
        print("✅ [DB] Database PostgreSQL Connected")
        return True
    except Exception as e:
        print("❌ [DB] Connection FAILED:", e)
        return False

# ==========================
# BUFFER FILE HANDLER
# ==========================
def save_to_buffer_file(device_id, parameter, value):
    """Menyimpan data ke file JSON dan mencetak debug ID & Type"""
    with file_lock:
        data = {}
        if os.path.exists(BUFFER_FILE_PATH):
            try:
                with open(BUFFER_FILE_PATH, "r") as f:
                    data = json.load(f)
            except:
                data = {}

        # Dapatkan Tipe Alat dari Map
        with map_lock:
            device_type = device_map.get(device_id, "Unknown Type")

        key = f"{device_id}|{parameter}"
        data[key] = {
            "device_id": device_id,
            "device_type": device_type,
            "parameter": parameter,
            "value": value,
            "timestamp": time.strftime('%Y-%m-%d %H:%M:%S')
        }

        try:
            with open(BUFFER_FILE_PATH, "w") as f:
                json.dump(data, f, indent=4)
         #   print(f"📥 [BUFFER] Saved: ID:[{device_id}] Type:[{device_type}] -> {parameter}: {value}")
        except Exception as e:
            print(f"❌ [FILE] Gagal tulis buffer: {e}")

def read_and_clear_buffer():
    with file_lock:
        if not os.path.exists(BUFFER_FILE_PATH): return []
        try:
            with open(BUFFER_FILE_PATH, "r") as f:
                data_dict = json.load(f)
            with open(BUFFER_FILE_PATH, "w") as f:
                json.dump({}, f)
            return list(data_dict.values())
        except:
            return []

# ==========================
# CONFIG LOADER (TOPICS & MAPPING)
# ==========================
def update_config_from_json():
    """Membaca topik sekaligus membuat pemetaan ID ke Tipe Alat"""
    new_topics = set()
    new_map = {}
    try:
        if not os.path.exists(CONFIG_JSON_PATH):
            return new_topics, new_map

        with open(CONFIG_JSON_PATH, "r") as f:
            data = json.load(f)
        
        device_types = data.get("device_type", {})
        for type_name, type_data in device_types.items():
            default_topics = type_data.get("def_topic", [])
            devices = type_data.get("devices", [])
            
            for dev in devices:
                dev_id = dev.get("dev_id")
                new_map[dev_id] = type_name  # Simpan ID -> Tipe
                
                use_default = dev.get("use_default", False)
                specific_topics = dev.get("topic", [])
                params = set()
                if use_default: params.update(default_topics)
                if specific_topics: params.update(specific_topics)
                
                for param in params:
                    new_topics.add(f"temins_iot/{dev_id}/data/{param}")
    except Exception as e:
        print(f"❌ [CONFIG] Error: {e}")
    
    return new_topics, new_map

# ==========================
# FILE WATCHER
# ==========================
def config_watcher(client):
    global last_file_mtime, subscribed_topics, device_map
    while True:
        try:
            if os.path.exists(CONFIG_JSON_PATH):
                current_mtime = os.stat(CONFIG_JSON_PATH).st_mtime
                if current_mtime != last_file_mtime:
                    last_file_mtime = current_mtime
                    
                    new_topics, new_map = update_config_from_json()
                    
                    with map_lock:
                        device_map = new_map
                    
                    to_sub = new_topics - subscribed_topics
                    to_unsub = subscribed_topics - new_topics
                    for t in to_sub: client.subscribe(t)
                    for t in to_unsub: client.unsubscribe(t)
                    
                    subscribed_topics = new_topics
                    print(f"🔄 [WATCHER] Config Updated. Monitoring {len(device_map)} devices.")
        except Exception as e:
            print(f"❌ [WATCHER] Error: {e}")
        time.sleep(10)

# ==========================
# MQTT HANDLERS
# ==========================
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        print("✅ [MQTT] Connected to Broker")
        client.subscribe("temins_iot/#")
    else:
        print(f"❌ [MQTT] Connection failed, rc={rc}")

def on_message(client, userdata, msg):
    raw_payload = msg.payload.decode()
    topic = msg.topic
    try:
        parts = topic.split("/")
        if len(parts) < 4 or parts[2] != 'data': return

        device_id = parts[1]
        parameter = parts[-1] 

        # Parsing Value
        try:
            data_json = json.loads(raw_payload)
            value = float(data_json.get("value", 0)) if isinstance(data_json, dict) else float(data_json)
        except:
            try:
                value = float(raw_payload.split(' - ')[0])
            except:
                return

        save_to_buffer_file(device_id, parameter, value)

    except Exception as e:
        print(f"❌ [ERROR] Msg failed: {e}")

# ==========================
# FLUSH TO DB THREAD
# ==========================
def flush_to_db():
    print(f"⏱️ [DB] Flush Thread Active (Every {FLUSH_INTERVAL}s)")
    while True:
        time.sleep(FLUSH_INTERVAL) 
        data_to_save = read_and_clear_buffer()
        
        if not data_to_save:
            continue

        try:
            conn = psycopg2.connect(**DB_CONFIG)
            cur = conn.cursor()
            sql = "INSERT INTO sensor_logs (device_unique_id, parameter_name, value) VALUES (%s, %s, %s)"
            
            rows = [(d["device_id"], d["parameter"], d["value"]) for d in data_to_save]
            cur.executemany(sql, rows)
            conn.commit()
            
            # DEBUG SIMPAN DB
            print("\n--- 💾 DATABASE PERSISTENCE REPORT ---")
            for d in data_to_save:
                print(f"✅ STORED: ID:[{d['device_id']}] Type:[{d['device_type']}] Param:[{d['parameter']}]")
            print(f"Total: {len(rows)} records successfully moved to PostgreSQL.\n")
            
            cur.close()
            conn.close()
        except Exception as e:
            print(f"❌ [DB] Flush Failed: {e}")

# ==========================
# MAIN EXECUTION
# ==========================
if __name__ == "__main__":
    if not check_db_connection():
        sys.exit(1)

    client = mqtt.Client(
        client_id=f"temins_logger_final_{int(time.time())}",
        transport="websockets",
    )
    client.on_connect = on_connect
    client.on_message = on_message
    client.tls_set() 
    client.ws_set_options(path="/mqtt")

    threading.Thread(target=flush_to_db, daemon=True).start()
    threading.Thread(target=config_watcher, args=(client,), daemon=True).start()

    print(f"🔌 Connecting to {BROKER_HOST}...")
    try:
        client.connect(BROKER_HOST, BROKER_PORT, keepalive=60)
        client.loop_forever()
    except KeyboardInterrupt:
        print("\n⛔ Program dihentikan.")