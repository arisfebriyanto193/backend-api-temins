const db = require('../../config/db');

const getPower = async (req, res) => {
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
};

const getPowerMobile = getPower;

module.exports = { getPower, getPowerMobile };
