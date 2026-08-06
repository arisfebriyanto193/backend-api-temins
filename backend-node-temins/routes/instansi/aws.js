const express = require('express');
const router = express.Router();
const db = require('../../config/db');
const verifyToken = require('../../middleware/auth');

router.get('/ds.php', verifyToken, async (req, res) => {
    try {
        if (req.user.role !== 'instansi') {
            return res.status(401).json({ status: false, message: "Unauthorized" });
        }

        const instansi_id = req.user.uid || req.user.id;

        const [userRows] = await db.execute("SELECT * FROM users WHERE id = ?", [instansi_id]);
        const user = userRows[0] || {};

        const [deviceRows] = await db.execute(`
            SELECT ud.device_unique_id, ud.device_name, ud.location, ud.owner_name, u.username as member_name
            FROM user_devices ud
            JOIN users u ON ud.user_id = u.id
            WHERE u.instansi_id = ? 
            ORDER BY ud.device_name ASC
        `, [instansi_id]);

        let devices = [];
        for (let row of deviceRows) {
            devices.push({
                id: row.device_unique_id,
                name: row.device_name,
                location: row.location,
                owner: row.owner_name,
                added_by: row.member_name
            });
        }

        const selected_id = req.query.device_id || null;
        let details = null;

        if (selected_id) {
            const [sensorRows] = await db.execute(`
                SELECT parameter_name, mqtt_topic, unit, category, display_order 
                FROM device_settings WHERE device_unique_id = ? AND is_visible = 1 AND category = 'sensor'
            `, [selected_id]);

            let sensors = [];
            let topics = [];
            for (let s of sensorRows) {
                sensors.push({ label: s.parameter_name, topic: s.mqtt_topic, unit: s.unit, value: 0, type: '' });
                topics.push(s.mqtt_topic);
            }

            const [devInfoRows] = await db.execute("SELECT * FROM user_devices WHERE device_unique_id = ? LIMIT 1", [selected_id]);
            const dev_info = devInfoRows[0] || {};

            const [chartRows] = await db.execute(`
                SELECT usc.data, ds.parameter_name 
                FROM user_sensor_charts usc 
                JOIN device_settings ds ON usc.device_setting_id = ds.id 
                WHERE usc.device_unique_id = ? AND usc.is_active = 1
            `, [selected_id]);

            let charts = [];
            for (let c of chartRows) {
                charts.push({ val: c.data, label: c.parameter_name });
            }

            details = {
                device: {
                    name: dev_info.device_name,
                    id: dev_info.device_unique_id,
                    lokasi: dev_info.location,
                    zona_waktu: dev_info.timezone || 'WIB',
                    owner: dev_info.owner_name,
                    username: user.username
                },
                sensors: sensors,
                charts: charts,
                mqtt_topics: topics
            };
        }

        return res.json({
            status: true,
            instansi_name: user.username,
            device_list: devices,
            selected_device_details: details
        });

    } catch (error) {
        console.error(error);
        res.status(500).json({ status: false, message: "Internal Server Error" });
    }
});

module.exports = router;
