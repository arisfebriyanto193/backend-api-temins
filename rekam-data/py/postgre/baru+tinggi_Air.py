import json
import ssl
import time
import threading
import psycopg2
import mysql.connector
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
BUFFER_FILE_PATH = os.path.join(BASE_DIR, "../buf2.json")

# PostgreSQL Config
DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 5432,
    "user": "postgres",
    "password": "example",
    "dbname": "temins"
}

# MySQL Config - GANTI HOST SESUAI SERVER ANDA
MYSQL_CONFIG = {
    "host": "127.0.0.1",  # Ganti dengan IP server MySQL Anda jika remote
    "port": 3306,  # Port MySQL default
    "user": "temins",
    "password": "YFBmEzBBtty6hBC7",
    "database": "temins",
    "connect_timeout": 10
}

# ==========================
# GLOBAL STATE & LOCKS
# ==========================
file_lock = threading.Lock()
map_lock = threading.Lock()
subscribed_topics = set()
device_map = {}  # Format: {"id_alat": "tipe_alat"}
sensor_height_cache = {}  # Cache untuk tinggi_sensor dari MySQL
cache_lock = threading.Lock()
last_file_mtime = 0

# ==========================
# DATABASE CONNECTION
# ==========================
def check_db_connection():
    """Check PostgreSQL connection"""
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT 1")
        cur.close()
        conn.close()
        print("✅ [PostgreSQL] Database Connected")
        return True
    except Exception as e:
        print(f"❌ [PostgreSQL] Connection FAILED: {e}")
        return False

def check_mysql_connection():
    """Check MySQL connection dengan detail error"""
    try:
        print(f"🔍 [MySQL] Attempting connection to {MYSQL_CONFIG['host']}:{MYSQL_CONFIG.get('port', 3306)}...")
        print(f"    User: {MYSQL_CONFIG['user']}")
        print(f"    Database: {MYSQL_CONFIG['database']}")
        
        conn = mysql.connector.connect(**MYSQL_CONFIG)
        cur = conn.cursor()
        cur.execute("SELECT 1")
        result = cur.fetchone()
        cur.close()
        conn.close()
        
        print("✅ [MySQL] Database Connected Successfully")
        return True
    except mysql.connector.Error as err:
        if err.errno == 2003:
            print(f"❌ [MySQL] Cannot connect to server: {MYSQL_CONFIG['host']}")
            print(f"   Pastikan MySQL server berjalan dan host sudah benar!")
        elif err.errno == 1045:
            print(f"❌ [MySQL] Access denied for user '{MYSQL_CONFIG['user']}'")
            print(f"   Periksa username dan password!")
        elif err.errno == 1049:
            print(f"❌ [MySQL] Database '{MYSQL_CONFIG['database']}' tidak ditemukan!")
        else:
            print(f"❌ [MySQL] Error {err.errno}: {err.msg}")
        return False
    except Exception as e:
        print(f"❌ [MySQL] Connection FAILED: {e}")
        return False

def get_sensor_height_from_mysql(device_id):
    """Mengambil tinggi_sensor dari MySQL untuk device tertentu"""
    with cache_lock:
        # Cek cache dulu
        if device_id in sensor_height_cache:
           # print(f"🔍 [CACHE] Using cached sensor height for device {device_id}: {sensor_height_cache[device_id]} cm")
            return sensor_height_cache[device_id]
    
    try:
        conn = mysql.connector.connect(**MYSQL_CONFIG)
        cur = conn.cursor()
        
        query = "SELECT tinggi_sensor FROM device_settings WHERE device_unique_id = %s LIMIT 1"
        cur.execute(query, (device_id,))
        result = cur.fetchone()
        
        cur.close()
        conn.close()
        
        if result:
            tinggi_sensor = float(result[0])
            print(f"📊 [MySQL] Retrieved sensor height for device {device_id}: {tinggi_sensor} cm")
            
            # Simpan ke cache
            with cache_lock:
                sensor_height_cache[device_id] = tinggi_sensor
            
            return tinggi_sensor
        else:
            print(f"⚠️ [MySQL] No sensor height found for device {device_id}")
            return None
            
    except Exception as e:
        print(f"❌ [MySQL] Error getting sensor height for device {device_id}: {e}")
        return None

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
        
        # Jika device type adalah AWLR dan parameter adalah 'tuc', hitung tinggi air
        if device_type.lower() == "awlr" and parameter == "tuc":
            tinggi_sensor = get_sensor_height_from_mysql(device_id)
            
            if tinggi_sensor is not None:
                # Hitung tinggi air: tinggi_sensor - nilai_tuc
                tinggi_air = tinggi_sensor - value
                
              #print(f"🌊 [AWLR CALC] Device:{device_id} | TUC:{value} cm | Sensor Height:{tinggi_sensor} cm | Water Height:{tinggi_air} cm")
                
                # Simpan data tinggi air sebagai entry terpisah
                air_key = f"{device_id}|result_tinggi_air"
                data[air_key] = {
                    "device_id": device_id,
                    "device_type": device_type,
                    "parameter": "result_tinggi_air",
                    "value": tinggi_air,
                    "timestamp": time.strftime('%Y-%m-%d %H:%M:%S')
                }
        
        try:
            with open(BUFFER_FILE_PATH, "w") as f:
                json.dump(data, f, indent=4)
        #    print(f"📥 [BUFFER] Saved: ID:[{device_id}] Type:[{device_type}] -> {parameter}: {value}")
        except Exception as e:
            print(f"❌ [FILE] Gagal tulis buffer: {e}")

