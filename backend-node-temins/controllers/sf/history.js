const db = require('../../config/db');

const getHistory = async (req, res) => {
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
};

module.exports = { getHistory };
