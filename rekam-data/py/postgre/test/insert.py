import psycopg2
import random
from datetime import datetime, timedelta
from psycopg2.extras import execute_batch

# =========================
# KONFIGURASI DATABASE
# =========================
DB_CONFIG = {
    "host": "localhost",
    "port": 5432,
    "dbname": "temins",
    "user": "postgres",
    "password": "example"
}

TABLE_NAME = "sensor_logs"

# =========================
# PARAMETER
# =========================
PARAMETERS = [
    "su", "ku", "tu", "su2", "tu2", "ku2", "su3", "ku3",
    "tt", "ka", "aa", "rm", "ch", "kam", "cha", "chr",
    "tsp", "tb", "ac", "da", "sm", "sur"
]

DEVICE_ID = "0822"
INTERVAL_MINUTES = 5
TOTAL_DAYS = 30
TOTAL_POINTS = int((24 * 60 / INTERVAL_MINUTES) * TOTAL_DAYS)  # 8640

# =========================
# GENERATE DATA (FIXED)
# =========================
def generate_data():
    data = []

    # =========================
    # FIXED DATE RANGE
    # =========================
    start_time = datetime(2025, 12, 1, 0, 0)
    end_time   = datetime(2025, 12, 22, 23, 55)

    # hitung total point otomatis
    total_minutes = int((end_time - start_time).total_seconds() / 60)
    total_points = total_minutes // INTERVAL_MINUTES + 1

    timestamps = [
        start_time + timedelta(minutes=i * INTERVAL_MINUTES)
        for i in range(total_points)
    ]

    for ts in timestamps:
        for param in PARAMETERS:
            row = (
                DEVICE_ID,
                param,
                round(random.uniform(10, 100), 2),
                None,
                ts
            )
            data.append(row)

    return data

# =========================
# INSERT KE DATABASE
# =========================
def insert_data():
    conn = psycopg2.connect(**DB_CONFIG)
    cursor = conn.cursor()

    sql = f"""
        INSERT INTO {TABLE_NAME}
        (device_unique_id, parameter_name, value, topic, recorded_at)
        VALUES (%s, %s, %s, %s, %s)
    """

    data = generate_data()
    print(f"Total data yang akan dimasukkan: {len(data)}")

    execute_batch(cursor, sql, data, page_size=5000)

    conn.commit()
    cursor.close()
    conn.close()

    print("✅ Data dummy berhasil dimasukkan (8640 baris per parameter)")

# =========================
# MAIN
# =========================
if __name__ == "__main__":
    insert_data()
