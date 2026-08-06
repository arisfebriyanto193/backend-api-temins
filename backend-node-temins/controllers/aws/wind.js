const db = require('../../config/db');

const getWind = async (req, res) => {
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
};

module.exports = { getWind };
