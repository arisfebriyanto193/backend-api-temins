import json
import ssl
import time
from paho.mqtt import client as mqtt

# ==========================
# Load device list
# ==========================
def load_devices():
    with open("devices.json", "r") as f:
        data = json.load(f)
    return data["devices"]

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
# MQTT Callback
# ==========================
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        print("✅ Connected to MQTT WebSocket Broker")
        devices = load_devices()

        for device in devices:
            topics = generate_topics(device)
            for topic in topics:
                client.subscribe(topic)
                print(f"📡 Subscribe: {topic}")
    else:
        print("❌ Failed connect, rc =", rc)

def on_message(client, userdata, msg):
    topic = msg.topic
    payload = msg.payload.decode()

    print("=" * 50)
    print("📥 Topic  :", topic)
    print("📦 Data   :", payload)

    # Jika payload JSON
    try:
        data = json.loads(payload)
        print("🧾 JSON   :", data)
    except:
        pass

# ==========================
# MQTT Setup (WebSocket)
# ==========================
broker_host = "karsacerdasinovatif.web.id"
broker_port = 8081  # WebSocket Port

client = mqtt.Client(
    client_id="temins_ws_subscriber",
    transport="websockets"
)

client.on_connect = on_connect
client.on_message = on_message

# Jika broker pakai SSL (wss://)
client.tls_set(
    ca_certs=None,
    certfile=None,
    keyfile=None,
    cert_reqs=ssl.CERT_NONE
)
client.tls_insecure_set(True)

# Path WebSocket (penting!)
client.ws_set_options(path="/mqtt")

# ==========================
# Connect & Loop
# ==========================
print("🔌 Connecting to broker...")
client.connect(broker_host, broker_port, keepalive=60)

client.loop_forever()
