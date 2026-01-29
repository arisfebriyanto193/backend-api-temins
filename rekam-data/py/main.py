import json
import ssl
import os
import time
import mysql.connector
from paho.mqtt import client as mqtt

# ==========================
# Load device list from JSON
# ==========================
def load_devices():
    with open("devices.json", "r") as f:
        data = json.load(f)
    return data["devices"]

# ================================================================
# Generate topics (22 parameter)
# ================================================================
def generate_topics(device_id):
    params = [
        "su", "ku", "tu", "su2", "tu2", "ku2",
        "su3", "ku3", "tt", "ka", "aa",
        "rm", "ch", "kam", "cha", "chr",
        "tsp", "tb", "ac", "da", "sm", "sur"
    ]
    return [f"temins_iot/{device_id}/data/{p}" for p in params]

# ==========================
# MySQL Connection
# ==========================
db = mysql.connector.connect(
    host="localhost",
    user="temins",
    password="YFBmEzBBtty6hBC7",
    database="temins"
)
cursor = db.cursor()

# ============================================
# Tempat menyimpan data terbaru setiap topik
# ============================================
# Contoh:
# last_data = {
#   "0021:su": 32.3,
#   "0021:ku": 55.3,
# }
last_data = {}

# ==========================
# MQTT Callback
# ==========================
def on_message(client, userdata, msg):
    global last_data

    topic = msg.topic

    try:
        value = float(msg.payload.decode())
    except:
        print("Payload bukan angka:", msg.payload.decode())
        return

    parts = topic.split("/")
    if len(parts) < 4:
        print("Topic tidak valid:", topic)
        return

    device_id = parts[1]
    parameter_name = parts[3]

    key = f"{device_id}:{parameter_name}"
    last_data[key] = value  # simpan nilai terbaru

    print(f"Update: {device_id} | {parameter_name} = {value}")

# ==========================
# Insert data setiap 5 menit
# ==========================
def save_data_to_mysql():
    global last_data

    if not last_data:
        print("Tidak ada data dalam memory untuk disimpan.")
        return

    print("\n📝 Menyimpan data 5 menit terakhir...")

    sql = """
        INSERT INTO sensor_logs (device_unique_id, parameter_name, value)
        VALUES (%s, %s, %s)
    """

    count = 0

    # Simpan SEMUA data yang ada di memory
    for key, value in last_data.items():
        device_id, parameter = key.split(":")
        cursor.execute(sql, (device_id, parameter, value))
        count += 1

        print(f"Simpan → {device_id} | {parameter} = {value}")

    db.commit()

    print(f"✔ Selesai simpan. Total {count} data.\n")
    # ❗ DATA TIDAK DIHAPUS
    # last_data tetap digunakan di 5 menit berikutnya

# ====================================================
# MQTT Setup - WSS
# ====================================================
client = mqtt.Client(transport="websockets")
client.on_message = on_message

client.tls_set(
    cert_reqs=ssl.CERT_NONE,
    tls_version=ssl.PROTOCOL_TLSv1_2
)
client.tls_insecure_set(True)

broker_host = "karsacerdasinovatif.web.id"
broker_port = 8081
client.connect(broker_host, broker_port, keepalive=60)

# ====================================================
# Auto Reload JSON when modified
# ====================================================
last_json_mtime = 0
current_devices = []

def reload_devices_if_changed():
    global last_json_mtime, current_devices

    try:
        mtime = os.path.getmtime("devices.json")
    except FileNotFoundError:
        print("devices.json tidak ditemukan")
        return

    if mtime != last_json_mtime:
        last_json_mtime = mtime

        print("\n🔄 JSON berubah → reload device list...\n")

        with open("devices.json", "r") as f:
            data = json.load(f)

        new_devices = data["devices"]

        # Unsubscribe lama
        for dev in current_devices:
            topics = generate_topics(dev)
            for t in topics:
                client.unsubscribe(t)
                print("Unsubscribe:", t)

        # Subscribe baru
        current_devices = new_devices
        for dev in new_devices:
            topics = generate_topics(dev)
            for t in topics:
                client.subscribe(t)
                print("Subscribe:", t)

        print("\n✔ Device list updated:", current_devices, "\n")

# ==========================
# MAIN LOOP 5 MENIT
# ==========================
print("MQTT Listener Running (WSS + Auto Reload JSON + 5 Minute Recording)...")

reload_devices_if_changed()

last_save_time = time.time()

while True:
    client.loop(timeout=1.0)
    reload_devices_if_changed()

    # setiap 300 detik (5 menit)
    if time.time() - last_save_time >= 300:
        save_data_to_mysql()
        last_save_time = time.time()

    time.sleep(1)
