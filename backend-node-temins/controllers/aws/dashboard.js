const db = require('../../config/db');

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

const getDashboard = async (req, res) => {
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
};

module.exports = { getDashboard };
