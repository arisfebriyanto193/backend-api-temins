const db = require('../../config/db');

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

const getHistory = async (req, res) => {
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
};

module.exports = { getHistory };