def read_and_clear_buffer():
    """Membaca dan mengosongkan buffer"""
    with file_lock:
        if not os.path.exists(BUFFER_FILE_PATH): 
            return []
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
            print(f"⚠️ [CONFIG] File not found: {CONFIG_JSON_PATH}")
            return new_topics, new_map
        
        with open(CONFIG_JSON_PATH, "r") as f:
            data = json.load(f)
        
        device_types = data.get("device_type", {})
        
        for type_name, type_data in device_types.items():
            default_topics = type_data.get("def_topic", [])
            devices = type_data.get("devices", [])
            
            print(f"🔧 [CONFIG] Loading device type: {type_name} with {len(devices)} devices")
            
            for dev in devices:
                dev_id = dev.get("dev_id")
                new_map[dev_id] = type_name  # Simpan ID -> Tipe
                
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
                
                print(f"   📱 Device ID: {dev_id} | Type: {type_name} | Topics: {len(params)}")
                
    except Exception as e:
        print(f"❌ [CONFIG] Error: {e}")
    
    return new_topics, new_map

# ==========================
# FILE WATCHER
# ==========================
def config_watcher(client):
    """Monitor perubahan file konfigurasi"""
    global last_file_mtime, subscribed_topics, device_map
    
    while True:
        try:
            if os.path.exists(CONFIG_JSON_PATH):
                current_mtime = os.stat(CONFIG_JSON_PATH).st_mtime
                
                if current_mtime != last_file_mtime:
                    last_file_mtime = current_mtime
                    
                    print("\n🔄 [WATCHER] Config file changed, reloading...")
                    
                    new_topics, new_map = update_config_from_json()
                    
                    with map_lock:
                        device_map = new_map
                    
                    # Subscribe to new topics
                    to_sub = new_topics - subscribed_topics
                    to_unsub = subscribed_topics - new_topics
                    
                    for t in to_sub: 
                        client.subscribe(t)
                        print(f"   ➕ Subscribed: {t}")
                    
                    for t in to_unsub: 
                        client.unsubscribe(t)
                        print(f"   ➖ Unsubscribed: {t}")
                    
                    subscribed_topics = new_topics
                    
                    # Clear cache ketika config berubah
                    with cache_lock:
                        sensor_height_cache.clear()
                    
                    print(f"✅ [WATCHER] Config Updated. Monitoring {len(device_map)} devices with {len(subscribed_topics)} topics.\n")
                    
        except Exception as e:
            print(f"❌ [WATCHER] Error: {e}")
        
        time.sleep(10)

# ==========================
# MQTT HANDLERS
# ==========================
def on_connect(client, userdata, flags, rc):
    """Callback ketika terhubung ke MQTT broker"""
    if rc == 0:
        print("✅ [MQTT] Connected to Broker")
        client.subscribe("temins_iot/#")
        print("📡 [MQTT] Subscribed to temins_iot/#")
    else:
        print(f"❌ [MQTT] Connection failed, rc={rc}")

