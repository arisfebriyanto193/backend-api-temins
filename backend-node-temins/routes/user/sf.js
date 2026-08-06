const express = require('express');
const router = express.Router();
const db = require('../../config/db');
const verifyToken = require('../../middleware/auth');

function detectType(label) {
    const l = label.toLowerCase();
    if (l.includes('suhu') || l.includes('temp')) return 'soil_temp';
    if (l.includes('kelembapan') || l.includes('moist') || l.includes('hum')) return 'soil_moist';
    if (/\bn\b/.test(l) || l.includes('nitrogen')) return 'val_n';
    if (/\bp\b/.test(l) || l.includes('fosfor') || l.includes('phosphor')) return 'val_p';
    if (/\bk\b/.test(l) || l.includes('kalium') || l.includes('potassium')) return 'val_k';
    if (l.includes('ph')) return 'val_ph';
    if (l.includes('tds')) return 'val_tds';
    if (l.includes('ec') || l.includes('konduktifitas')) return 'val_ec';
    if (l.includes('garam') || l.includes('salt')) return 'val_salt';
    if (l.includes('baterai') || l.includes('batt') || l.includes('volt')) return 'battery';
    return 'general';
}

router.get('/ds.php', verifyToken, async (req, res) => {
    try {
        const user_id = req.user.uid || req.user.id;
        const [devRows] = await db.execute("SELECT * FROM user_devices WHERE user_id = ?", [user_id]);
        if (devRows.length === 0) return res.json({ status: false, message: "Akun belum terhubung alat Smart Farm." });

        const device = devRows[0];
        const device_unique_id = device.device_unique_id;

        const [chartRows] = await db.execute(`
            SELECT ds.mqtt_topic, ds.parameter_name
            FROM user_sensor_charts usc
            JOIN device_settings ds ON ds.id = usc.device_setting_id
            WHERE usc.device_unique_id = ? AND usc.is_active = 1
            ORDER BY usc.chart_order ASC
        `, [device_unique_id]);

        let chartList = [];
        for (let row of chartRows) {
            let parts = (row.mqtt_topic || '').split('/');
            chartList.push({ val: parts[parts.length - 1], label: row.parameter_name });
        }

        const [configRows] = await db.execute(`
            SELECT * FROM device_settings 
            WHERE device_unique_id = ? AND is_visible = 1 AND category = 'sensor' 
            ORDER BY display_order ASC
        `, [device_unique_id]);

        let topics_to_subscribe = [];
        let soilMoist = null, soilTemp = null, soilPh = null, battery = null;
        let npkList = [], chemList = [];

        for (let conf of configRows) {
            const topic = conf.mqtt_topic;
            topics_to_subscribe.push(topic);

            const [logRows] = await db.execute("SELECT value FROM sensor_logs WHERE topic=? ORDER BY id DESC LIMIT 1", [topic]);
            let val = logRows.length > 0 ? parseFloat(logRows[0].value) : 0;

            let sensorData = {
                id: conf.id,
                name: conf.parameter_name,
                topic: topic,
                value: val,
                unit: conf.unit,
                type: detectType(conf.parameter_name)
            };

            switch (sensorData.type) {
                case 'soil_moist': if (!soilMoist) soilMoist = sensorData; else chemList.push(sensorData); break;
                case 'soil_temp': if (!soilTemp) soilTemp = sensorData; else chemList.push(sensorData); break;
                case 'val_ph': if (!soilPh) soilPh = sensorData; else chemList.push(sensorData); break;
                case 'val_n':
                case 'val_p':
                case 'val_k': npkList.push(sensorData); break;
                case 'battery': battery = sensorData; break;
                default: chemList.push(sensorData); break;
            }
        }

        return res.json({
            status: true,
            device: {
                name: device.device_name,
                id: device_unique_id,
                lokasi: "smg",
                zonawaktu: "WITA"
            },
            mqtt: {
                broker: "wss://karsacerdasinovatif.web.id:8081",
                topics: topics_to_subscribe
            },
            sensors: { soil_moist: soilMoist, soil_temp: soilTemp, soil_ph: soilPh, battery, npk: npkList, chem: chemList },
            charts: chartList
        });
    } catch (error) {
        console.error(error);
        res.status(500).json({ status: false, message: "Internal Server Error" });
    }
});

router.get('/history.php', verifyToken, async (req, res) => {
    try {
        const user_id = req.user.uid || req.user.id;
        const [devRows] = await db.execute("SELECT * FROM user_devices WHERE user_id = ?", [user_id]);
        if (devRows.length === 0) return res.json({ status: false, message: 'No device linked' });

        const device = devRows[0];
        const device_unique_id = device.device_unique_id;

        const [q_sensor] = await db.execute(`
            SELECT usc.id as chart_id, usc.data as code, ds.parameter_name as label, ds.mqtt_topic as topic, ds.unit
            FROM user_sensor_charts usc
            JOIN device_settings ds ON ds.id = usc.device_setting_id
            WHERE usc.device_unique_id = ? AND usc.is_active = 1
            ORDER BY usc.chart_order ASC
        `, [device_unique_id]);

        let sensors = q_sensor;

        return res.json({
            status: true,
            device: { name: device.device_name, id: device_unique_id, zonawaktu: device.timezone },
            sensors: sensors,
            years: { min: 2024, max: new Date().getFullYear() }
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
            `SELECT parameter_name, mqtt_topic, unit FROM device_settings 
             WHERE device_unique_id = ? AND category = 'power' ORDER BY display_order ASC`,
            [device_unique_id]
        );

        let sensors = [], topics = [];
        for (let conf of configRows) {
            const topic = conf.mqtt_topic;
            topics.push(topic);
            const [logRows] = await db.execute("SELECT value FROM sensor_logs WHERE topic=? ORDER BY id DESC LIMIT 1", [topic]);
            let lastVal = logRows.length > 0 ? parseFloat(logRows[0].value) : 0;

            const t = topic.toLowerCase(), l = conf.parameter_name.toLowerCase();
            let type_id = 'general';
            if (t.includes('amp') || t.includes('arus') || l.includes('arus')) type_id = 'amp';
            if (t.includes('volt') || t.includes('tegangan') || l.includes('tegangan')) type_id = 'volt';
            if (t.includes('watt') || t.includes('daya') || l.includes('daya')) type_id = 'watt';

            let parts = topic.split('/');
            sensors.push({
                label: conf.parameter_name, topic: topic, unit: conf.unit,
                value: lastVal, type_id: type_id, code: parts[parts.length - 1]
            });
        }

        return res.json({
            status: true,
            device: { name: device.device_name, id: device_unique_id, zonawaktu: device.timezone },
            sensors: sensors,
            mqtt_topics: topics
        });
    } catch (error) {
        console.error(error);
        res.status(500).json({ status: false, message: "Internal Server Error" });
    }
});

module.exports = router;
