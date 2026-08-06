const db = require('../../config/db');

const getDashboard = async (req, res) => {
    try {
        const user_id = req.user.uid || req.user.id;

        const [devRows] = await db.execute("SELECT * FROM user_devices WHERE user_id = ? LIMIT 1", [user_id]);
        if (devRows.length === 0) {
            return res.json({ status: false, message: "Device not found" });
        }

        const device = devRows[0];
        const device_unique_id = device.device_unique_id;
        const device_name = device.device_name;

        let topics_to_subscribe = [];
        let topic_dist = "";
        let topic_batt = "";
        let sensor_max_height = 4100;

        const [confRows] = await db.execute(
            "SELECT parameter_name, mqtt_topic, tinggi_sensor FROM device_settings WHERE device_unique_id = ? AND is_visible = 1",
            [device_unique_id]
        );

        for (let row of confRows) {
            let p_name = (row.parameter_name || '').toLowerCase();
            let t_mqtt = row.mqtt_topic;

            if (p_name.includes('tinggi air cm')) {
                topic_dist = t_mqtt;
                if (row.tinggi_sensor > 0) sensor_max_height = parseFloat(row.tinggi_sensor);
            } else if (p_name.includes('batre') || p_name.includes('battery')) {
                topic_batt = t_mqtt;
            }
            topics_to_subscribe.push(t_mqtt);
        }

        let initial_distance = 0;
        let initial_battery = 0;

        if (topic_dist) {
            const [q1] = await db.execute("SELECT value FROM sensor_logs WHERE topic=? ORDER BY id DESC LIMIT 1", [topic_dist]);
            if (q1.length > 0) initial_distance = parseFloat(q1[0].value);
        }

        if (topic_batt) {
            const [q2] = await db.execute("SELECT value FROM sensor_logs WHERE topic=? ORDER BY id DESC LIMIT 1", [topic_batt]);
            if (q2.length > 0) initial_battery = parseFloat(q2[0].value);
        }

        const [q_config] = await db.execute(
            "SELECT parameter_name, unit, tinggi_sensor FROM device_settings WHERE device_unique_id = ? AND category = 'config'",
            [device_unique_id]
        );
        let data_config = q_config.length > 0 ? q_config[0] : {};

        const [q_config2] = await db.execute(
            "SELECT category FROM device_settings WHERE device_unique_id = ? AND mqtt_topic = 'jenis'",
            [device_unique_id]
        );
        let data_config2 = q_config2.length > 0 ? q_config2[0] : {};

        const [q_grafik] = await db.execute(
            "SELECT data FROM user_sensor_charts WHERE device_unique_id = ? AND is_active = 1 AND chart_order = 111 LIMIT 1",
            [device_unique_id]
        );
        let grafik_data = null;
        if (q_grafik.length > 0) {
            let rawData = q_grafik[0].data;
            try {
                grafik_data = JSON.parse(rawData);
            } catch (e) {
                grafik_data = rawData;
            }
        }

        return res.json({
            status: true,
            device: {
                name: device_name,
                id: device_unique_id,
                max_height: data_config.tinggi_sensor,
                data: data_config.parameter_name,
                statusData: data_config.unit,
                grafik: grafik_data,
                lokasi: device.location,
                owner: device.owner_name,
                jenis: data_config2.category,
                zonawaktu: device.timezone
            },
            mqtt: {
                topics: topics_to_subscribe,
                topic_dist: topic_dist,
                topic_batt: topic_batt
            },
            initial: {
                distance: initial_distance,
                battery: initial_battery
            }
        });

    } catch (error) {
        console.error(error);
        res.status(500).json({ status: false, message: "Internal Server Error" });
    }
};

module.exports = { getDashboard };