def on_message(client, userdata, msg):
    """Callback ketika menerima pesan MQTT"""
    raw_payload = msg.payload.decode()
    topic = msg.topic
    
    try:
        parts = topic.split("/")
        
        # Format: temins_iot/{device_id}/data/{parameter}
        if len(parts) < 4 or parts[2] != 'data': 
            return
        
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
                print(f"⚠️ [MQTT] Cannot parse value from: {raw_payload}")
                return
        
        #print(f"📨 [MQTT] Received: {topic} = {value}")
        save_to_buffer_file(device_id, parameter, value)
        
    except Exception as e:
        print(f"❌ [ERROR] Message processing failed: {e}")

# ==========================
# FLUSH TO DB THREAD
# ==========================
def flush_to_db():
    """Thread untuk flush data dari buffer ke PostgreSQL"""
    print(f"⏱️ [DB] Flush Thread Active (Every {FLUSH_INTERVAL}s)")
    
    while True:
        time.sleep(FLUSH_INTERVAL) 
        data_to_save = read_and_clear_buffer()
        
        if not data_to_save:
            print("ℹ️ [DB] No data to flush")
            continue
        
        try:
            conn = psycopg2.connect(**DB_CONFIG)
            cur = conn.cursor()
            
            sql = "INSERT INTO sensor_logs (device_unique_id, parameter_name, value) VALUES (%s, %s, %s)"
            
            rows = [(d["device_id"], d["parameter"], d["value"]) for d in data_to_save]
            cur.executemany(sql, rows)
            conn.commit()
            
            # DEBUG SIMPAN DB
            print("\n" + "="*60)
            print("💾 DATABASE PERSISTENCE REPORT")
            print("="*60)
            
            for d in data_to_save:
                status_icon = "🌊" if d["parameter"] == "result_tinggi_air" else "✅"
                print(f"{status_icon} STORED: ID:[{d['device_id']}] Type:[{d['device_type']}] Param:[{d['parameter']}] Value:[{d['value']}]")
            
            print(f"\n📊 Total: {len(rows)} records successfully saved to PostgreSQL.")
            print("="*60 + "\n")
            
            cur.close()
            conn.close()
            
        except Exception as e:
            print(f"❌ [DB] Flush Failed: {e}")

# ==========================
# CACHE REFRESH THREAD
# ==========================
def refresh_sensor_height_cache():
    """Thread untuk refresh cache tinggi sensor secara berkala"""
    print("🔄 [CACHE] Sensor height cache refresh thread started (Every 30 minutes)")
    
    while True:
        time.sleep(1800)  # 30 menit
        
        print("\n🔄 [CACHE] Refreshing sensor height cache...")
        
        with map_lock:
            awlr_devices = [dev_id for dev_id, dev_type in device_map.items() if dev_type.lower() == "awlr"]
        
        for device_id in awlr_devices:
            get_sensor_height_from_mysql(device_id)
        
        print(f"✅ [CACHE] Cache refreshed for {len(awlr_devices)} AWLR devices\n")

# ==========================
# MAIN EXECUTION
# ==========================
if __name__ == "__main__":
    print("\n" + "="*60)
    print("🚀 TEMINS IoT Logger with AWLR Water Height Calculation")
    print("="*60 + "\n")
    
    # Check database connections
    if not check_db_connection():
        print("❌ Cannot proceed without PostgreSQL connection")
        sys.exit(1)
    
    if not check_mysql_connection():
        print("❌ Cannot proceed without MySQL connection")
        sys.exit(1)
    
    print()
    
    # Create MQTT client
    client = mqtt.Client(
        client_id=f"temins_logger_final_{int(time.time())}",
        transport="websockets",
    )
    
    client.on_connect = on_connect
    client.on_message = on_message
    
    # Setup TLS and WebSocket
    client.tls_set() 
    client.ws_set_options(path="/mqtt")
    
    # Start background threads
    threading.Thread(target=flush_to_db, daemon=True).start()
    threading.Thread(target=config_watcher, args=(client,), daemon=True).start()
    threading.Thread(target=refresh_sensor_height_cache, daemon=True).start()
    
    # Connect to MQTT broker
    print(f"🔌 Connecting to {BROKER_HOST}:{BROKER_PORT}...")
    
    try:
        client.connect(BROKER_HOST, BROKER_PORT, keepalive=60)
        print("✅ Connection established, starting loop...\n")
        client.loop_forever()
    except KeyboardInterrupt:
        print("\n⛔ Program stopped by user.")
    except Exception as e:
        print(f"\n❌ Error: {e}")
