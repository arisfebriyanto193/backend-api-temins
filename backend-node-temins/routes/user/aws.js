const express = require('express');
const router = express.Router();
const db = require('../../config/db');
const verifyToken = require('../../middleware/auth');

function detectType(label) {
    const l = label.toLowerCase();
    if (l.includes('arah')) return 'wind_dir';
    if (l.includes('kecepatan') || l.includes('speed')) return 'wind_spd';
    if (l.includes('gust')) return 'wind_gust';
    if (l.includes('hujan') || l.includes('rain')) return 'rain';
    if (l.includes('suhu') || l.includes('temp')) return 'temp';
    if (l.includes('lembab') || l.includes('hum')) return 'hum';
    if (l.includes('tekanan') || l.includes('press')) return 'press';
    if (l.includes('radiasi') || l.includes('solar')) return 'solar';
    if (l.includes('baterai') || l.includes('batt') || l.includes('volt')) return 'battery';
    return 'general';
}

router.get('/ds.php', verifyToken, async (req, res) => {
    try {
        const user_id = req.user.uid || req.user.id;

        const [devRows] = await db.execute("SELECT * FROM user_devices WHERE user_id = ? LIMIT 1", [user_id]);
        if (devRows.length === 0) {
            return res.json({ status: false, message: "No device found for User ID: " + user_id });
        }

        const device = devRows[0];
        const device_unique_id = device.device_unique_id;

        const [configRows] = await db.execute(
            `SELECT parameter_name, mqtt_topic, unit, category 
             FROM device_settings 
             WHERE device_unique_id = ? 
             AND is_visible = 1 AND category = 'sensor' AND parameter_name != 'Arus Charging' 
             ORDER BY display_order ASC`,
            [device_unique_id]
        );

        let sensors = [];
        let topics = [];

        for (let conf of configRows) {
            const topic = conf.mqtt_topic;
            const [logRows] = await db.execute("SELECT value FROM sensor_logs WHERE topic=? ORDER BY id DESC LIMIT 1", [topic]);
            
            let lastVal = 0;
            if (logRows.length > 0) {
                lastVal = parseFloat(logRows[0].value);
            }

            sensors.push({
                label: conf.parameter_name,
                topic: conf.mqtt_topic,
                unit: conf.unit,
                value: lastVal,
                type: detectType(conf.parameter_name)
            });
            topics.push(conf.mqtt_topic);
        }

        const [chartRows] = await db.execute(
            `SELECT usc.data, ds.parameter_name, ds.mqtt_topic
             FROM user_sensor_charts usc
             JOIN device_settings ds ON ds.id = usc.device_setting_id
             WHERE usc.device_unique_id = ? AND usc.is_active = 1
             ORDER BY usc.chart_order ASC`,
            [device_unique_id]
        );

        let charts = [];
        for (let row of chartRows) {
            let valCode = row.data;
            if (!valCode && row.mqtt_topic) {
                const parts = row.mqtt_topic.split('/');
                valCode = parts[parts.length - 1];
            }
            charts.push({ val: valCode, label: row.parameter_name });
        }

        return res.json({
            status: true,
            device: {
                name: device.device_name,
                id: device_unique_id,
                lokasi: device.location,
                zona_waktu: device.timezone,
                owner: device.owner_name
            },
            sensors: sensors,
            charts: charts,
            mqtt_topics: topics
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
        if (devRows.length === 0) {
            return res.json({ status: false, message: "No device found" });
        }

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
            if (logRows.length > 0) {
                lastVal = parseFloat(logRows[0].value);
            }

            const t = topic.toLowerCase();
            const l = conf.parameter_name.toLowerCase();
            let type_id = 'general';
            
            if (t.includes('amp') || t.includes('arus') || l.includes('arus')) type_id = 'amp';
            if (t.includes('volt') || t.includes('tegangan') || l.includes('tegangan')) type_id = 'volt';
            if (t.includes('watt') || t.includes('daya') || l.includes('daya')) type_id = 'watt';

            const parts = topic.split('/');
            const code = parts[parts.length - 1];

            sensors.push({
                label: conf.parameter_name,
                topic: topic,
                unit: conf.unit,
                value: lastVal,
                type_id: type_id,
                code: code
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

function getSensorConfig(label) {
    const l = label.toLowerCase();
    if (l.includes('arah')) return { icon: 'fa-compass', color: '#a855f7' };
    if (l.includes('kecepatan')) return { icon: 'fa-wind', color: '#06b6d4' };
    if (l.includes('hujan')) return { icon: 'fa-cloud-rain', color: '#3b82f6' };
    if (l.includes('suhu')) return { icon: 'fa-temperature-half', color: '#f97316' };
    if (l.includes('lembab')) return { icon: 'fa-droplet', color: '#10b981' };
    if (l.includes('tekanan')) return { icon: 'fa-gauge', color: '#64748b' };
    if (l.includes('solar')) return { icon: 'fa-sun', color: '#eab308' };
    if (l.includes('volt')) return { icon: 'fa-car-battery', color: '#22c55e' };
    return { icon: 'fa-microchip', color: '#6366f1' };
}

router.get('/history.php', verifyToken, async (req, res) => {
    try {
        const user_id = req.user.uid || req.user.id;
        const [devRows] = await db.execute("SELECT * FROM user_devices WHERE user_id = ? LIMIT 1", [user_id]);
        if (devRows.length === 0) return res.json({ status: false, message: "No device linked" });

        const device = devRows[0];
        const device_unique_id = device.device_unique_id;

        const [chartRows] = await db.execute(
            `SELECT usc.data, ds.parameter_name, ds.unit
             FROM user_sensor_charts usc
             JOIN device_settings ds ON ds.id = usc.device_setting_id
             WHERE usc.device_unique_id = ? AND usc.is_active = 1
             ORDER BY usc.chart_order ASC`,
            [device_unique_id]
        );

        let sensors = [];
        for (let row of chartRows) {
            const style = getSensorConfig(row.parameter_name);
            sensors.push({
                code: row.data,
                label: row.parameter_name,
                unit: row.unit,
                icon: style.icon,
                color: style.color
            });
        }

        const minYear = 2024;
        const maxYear = new Date().getFullYear();
        let years = [];
        for (let y = maxYear; y >= minYear; y--) years.push(y);

        return res.json({
            status: true,
            device_name: device.device_name,
            device_id: device_unique_id,
            lokasi: device.location,
            kota: device.city,
            zonawaktu: device.timezone,
            sensors: sensors,
            years: years
        });
    } catch (error) {
        console.error(error);
        res.status(500).json({ status: false, message: "Internal Server Error" });
    }
});

router.get('/cuaca.php', verifyToken, async (req, res) => {
    try {
        const user_id = req.user.uid || req.user.id;
        const [devRows] = await db.execute("SELECT * FROM user_devices WHERE user_id = ? LIMIT 1", [user_id]);
        if (devRows.length === 0) return res.json({ status: false, message: "No device found" });

        const device_unique_id = devRows[0].device_unique_id;
        const [rows] = await db.execute("SELECT parameter_name, mqtt_topic FROM device_settings WHERE device_unique_id=?", [device_unique_id]);

        let data = {};
        let mqtt_topics = [];

        for (let row of rows) {
            let code = '';
            const param_name = (row.parameter_name || '').trim().toLowerCase();

            if (param_name.includes('suhu')) {
                code = 'su';
            } else if (param_name.includes('kelembapan') || param_name.includes('lembab')) {
                code = 'ku';
            } else if (param_name.includes('radiasi') || param_name.includes('matahari')) {
                code = 'rm';
            } else if (param_name.includes('hujan')) {
                code = 'ch';
            } else if (param_name.includes('angin') || param_name.includes('kecepatan')) {
                code = 'ka';
            }

            if (code !== '' && !data[code]) {
                data[code] = row.mqtt_topic;
                mqtt_topics.push(row.mqtt_topic);
            }
        }

        return res.json({
            status: true,
            data: data,
            mqtt_topics: mqtt_topics,
            device_unique_id: device_unique_id
        });
    } catch (error) {
        console.error(error);
        res.status(500).json({ status: false, message: "Internal Server Error" });
    }
});

router.get('/wind.php', verifyToken, async (req, res) => {
    try {
        const user_id = req.user.uid || req.user.id;
        const [devRows] = await db.execute("SELECT * FROM user_devices WHERE user_id = ? LIMIT 1", [user_id]);
        if (devRows.length === 0) return res.json({ status: false, message: "No device found" });

        const device_unique_id = devRows[0].device_unique_id;
        const [rows] = await db.execute("SELECT parameter_name, mqtt_topic FROM device_settings WHERE device_unique_id=?", [device_unique_id]);

        let data = {};
        let mqtt_topics = [];

        for (let row of rows) {
            let code = '';
            const param_name = (row.parameter_name || '').trim().toLowerCase();

            if (param_name.includes('hujan') || param_name.includes('curah')) {
                code = 'ch';
            } else if (param_name.includes('arah')) {
                code = 'aa';
            } else if (param_name.includes('kecepatan') || (param_name.includes('angin') && !param_name.includes('arah'))) {
                code = 'ka';
            }

            if (code !== '' && !data[code]) {
                data[code] = row.mqtt_topic;
                mqtt_topics.push(row.mqtt_topic);
            }
        }

        return res.json({
            status: true,
            data: data,
            mqtt_topics: mqtt_topics,
            device_unique_id: device_unique_id
        });
    } catch (error) {
        console.error(error);
        res.status(500).json({ status: false, message: "Internal Server Error" });
    }
});

module.exports = router;
