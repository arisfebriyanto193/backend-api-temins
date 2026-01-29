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

# --- PERBAIKAN PATH FILE ---
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
JSON_FILE_PATH = os.path.join(BASE_DIR, "../1.json") 

DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 5432,
    "user": "postgres",
    "password": "example",
    "dbname": "temins"
}

# ==========================
# GLOBAL STATE
# ==========================
data_buffer = {}
buffer_lock = threading.Lock()
subscribed_topics = set()
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
        print("✅ [DB] Database PostgreSQL connected")
        return True
    except Exception as e:
        print("❌ [DB] Connection FAILED:", e)
        return False

if not check_db_connection():
    print("⛔ Program dihentikan karena DB tidak tersedia")
    sys.exit(1)

# ==========================
# JSON CONFIG LOADER
# ==========================
def get_topics_from_json():
    new_topics = set()
    try:
        print(f"📂 [CONFIG] Membaca file di: {JSON_FILE_PATH}")
        if not os.path.exists(JSON_FILE_PATH):
            print(f"⚠️ [CONFIG] File TIDAK DITEMUKAN di path tersebut!")
            return new_topics

        with open(JSON_FILE_PATH, "r") as f:
            data = json.load(f)
        
        device_types = data.get("device_type", {})
        
        for type_name, type_data in device_types.items():
            default_topics = type_data.get("def_topic", [])
            devices = type_data.get("devices", [])
            
            for dev in devices:
                dev_id = dev.get("dev_id")
                use_default = dev.get("use_default", False)
                specific_topics = dev.get("topic", [])
                
                params = set()
                if use_default:
                    params.update(default_topics)
                if specific_topics:
                    params.update(specific_topics)
                
                for param in params:
                    topic = f"temins_iot/{dev_id}/data/{param}"
                    new_topics.add(topic)

    except Exception as e:
        print(f"❌ [CONFIG] Error membaca file: {e}")
        
    return new_topics

# ==========================
# FILE WATCHER
# ==========================
def config_watcher(client):
    global last_file_mtime, subscribed_topics
    print("👀 [WATCHER] Memulai pemantauan file JSON...")
    
    while True:
        try:
            if os.path.exists(JSON_FILE_PATH):
                current_mtime = os.stat(JSON_FILE_PATH).st_mtime
                
                if current_mtime != last_file_mtime:
                    print("\n🔄 [WATCHER] Config berubah/dimuat! Reloading...")
                    last_file_mtime = current_mtime
                    
                    new_topics_set = get_topics_from_json()
                    
                    if not new_topics_set:
                        print("⚠️ [WATCHER] Config kosong atau tidak valid.")
                    
                    to_subscribe = new_topics_set - subscribed_topics
                    to_unsubscribe = subscribed_topics - new_topics_set
                    
                    if to_subscribe:
                        print(f"➕ [MQTT] Subscribing {len(to_subscribe)} topik baru:")
                        for t in to_subscribe:
                            client.subscribe(t)
                            # print(f"   👉 [SUB JSON] {t}") 

                    if to_unsubscribe:
                        print(f"➖ [MQTT] Unsubscribing {len(to_unsubscribe)} topik lama:")
                        for t in to_unsubscribe:
                            client.unsubscribe(t)
                            # print(f"   👋 [UNSUB] {t}")
                    
                    subscribed_topics = new_topics_set
                    print(f"\n✅ [STATUS] Total {len(subscribed_topics)} Topic JSON Aktif.")
            else:
                print(f"⚠️ [WATCHER] Menunggu file... Path: {JSON_FILE_PATH}")
                
        except Exception as e:
            print(f"❌ [WATCHER] Error: {e}")
            
        time.sleep(10)

# ==========================
# MQTT HANDLERS (UPDATED)
# ==========================
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        print("✅ [MQTT] Terhubung ke Broker!")
        print("⚠️ [DEBUG] Memaksa Subscribe ke 'temins_iot/#' (Wildcard)...")
        client.subscribe("temins_iot/#")
        global last_file_mtime
        last_file_mtime = 0 
    else:
        print(f"❌ [MQTT] Gagal connect, rc={rc}")

def on_message(client, userdata, msg):
    raw_payload = msg.payload.decode()
    topic = msg.topic

    # Uncomment baris dibawah jika ingin melihat semua data mentah yg masuk
    # print(f"🔔 [RAW] {topic} -> {raw_payload}")

    try:
        parts = topic.split("/")
        
        # Filter Format Topic
        if len(parts) < 4:
            # print(f"⚠️ [SKIP] Format topik pendek: {topic}")
            return 

        # Pastikan ini topic data
        if parts[2] != 'data':
             return

        device_id = parts[1]
        parameter = parts[-1] 

        # Parsing Payload
        value = 0.0
        try:
            # 1. Coba Parsing JSON {"value": 123}
            data = json.loads(raw_payload)
            if isinstance(data, dict):
                value = float(data.get("value", 0))
            else:
                value = float(data) 
        except:
            # 2. Coba Parsing Angka Langsung "123.45"
            try:
                value = float(raw_payload)
            except:
                # 3. Coba Parsing Format "ANGKA - TANGGAL"
                try:
                    clean_value = raw_payload.split(' - ')[0] 
                    value = float(clean_value)
                    
                    # --- MODIFIKASI DISINI ---
                    print(f"🔧 [FIX] Topic: {topic}")
                    print(f"   -> Asli: '{raw_payload}' | Jadi: {value}")
                    
                except:
                    # JIKA MASIH GAGAL, PRINT TOPIC NYA DISINI:
                    print(f"⚠️ [SKIP] Gagal convert di Topic: {topic}") 
                    print(f"          Payload: '{raw_payload}'") 
                    return

        # Simpan ke Buffer
        with buffer_lock:
            data_buffer[(device_id, parameter)] = {
                "device_id": device_id,
                "parameter": parameter,
                "value": value
            }

    except Exception as e:
        print(f"❌ [ERROR] Processing message failed: {e}")

# ==========================
# FLUSH TO DB THREAD
# ==========================
def flush_to_db():
    print(f"⏱️ [DB] Thread Flush dimulai (Interval {FLUSH_INTERVAL} detik)")
    while True:
        time.sleep(FLUSH_INTERVAL) 

        data_to_save = []
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
                (d["device_id"], d["parameter"], d["value"], None) 
                for d in data_to_save
            ]

            cur.executemany(sql, rows)
            conn.commit()
            
            cur.close()
            conn.close()
            print(f"💾 [DB] SUKSES! {len(rows)} data tersimpan ke Database.")

        except Exception as e:
            print(f"❌ [DB] Gagal insert database: {e}")

# ==========================
# MAIN EXECUTION
# ==========================
if __name__ == "__main__":
    client = mqtt.Client(
        client_id=f"temins_logger_FINAL_{int(time.time())}",
        transport="websockets",
        protocol=mqtt.MQTTv311
    )
    
    client.on_connect = on_connect
    client.on_message = on_message
    
    client.tls_set() 
    client.ws_set_options(path="/mqtt")

    t_flush = threading.Thread(target=flush_to_db, daemon=True)
    t_flush.start()

    t_watch = threading.Thread(target=config_watcher, args=(client,), daemon=True)
    t_watch.start()

    print(f"🔌 Connecting to {BROKER_HOST}:{BROKER_PORT} (WS)...")
    try:
        client.connect(BROKER_HOST, BROKER_PORT, keepalive=60)
        client.loop_forever()
    except KeyboardInterrupt:
        print("\n⛔ Program dihentikan.")
    except Exception as e:
        print(f"❌ Critical Error: {e}")