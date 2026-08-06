const db = require('../../config/db');

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

const getHistory = async (req, res) => {
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
};

const getHistoryMobile = async (req, res) => {
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
        
        const minYear = getYear(range.f);
        const maxYear = getYear(range.l);
        let years = [];
        for (let y = maxYear; y >= minYear; y--) years.push(y);

        // PHP logic hardcoded minYear = 2024 for some reason, but dynamic is better
        if (years.length === 0) years = [new Date().getFullYear()];

        return res.json({
            status: true,
            device_name: device.device_name,
            device_id: device_unique_id,
            zonawaktu: device.timezone,
            sensors: sensorOptions,
            years: years
        });
    } catch (error) {
        console.error(error);
        res.status(500).json({ status: false, message: "Internal Server Error" });
    }
};

module.exports = { getHistory, getHistoryMobile };
