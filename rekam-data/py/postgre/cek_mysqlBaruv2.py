import json
import ssl
import time
import threading
import psycopg2
import mysql.connector
import os
import sys
from datetime import datetime, timezone, timedelta
from paho.mqtt import client as mqtt

# ==========================
# CONFIGURATION
# ==========================
BROKER_HOST = "karsacerdasinovatif.web.id"
BROKER_PORT = 8081
FLUSH_INTERVAL = 300  # 5 menit
CACHE_REFRESH_INTERVAL = 30  # 30 detik untuk cek MySQL

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_JSON_PATH = os.path.join(BASE_DIR, "../1.json") 
BUFFER_FILE_PATH = os.path.join(BASE_DIR, "../buf2.json")

# Zona Waktu WIB (UTC+7)
WIB_TIMEZONE = timezone(timedelta(hours=7))

# PostgreSQL Config
DB_CONFIG = {
    "host": "127.0.0.1",
    "port": 5432,
    "user": "postgres",
    "password": "example",
    "dbname": "temins"
}

# MySQL Config
MYSQL_CONFIG = {
    "host": "127.0.0.1",
    "port": 3306,
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
# UTILITY FUNCTIONS
# ==========================
def get_wib_timestamp():
    """Mendapatkan timestamp dalam zona waktu WIB (UTC+7)"""
    return datetime.now(WIB_TIMEZONE)

def format_wib_timestamp(dt):
    """Format datetime ke string dengan format database"""
    return dt.strftime('%Y-%m-%d %H:%M:%S')

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

def get_all_sensor_heights_from_mysql():
    """
    Mengambil SEMUA data tinggi_sensor dari MySQL dan mendeteksi perubahan
    Returns: dict {device_id: tinggi_sensor}
    """
    try:
        conn = mysql.connector.connect(**MYSQL_CONFIG)
        cur = conn.cursor()
        
        # Ambil semua device dan tinggi_sensor yang ada
        query = "SELECT device_unique_id, tinggi_sensor FROM device_settings"
        cur.execute(query)
        results = cur.fetchall()
        
        cur.close()
        conn.close()
        
        # Convert ke dictionary dengan validasi ketat
        mysql_data = {}
        skipped_devices = []
        
        for row in results:
            device_id = row[0]
            tinggi_sensor_raw = row[1]
            
            # Skip jika device_id kosong
            if not device_id or device_id.strip() == '':
                continue
            
            # Validasi dan konversi tinggi_sensor
            try:
                # Cek apakah None atau string kosong
                if tinggi_sensor_raw is None or str(tinggi_sensor_raw).strip() == '':
                    skipped_devices.append((device_id, "empty/null"))
                    continue
                
                # Konversi ke float
                tinggi_sensor = float(tinggi_sensor_raw)
                
                # Validasi nilai masuk akal (0-1000 cm misalnya)
                if tinggi_sensor <= 0 or tinggi_sensor > 1000:
                    skipped_devices.append((device_id, f"invalid value: {tinggi_sensor}"))
                    continue
                
                # Simpan ke cache jika valid
                mysql_data[device_id] = tinggi_sensor
                
            except (ValueError, TypeError) as e:
                skipped_devices.append((device_id, f"conversion error: {tinggi_sensor_raw}"))
                continue
        
        # Log device yang di-skip (hanya jika ada)
        if skipped_devices:
            print(f"\n⚠️ [MySQL] Skipped {len(skipped_devices)} devices with invalid tinggi_sensor:")
            for dev_id, reason in skipped_devices[:5]:  # Tampilkan max 5
                print(f"   ⊗ Device: {dev_id} | Reason: {reason}")
            if len(skipped_devices) > 5:
                print(f"   ... and {len(skipped_devices) - 5} more")
        
        return mysql_data
            
    except Exception as e:
        print(f"❌ [MySQL] Error getting all sensor heights: {e}")
        return {}

def get_sensor_height_from_cache(device_id):
    """Mengambil tinggi_sensor dari cache"""
    with cache_lock:
        return sensor_height_cache.get(device_id)

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
            tinggi_sensor = get_sensor_height_from_cache(device_id)
            
            if tinggi_sensor is not None:
                # Hitung tinggi air: tinggi_sensor - nilai_tuc
                tinggi_air = tinggi_sensor - value
                
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
        
        save_to_buffer_file(device_id, parameter, value)
        
    except Exception as e:
        print(f"❌ [ERROR] Message processing failed: {e}")

# ==========================
# FLUSH TO DB THREAD
# ==========================
def flush_to_db():
    """Thread untuk flush data dari buffer ke PostgreSQL dengan timestamp WIB yang sama"""
    print(f"⏱️ [DB] Flush Thread Active (Every {FLUSH_INTERVAL}s)")
    
    while True:
        time.sleep(FLUSH_INTERVAL) 
        data_to_save = read_and_clear_buffer()
        
        if not data_to_save:
            print("ℹ️ [DB] No data to flush")
            continue
        
        try:
            # Dapatkan SATU timestamp WIB untuk SEMUA data dalam batch ini
            batch_timestamp_wib = get_wib_timestamp()
            batch_timestamp_str = format_wib_timestamp(batch_timestamp_wib)
            
            conn = psycopg2.connect(**DB_CONFIG)
            cur = conn.cursor()
            
            # SQL dengan kolom recorded_at
            sql = """
                INSERT INTO sensor_logs 
                (device_unique_id, parameter_name, value, recorded_at) 
                VALUES (%s, %s, %s, %s)
            """
            
            # Siapkan rows dengan timestamp yang SAMA untuk semua data
            rows = [
                (
                    d["device_id"], 
                    d["parameter"], 
                    d["value"],
                    batch_timestamp_str  # Timestamp WIB yang sama untuk semua
                ) 
                for d in data_to_save
            ]
            
            cur.executemany(sql, rows)
            conn.commit()
            
            # DEBUG SIMPAN DB
            print("\n" + "="*70)
            print("💾 DATABASE PERSISTENCE REPORT (WIB TIMEZONE)")
            print("="*70)
            print(f"🕐 Batch Timestamp (WIB): {batch_timestamp_str}")
            print("-"*70)
            
            for d in data_to_save:
                status_icon = "🌊" if d["parameter"] == "result_tinggi_air" else "✅"
                print(f"{status_icon} STORED: ID:[{d['device_id']}] Type:[{d['device_type']}] Param:[{d['parameter']}] Value:[{d['value']}]")
            
            print("-"*70)
            print(f"📊 Total: {len(rows)} records successfully saved to PostgreSQL.")
            print(f"⏰ All records saved with identical timestamp: {batch_timestamp_str} WIB")
            print("="*70 + "\n")
            
            cur.close()
            conn.close()
            
        except Exception as e:
            print(f"❌ [DB] Flush Failed: {e}")

# ==========================
# AUTO-REFRESH CACHE THREAD (SETIAP 30 DETIK)
# ==========================
def auto_refresh_sensor_cache():
    """
    Thread untuk auto-refresh cache tinggi sensor setiap 30 detik
    Mendeteksi:
    1. Device baru yang ditambahkan di MySQL
    2. Perubahan nilai tinggi_sensor
    """
    print(f"🔄 [AUTO-REFRESH] Cache refresh thread started (Every {CACHE_REFRESH_INTERVAL} seconds)")
    
    iteration = 0
    
    while True:
        time.sleep(CACHE_REFRESH_INTERVAL)
        iteration += 1
        
        try:
            # Ambil data terbaru dari MySQL
            mysql_data = get_all_sensor_heights_from_mysql()
            
            if not mysql_data:
                print(f"⚠️ [AUTO-REFRESH #{iteration}] No data from MySQL")
                continue
            
            # Deteksi perubahan
            changes_detected = False
            new_devices = []
            updated_devices = []
            
            with cache_lock:
                # Cek device baru
                for device_id in mysql_data:
                    if device_id not in sensor_height_cache:
                        new_devices.append(device_id)
                        changes_detected = True
                
                # Cek perubahan nilai
                for device_id, new_value in mysql_data.items():
                    old_value = sensor_height_cache.get(device_id)
                    if old_value is not None and old_value != new_value:
                        updated_devices.append((device_id, old_value, new_value))
                        changes_detected = True
                
                # Update cache
                sensor_height_cache.update(mysql_data)
            
            # Laporan hasil
            if changes_detected:
                wib_time = get_wib_timestamp()
                print(f"\n{'='*70}")
                print(f"🔄 AUTO-REFRESH REPORT #{iteration} - {format_wib_timestamp(wib_time)} WIB")
                print(f"{'='*70}")
                
                if new_devices:
                    print(f"🆕 NEW DEVICES DETECTED: {len(new_devices)}")
                    for dev_id in new_devices:
                        print(f"   ➕ Device: {dev_id} | Sensor Height: {mysql_data[dev_id]} cm")
                
                if updated_devices:
                    print(f"📝 UPDATED SENSOR HEIGHTS: {len(updated_devices)}")
                    for dev_id, old_val, new_val in updated_devices:
                        print(f"   🔄 Device: {dev_id} | Old: {old_val} cm → New: {new_val} cm")
                
                print(f"✅ Cache updated with {len(mysql_data)} total devices")
                print(f"{'='*70}\n")
            else:
                # Log silent check
                print(f"✓ [AUTO-REFRESH #{iteration}] No changes detected ({len(mysql_data)} devices monitored)")
                
        except Exception as e:
            print(f"❌ [AUTO-REFRESH #{iteration}] Error: {e}")

# ==========================
# MAIN EXECUTION
# ==========================
if __name__ == "__main__":
    print("\n" + "="*70)
    print("🚀 TEMINS IoT Logger with WIB Timestamp & Auto-Refresh MySQL Cache")
    print("="*70)
    print(f"🕐 Current Time (WIB): {format_wib_timestamp(get_wib_timestamp())} WIB")
    print("="*70 + "\n")
    
    # Check database connections
    if not check_db_connection():
        print("❌ Cannot proceed without PostgreSQL connection")
        sys.exit(1)
    
    if not check_mysql_connection():
        print("❌ Cannot proceed without MySQL connection")
        sys.exit(1)
    
    print()
    
    # Initial cache load
    print("🔄 [INIT] Loading initial sensor height cache from MySQL...")
    initial_data = get_all_sensor_heights_from_mysql()
    with cache_lock:
        sensor_height_cache.update(initial_data)
    print(f"✅ [INIT] Loaded {len(initial_data)} devices into cache\n")
    
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
    threading.Thread(target=auto_refresh_sensor_cache, daemon=True).start()
    
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