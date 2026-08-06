const express = require('express');
const router = express.Router();
const db = require('../../config/db');
const verifyToken = require('../../middleware/auth');

router.get('/ds.php', verifyToken, async (req, res) => {
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
});

function getSensorStyle(label) {
    const l = label.toLowerCase();
    if (l.includes('kecepatan') || l.includes('speed')) return { icon: 'fa-wind', color: '#06b6d4' };
    if (l.includes('hujan') || l.includes('rain')) return { icon: 'fa-cloud-rain', color: '#3b82f6' };
    if (l.includes('suhu') || l.includes('temp')) return { icon: 'fa-temperature-half', color: '#f97316' };
    if (l.includes('lembab') || l.includes('hum')) return { icon: 'fa-droplet', color: '#10b981' };
    if (l.includes('solar') || l.includes('radiasi')) return { icon: 'fa-sun', color: '#eab308' };
    if (l.includes('volt') || l.includes('batt')) return { icon: 'fa-car-battery', color: '#22c55e' };
    return { icon: 'fa-microchip', color: '#6366f1' };
}

router.get('/history.php', verifyToken, async (req, res) => {
    try {
        const user_id = req.user.uid || req.user.id;
        const [devRows] = await db.execute("SELECT * FROM user_devices WHERE user_id = ? LIMIT 1", [user_id]);
        if (devRows.length === 0) return res.json({ status: false, message: "Device not connected" });

        const device = devRows[0];
        const device_unique_id = device.device_unique_id;

        const [q] = await db.execute(
            `SELECT usc.data, ds.parameter_name, ds.unit
             FROM user_sensor_charts usc
             JOIN device_settings ds ON ds.id = usc.device_setting_id
             WHERE usc.device_unique_id = ? AND usc.is_active = 1
             ORDER BY usc.chart_order ASC`,
            [device_unique_id]
        );

        let sensorOptions = [];
        for (let row of q) {
            const style = getSensorStyle(row.parameter_name);
            sensorOptions.push({
                code: row.data,
                label: row.parameter_name,
                unit: row.unit,
                icon: style.icon,
                color: style.color
            });
        }

        const [rangeRows] = await db.execute("SELECT MIN(recorded_at) as f, MAX(recorded_at) as l FROM sensor_logs WHERE device_unique_id = ?", [device_unique_id]);
        const range = rangeRows[0] || {};
        const getYear = (dateStr) => dateStr ? new Date(dateStr).getFullYear() : new Date().getFullYear();

        return res.json({
            status: true,
            device_name: device.device_name,
            device_id: device_unique_id,
            zonawaktu: device.timezone,
            sensors: sensorOptions,
            years: {
                start: getYear(range.f),
                end: getYear(range.l)
            }
        });
    } catch (error) {
        console.error(error);
        res.status(500).json({ status: false, message: "Internal Server Error" });
    }
});

router.get('/power.php', verifyToken, async (req, res) => {
    try {
        const user_id = req.user.uid || req.user.id;
        const [devRows] = await db.execute("SELECT * FROM user_devices WHERE user_id = ? LIMIT 1", [user_id]);
        if (devRows.length === 0) return res.json({ status: false, message: "No device found" });

        const device = devRows[0];
        const device_unique_id = device.device_unique_id;

        const [configRows] = await db.execute(
            `SELECT parameter_name, mqtt_topic, unit 
             FROM device_settings 
             WHERE device_unique_id = ? 
             AND category = 'power' 
             ORDER BY display_order ASC`,
            [device_unique_id]
        );

        let sensors = [];
        let topics = [];

        for (let conf of configRows) {
            const topic = conf.mqtt_topic;
            topics.push(topic);
            
            const [logRows] = await db.execute("SELECT value FROM sensor_logs WHERE topic=? ORDER BY id DESC LIMIT 1", [topic]);
            let lastVal = 0;
            if (logRows.length > 0) lastVal = parseFloat(logRows[0].value);

            const t = topic.toLowerCase();
            const l = conf.parameter_name.toLowerCase();
            let type_id = 'general';
            if (t.includes('amp') || t.includes('arus') || l.includes('arus')) type_id = 'amp';
            if (t.includes('volt') || t.includes('tegangan') || l.includes('tegangan')) type_id = 'volt';
            if (t.includes('watt') || t.includes('daya') || l.includes('daya')) type_id = 'watt';

            const parts = topic.split('/');
            sensors.push({
                label: conf.parameter_name,
                topic: topic,
                unit: conf.unit,
                value: lastVal,
                type_id: type_id,
                code: parts[parts.length - 1]
            });
        }

        return res.json({
            status: true,
            device: {
                name: device.device_name,
                id: device_unique_id,
                zonawaktu: device.timezone
            },
            sensors: sensors,
            mqtt_topics: topics
        });
    } catch (error) {
        console.error(error);
        res.status(500).json({ status: false, message: "Internal Server Error" });
    }
});

module.exports = router;
