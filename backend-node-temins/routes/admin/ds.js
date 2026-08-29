const express = require('express');
const router = express.Router();
const db = require('../../config/db');
const verifyToken = require('../../middleware/auth');
const bcrypt = require('bcryptjs');

router.use(verifyToken);

router.use(async (req, res, next) => {
    try {
        const uid = req.user.uid || req.user.id;
        const [rows] = await db.execute("SELECT role FROM users WHERE id = ?", [uid]);
        if (rows.length === 0 || rows[0].role !== 'admin') {
            return res.status(403).json({ status: false, message: "Access Denied (Admin Only)" });
        }
        next();
    } catch (e) {
        return res.status(500).json({ status: false, message: "Server error" });
    }
});

function nullIfEmpty(value) {
    if (value === undefined || value === null) return null;
    const str = String(value).trim();
    return str === '' ? null : str;
}

router.all('/', async (req, res) => {
    try {
        const method = req.method;
        const action = req.query.action || req.body?.action || '';

        if (method === 'GET') {
            if (action === 'get_templates') {
                const [rows] = await db.execute(`
                    SELECT t.template_code, t.template_name, p.param_name, p.mqtt_suffix, p.unit, p.data 
                    FROM device_templates t 
                    JOIN template_params p ON t.id = p.template_id 
                    ORDER BY t.id, p.id
                `);
                let templates_data = {};
                for (let row of rows) {
                    const code = row.template_code;
                    if (!templates_data[code]) templates_data[code] = { name: row.template_name, params: [] };
                    templates_data[code].params.push({ n: row.param_name, k: row.mqtt_suffix, u: row.unit, d: row.data });
                }
                return res.json({ status: true, data: templates_data });
            }

            if (action === 'get_device_config' && req.query.device_unique_id) {
                const did = req.query.device_unique_id;
                const uid_filter = req.query.user_id;

                const [sRows] = await db.execute("SELECT * FROM device_settings WHERE device_unique_id=? AND category NOT IN ('config', 'jenis') ORDER BY display_order ASC", [did]);
                let settings = [];
                for (let s of sRows) {
                    const [cRows] = await db.execute("SELECT chart_order, data FROM user_sensor_charts WHERE device_setting_id = ?", [s.id]);
                    const chart = cRows[0];
                    s.is_chart = !!chart;
                    s.chart_order = chart ? chart.chart_order : 10;
                    s.chart_data = chart ? chart.data : '';
                    settings.push(s);
                }

                const [hRows] = await db.execute("SELECT tinggi_sensor FROM device_settings WHERE device_unique_id=? LIMIT 1", [did]);
                let awlr_height = hRows.length > 0 ? hRows[0].tinggi_sensor : 0;

                let sqlDevice = `
                    SELECT d.timezone, d.status, d.device_type, d.location, d.city, d.owner_name, d.internet_no, d.pic, d.pic_contact, d.masa_aktif, d.masa_paket, d.waktu_add, u.email, u.username
                    FROM user_devices d
                    JOIN users u ON d.user_id = u.id
                    WHERE d.device_unique_id = ?
                `;
                let paramsDevice = [did];
                if (uid_filter) {
                    sqlDevice += " AND u.id = ?";
                    paramsDevice.push(uid_filter);
                }
                sqlDevice += " LIMIT 1";

                const [devRows] = await db.execute(sqlDevice, paramsDevice);
                const dev = devRows[0] || {};
                const timezone = dev.timezone || 'UTC';
                const statusAlat = dev.status || '';
                const device_type = dev.device_type || '';
                const lokasi = dev.location || '';
                const kota = dev.city || '';
                const extended_data = devRows.length > 0 ? {
                    owner: dev.owner_name, internet_no: dev.internet_no, pic_name: dev.pic, pic_contact: dev.pic_contact,
                    email: dev.email, masa_aktif: dev.masa_aktif, masa_paket: dev.masa_paket, waktu_add: dev.waktu_add, username: dev.username
                } : {};

                const [autoRows] = await db.execute("SELECT id, parameter_name, operator, threshold, send_email, send_notification FROM device_automations WHERE device_unique_id = ? ORDER BY id ASC", [did]);
                let automations = autoRows.map(a => ({
                    ...a, threshold: parseFloat(a.threshold), send_email: !!a.send_email, send_notification: !!a.send_notification
                }));

                let responseData = {
                    status: true, timezone, lokasi, kota, statusAlat, settings, automations, awlr_height, ...extended_data
                };

                if (device_type.toUpperCase() === 'AWLR') {
                    const [confRows] = await db.execute("SELECT parameter_name, unit FROM device_settings WHERE device_unique_id=? AND category='config' LIMIT 1", [did]);
                    const [jenisRows] = await db.execute("SELECT category FROM device_settings WHERE device_unique_id=? AND mqtt_topic='jenis' LIMIT 1", [did]);
                    responseData.awlrData = confRows[0]?.parameter_name || null;
                    responseData.awlrStatusData = confRows[0]?.unit || null;
                    responseData.awlrJenis = jenisRows[0]?.category || 'tidak di ketahui';
                }
                return res.json(responseData);
            }

            const [usersRaw] = await db.execute(`
                SELECT u.id, u.username, u.is_demo, d.device_type, d.device_unique_id, d.owner_name, d.city, d.status
                FROM users u JOIN user_devices d ON u.id = d.user_id 
                WHERE u.role = 'user' ORDER BY u.id DESC
            `);
            const users = usersRaw.map(u => ({
                ...u,
                id: String(u.id),
                status: String(u.status)
            }));
            const [statusRows] = await db.execute("SELECT SUM(status = 1) AS aktif, SUM(status = 0) AS nonaktif FROM user_devices");
            const device_status = { aktif: parseInt(statusRows[0]?.aktif || 0), nonaktif: parseInt(statusRows[0]?.nonaktif || 0) };
            
            return res.json({ status: true, device_status, data: users });
        }

        if (method === 'POST') {
            if (action === 'create_user') {
                const connection = await db.getConnection();
                try {
                    await connection.beginTransaction();
                    const { username, password, pic_name, timezone, dev_name, owner, city, lokasi, internet_no, dev_type, dev_id, email, is_demo, params, automations } = req.body;
                    
                    const [cek] = await connection.execute("SELECT id FROM users WHERE username=?", [username]);
                    if (cek.length > 0) throw new Error("Username sudah ada");

                    const hash = await bcrypt.hash(password, 10);
                    const is_demo_val = is_demo ? 1 : 0;
                    const [uRes] = await connection.execute("INSERT INTO users (username, password, role, email, is_demo) VALUES (?, ?, 'user', ?, ?)", [username, hash, email || '', is_demo_val]);
                    const new_uid = uRes.insertId;

                    const masa_aktif = nullIfEmpty(req.body.masa_aktif);
                    const masa_paket = nullIfEmpty(req.body.masa_paket);
                    
                    await connection.execute(`
                        INSERT INTO user_devices (user_id, device_name, owner_name, city, location, internet_no, pic_contact, device_type, device_unique_id, pic, timezone, masa_aktif, masa_paket, waktu_add) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    `, [new_uid, dev_name, owner, city, lokasi, internet_no, pic_name, dev_type, dev_id, pic_name, timezone, masa_aktif, masa_paket]);

                    if (!is_demo) {
                        if (Array.isArray(params)) {
                            for (let idx = 0; idx < params.length; idx++) {
                                const p = params[idx];
                                const order = idx + 1;
                                const [sRes] = await connection.execute(`
                                    INSERT INTO device_settings (device_unique_id, parameter_name, mqtt_topic, unit, display_order, is_visible, category) 
                                    VALUES (?, ?, ?, ?, ?, 1, 'sensor')
                                `, [dev_id, p.label, p.topic, p.unit, order]);
                                
                                if (p.data_key) {
                                    await connection.execute(`
                                        INSERT INTO user_sensor_charts (user_id, device_unique_id, device_setting_id, chart_order, is_active, data) 
                                        VALUES (?, ?, ?, ?, 1, ?)
                                    `, [new_uid, dev_id, sRes.insertId, order, p.data_key]);
                                }
                            }
                        }

                        if (Array.isArray(automations)) {
                            for (let auto of automations) {
                                await connection.execute(`
                                    INSERT INTO device_automations (device_unique_id, parameter_name, operator, threshold, send_email, send_notification) 
                                    VALUES (?, ?, ?, ?, ?, ?)
                                `, [dev_id, auto.parameter_name, auto.operator, parseFloat(auto.threshold), auto.send_email ? 1 : 0, auto.send_notification ? 1 : 0]);
                            }
                        }

                        await connection.execute("INSERT INTO device_settings (device_unique_id, parameter_name, mqtt_topic, unit, display_order, is_visible, category) VALUES (?, 'Tegangan', ?, 'V', 100, 1, 'power')", [dev_id, `temins_iot/${dev_id}/data/tsp`]);
                        await connection.execute("INSERT INTO device_settings (device_unique_id, parameter_name, mqtt_topic, unit, display_order, is_visible, category) VALUES (?, 'Arus Charging', ?, 'mA', 101, 1, 'power')", [dev_id, `temins_iot/${dev_id}/data/ac`]);
                        await connection.execute("INSERT INTO device_settings (device_unique_id, parameter_name, mqtt_topic, unit, display_order, is_visible, category) VALUES (?, 'daya', ?, 'watt', 101, 1, 'power')", [dev_id, `temins_iot/${dev_id}/data/da`]);

                        if (dev_type && dev_type.toUpperCase() === 'AWLR') {
                            await connection.execute("INSERT INTO device_settings (device_unique_id, parameter_name, tinggi_sensor, unit, is_visible, category, mqtt_topic) VALUES (?, 'tuc', '400', '1', 0, 'config', 'config')", [dev_id]);
                            await connection.execute("INSERT INTO device_settings (device_unique_id, parameter_name, tinggi_sensor, unit, is_visible, category, mqtt_topic) VALUES (?, 'tuc', '400', '1', 0, 'sungai', 'jenis')", [dev_id]);
                        }
                    }

                    await connection.commit();
                    connection.release();
                    return res.json({ status: true, message: "User berhasil dibuat" });
                } catch (err) {
                    await connection.rollback();
                    connection.release();
                    return res.status(400).json({ status: false, message: err.message });
                }
            }

            if (action === 'update_config') {
                const connection = await db.getConnection();
                try {
                    await connection.beginTransaction();
                    const dev_id = req.body.device_unique_id;
                    const uid = req.body.user_id;
                    const { timezone, statusAlat, lokasi, city, username, owner, internet_no, pic_name, pic_contact, email, params, automations, awlrData, awlrStatusData, awlrJenis, awlr_height } = req.body;
                    
                    const masa_aktif = nullIfEmpty(req.body.masa_aktif);
                    const masa_paket = nullIfEmpty(req.body.masa_paket);
                    const waktu_add = nullIfEmpty(req.body.waktu_add);

                    const [uCheck] = await connection.execute("SELECT is_demo FROM users WHERE id=?", [uid]);
                    const is_demo = uCheck.length > 0 ? uCheck[0].is_demo : 0;

                    if (timezone && statusAlat && lokasi && username) {
                        await connection.execute(`
                            UPDATE user_devices 
                            SET timezone=?, status=?, location=?, city=?, owner_name=?, internet_no=?, pic=?, pic_contact=?, masa_aktif=?, masa_paket=?, waktu_add=?
                            WHERE device_unique_id=? AND user_id=?
                        `, [timezone, statusAlat, lokasi, city, owner, internet_no, pic_name, pic_contact, masa_aktif, masa_paket, waktu_add, dev_id, uid]);
                        
                        if (uid) {
                            await connection.execute("UPDATE users SET username=?, email=? WHERE id=?", [username, email, uid]);
                        }
                    }

                    // Only update shared configurations if NOT a demo account
                    if (!is_demo) {
                        if (awlrData && awlrStatusData && awlrJenis) {
                            const [cResult] = await connection.execute("UPDATE device_settings SET parameter_name=?, unit=? WHERE device_unique_id=? AND category='config' LIMIT 1", [awlrData, awlrStatusData, dev_id]);
                            if (cResult.affectedRows === 0) {
                                await connection.execute("INSERT INTO device_settings (device_unique_id, parameter_name, tinggi_sensor, unit, is_visible, category, mqtt_topic) VALUES (?, ?, '400', ?, 0, 'config', 'config')", [dev_id, awlrData, awlrStatusData]);
                            }
                            const [jCek] = await connection.execute("SELECT id FROM device_settings WHERE device_unique_id=? AND mqtt_topic='jenis' LIMIT 1", [dev_id]);
                            if (jCek.length > 0) {
                                await connection.execute("UPDATE device_settings SET category=? WHERE device_unique_id=? AND mqtt_topic='jenis' LIMIT 1", [awlrJenis, dev_id]);
                            } else {
                                await connection.execute("INSERT INTO device_settings (device_unique_id, parameter_name, tinggi_sensor, unit, is_visible, category, mqtt_topic) VALUES (?, 'tuc', '400', '1', 0, ?, 'jenis')", [dev_id, awlrJenis]);
                            }
                        }

                        if (awlr_height) {
                            await connection.execute("UPDATE device_settings SET tinggi_sensor=? WHERE device_unique_id=?", [awlr_height, dev_id]);
                        }

                        await connection.execute("DELETE FROM user_sensor_charts WHERE device_unique_id=?", [dev_id]);

                        if (Array.isArray(params)) {
                            for (let idx = 0; idx < params.length; idx++) {
                                const p = params[idx];
                                const vis = p.is_visible ? 1 : 0;
                                const order = idx + 1;
                                let setting_id = p.id;

                                if (setting_id) {
                                    await connection.execute(`
                                        UPDATE device_settings SET parameter_name=?, mqtt_topic=?, unit=?, is_visible=?, display_order=? WHERE id=? AND category NOT IN ('config', 'jenis')
                                    `, [p.label, p.topic, p.unit, vis, order, setting_id]);
                                } else {
                                    const [sRes] = await connection.execute(`
                                        INSERT INTO device_settings (device_unique_id, parameter_name, mqtt_topic, unit, display_order, is_visible, category) 
                                        VALUES (?, ?, ?, ?, ?, ?, 'sensor')
                                    `, [dev_id, p.label, p.topic, p.unit, order, vis]);
                                    setting_id = sRes.insertId;
                                }

                                if (p.is_chart && p.chart_data) {
                                    await connection.execute(`
                                        INSERT INTO user_sensor_charts (user_id, device_unique_id, device_setting_id, chart_order, is_active, data) 
                                        VALUES (?, ?, ?, ?, 1, ?)
                                    `, [uid, dev_id, setting_id, parseInt(p.chart_order), p.chart_data]);
                                }
                            }
                        }

                        await connection.execute("DELETE FROM device_automations WHERE device_unique_id=?", [dev_id]);
                        if (Array.isArray(automations)) {
                            for (let auto of automations) {
                                await connection.execute(`
                                    INSERT INTO device_automations (device_unique_id, parameter_name, operator, threshold, send_email, send_notification) 
                                    VALUES (?, ?, ?, ?, ?, ?)
                                `, [dev_id, auto.parameter_name, auto.operator, parseFloat(auto.threshold), auto.send_email ? 1 : 0, auto.send_notification ? 1 : 0]);
                            }
                        }
                    }

                    await connection.commit();
                    connection.release();
                    return res.json({ status: true, message: is_demo ? "Profil dan lokasi berhasil diupdate (konfigurasi sensor tidak dapat diubah untuk akun demo)" : "Konfigurasi device berhasil diupdate", username });
                } catch (err) {
                    await connection.rollback();
                    connection.release();
                    return res.status(500).json({ status: false, message: "Gagal update: " + err.message });
                }
            }

            if (action === 'delete_param') {
                const { id } = req.body;
                await db.execute("DELETE FROM user_sensor_charts WHERE device_setting_id=?", [id]);
                await db.execute("DELETE FROM device_settings WHERE id=?", [id]);
                return res.json({ status: true, message: "Parameter dihapus" });
            }

            if (action === 'change_password') {
                const { user_id, new_password } = req.body;
                const hash = await bcrypt.hash(new_password, 10);
                await db.execute("UPDATE users SET password=? WHERE id=?", [hash, user_id]);
                return res.json({ status: true, message: "Password diubah" });
            }

                if (action === 'delete_user') {
                const connection = await db.getConnection();
                try {
                    await connection.beginTransaction();
                    const uid = req.body.user_id;
                    const did = req.body.device_unique_id;

                    const [uCheck] = await connection.execute("SELECT is_demo FROM users WHERE id=?", [uid]);
                    const is_demo = uCheck.length > 0 ? uCheck[0].is_demo : 0;

                    await connection.execute("DELETE FROM user_sensor_charts WHERE user_id=?", [uid]);
                    await connection.execute("DELETE FROM user_devices WHERE user_id=?", [uid]);
                    await connection.execute("DELETE FROM users WHERE id=?", [uid]);

                    // Only delete device settings if this is NOT a demo account
                    if (!is_demo) {
                        await connection.execute("DELETE FROM user_sensor_charts WHERE device_unique_id=?", [did]);
                        await connection.execute("DELETE FROM sensor_logs WHERE device_unique_id=?", [did]);
                        await connection.execute("DELETE FROM device_settings WHERE device_unique_id=?", [did]);
                        await connection.execute("DELETE FROM device_automations WHERE device_unique_id=?", [did]);
                    }

                    await connection.commit();
                    connection.release();
                    return res.json({ status: true, message: "User dihapus berhasil" });
                } catch (err) {
                    await connection.rollback();
                    connection.release();
                    return res.status(500).json({ status: false, message: "Gagal hapus user" });
                }
            }
        }
        return res.status(404).json({ status: false, message: "Endpoint tidak ditemukan" });
    } catch (e) {
        console.error(e);
        return res.status(500).json({ status: false, message: "Internal server error", error: e.message });
    }
});

module.exports = router;
