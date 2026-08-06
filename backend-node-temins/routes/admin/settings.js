const express = require('express');
const router = express.Router();
const db = require('../../config/db');
const verifyToken = require('../../middleware/auth');
const bcrypt = require('bcryptjs');
const nodemailer = require('nodemailer');

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

router.all('/', async (req, res) => {
    try {
        const method = req.method;
        const action = req.query.action || req.body?.action || '';

        if (action === 'admin-users') {
            if (method === 'PUT') {
                const { id, type, password, username, email } = req.body;
                if (!id) return res.status(400).json({ status: false, message: "ID wajib diisi" });
                const [cek] = await db.execute("SELECT id FROM users WHERE id=? AND role='admin'", [id]);
                if (cek.length === 0) return res.status(404).json({ status: false, message: "Admin tidak ditemukan" });

                if (type === 'change_password') {
                    if (!password) return res.status(400).json({ status: false, message: "Password baru wajib diisi" });
                    const hash = await bcrypt.hash(password, 10);
                    await db.execute("UPDATE users SET password=? WHERE id=?", [hash, id]);
                    return res.json({ status: true, message: "Password admin berhasil diubah" });
                }

                if (type === 'edit_profile') {
                    if (!username) return res.status(400).json({ status: false, message: "Username wajib diisi" });
                    const [cekUser] = await db.execute("SELECT id FROM users WHERE username=? AND id!=?", [username, id]);
                    if (cekUser.length > 0) return res.status(409).json({ status: false, message: "Username sudah digunakan" });
                    await db.execute("UPDATE users SET username=?, email=? WHERE id=?", [username, email || '', id]);
                    return res.json({ status: true, message: "Profil admin berhasil diperbarui" });
                }
                return res.status(400).json({ status: false, message: "Tipe aksi tidak dikenal" });
            }

            if (method === 'GET') {
                const [rows] = await db.execute("SELECT id, username, email, role, diBuat FROM users WHERE role='admin'");
                return res.json({ status: true, data: rows });
            }

            if (method === 'POST') {
                const { username, password, email } = req.body;
                if (!username || !password) return res.status(400).json({ status: false, message: "Username dan password wajib diisi" });
                const [cek] = await db.execute("SELECT id FROM users WHERE username=?", [username]);
                if (cek.length > 0) return res.status(409).json({ status: false, message: "Username sudah terdaftar" });
                const hash = await bcrypt.hash(password, 10);
                await db.execute("INSERT INTO users (username, password, role, email, diBuat) VALUES (?, ?, 'admin', ?, NOW())", [username, hash, email || '']);
                return res.json({ status: true, message: "Admin berhasil ditambahkan" });
            }

            if (method === 'DELETE') {
                const { id } = req.body;
                if (!id) return res.status(400).json({ status: false, message: "ID admin wajib diisi" });
                const [cek] = await db.execute("SELECT id FROM users WHERE id=? AND role='admin'", [id]);
                if (cek.length === 0) return res.status(404).json({ status: false, message: "Admin tidak ditemukan" });
                const [countAdmin] = await db.execute("SELECT COUNT(*) AS total FROM users WHERE role='admin'");
                if (countAdmin[0].total <= 1) return res.status(403).json({ status: false, message: "Admin tidak bisa dihapus karena hanya tersisa satu admin" });
                await db.execute("DELETE FROM users WHERE id=? AND role='admin'", [id]);
                return res.json({ status: true, message: "Admin berhasil dihapus" });
            }
        }

        if (action === 'app-config') {
            if (method === 'GET') {
                const [rows] = await db.execute("SELECT status, versi, url FROM app_configs ORDER BY id ASC LIMIT 1");
                if (rows.length === 0) return res.status(404).json({ status: false, message: "Konfigurasi belum tersedia" });
                return res.json({ status: true, data: rows[0] });
            }
            if (method === 'POST') {
                const { status, versi, url } = req.body;
                if (status == null || !versi || !url) return res.status(400).json({ status: false, message: "Field status, versi, dan url wajib diisi" });
                const [cek] = await db.execute("SELECT id FROM app_configs LIMIT 1");
                if (cek.length === 0) {
                    await db.execute("INSERT INTO app_configs (status, versi, url) VALUES (?, ?, ?)", [status, versi, url]);
                } else {
                    await db.execute("UPDATE app_configs SET status=?, versi=?, url=? WHERE id=?", [status, versi, url, cek[0].id]);
                }
                return res.json({ status: true, message: "Konfigurasi berhasil diperbarui", data: { status, versi, url } });
            }
        }

        if (action === 'push-users' && method === 'GET') {
            const [rows] = await db.execute(`
                SELECT u.id AS user_id, u.username, COUNT(upt.id) AS device_count
                FROM users u
                INNER JOIN user_push_tokens upt ON upt.user_id = u.id
                GROUP BY u.id, u.username
                ORDER BY u.username ASC
            `);
            return res.json({ status: true, data: rows.map(r => ({ ...r, user_id: Number(r.user_id), device_count: Number(r.device_count) })) });
        }

        if (action === 'send-notif' && method === 'POST') {
            const { title, message, user_ids } = req.body;
            if (!title || !message) return res.status(400).json({ status: false, message: "title dan message wajib diisi" });
            
            let targets = [];
            if (user_ids === 'all' || (Array.isArray(user_ids) && user_ids.length === 0)) {
                const [rows] = await db.execute("SELECT DISTINCT user_id FROM user_push_tokens");
                targets = rows.map(r => r.user_id);
            } else {
                targets = Array.isArray(user_ids) ? user_ids.map(Number) : [Number(user_ids)];
            }
            if (targets.length === 0) return res.json({ status: false, message: "Tidak ada user dengan push token terdaftar" });

            let results = [];
            for (let uid of targets) {
                try {
                    const resp = await fetch('https://be-data.dash.temins.id/send/notif', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({
                            user_id: uid, title, message, data: { screen: "detail", id: uid, status: "manual" }
                        })
                    });
                    const data = await resp.json().catch(() => null);
                    results.push({ user_id: uid, http_code: resp.status, ok: resp.ok, response: data, error: null });
                } catch (e) {
                    results.push({ user_id: uid, http_code: 0, ok: false, response: null, error: e.message });
                }
            }
            const successCount = results.filter(r => r.ok).length;
            return res.json({ status: true, message: `Notifikasi dikirim ke ${successCount}/${targets.length} user`, results });
        }

        if (action === 'email-config') {
            if (method === 'GET') {
                const [rows] = await db.execute("SELECT id, email, app_pass FROM email_configs ORDER BY id ASC LIMIT 1");
                if (rows.length === 0) return res.json({ status: true, data: { email: "", app_pass: "" } });
                const row = rows[0];
                const masked = row.app_pass.length > 4 ? row.app_pass.substring(0,4) + '*'.repeat(row.app_pass.length-4) : '*'.repeat(row.app_pass.length);
                return res.json({ status: true, data: { id: row.id, email: row.email, app_pass: row.app_pass, app_pass_masked: masked } });
            }
            if (method === 'POST') {
                const type = req.body.type || 'update';
                if (type === 'update') {
                    const { email, app_pass } = req.body;
                    if (!email || !app_pass) return res.status(400).json({ status: false, message: "Email dan app password wajib diisi" });
                    const [cek] = await db.execute("SELECT id FROM email_configs LIMIT 1");
                    if (cek.length === 0) {
                        await db.execute("INSERT INTO email_configs (email, app_pass) VALUES (?, ?)", [email, app_pass]);
                    } else {
                        await db.execute("UPDATE email_configs SET email=?, app_pass=? WHERE id=?", [email, app_pass, cek[0].id]);
                    }
                    return res.json({ status: true, message: "Konfigurasi email berhasil disimpan" });
                }
                if (type === 'test') {
                    const to = req.body.to;
                    if (!to) return res.status(400).json({ status: false, message: "Email tujuan wajib diisi" });
                    const [rows] = await db.execute("SELECT email, app_pass FROM email_configs LIMIT 1");
                    if (rows.length === 0) return res.status(400).json({ status: false, message: "Konfigurasi email belum diatur" });
                    
                    const transporter = nodemailer.createTransport({
                        host: 'smtp.gmail.com',
                        port: 587,
                        secure: false,
                        auth: { user: rows[0].email, pass: rows[0].app_pass }
                    });

                    try {
                        await transporter.sendMail({
                            from: `"Temins IoT" <${rows[0].email}>`,
                            to: to,
                            subject: "[TEST] Email dari Temins IoT Admin",
                            html: `<html><body style='font-family:sans-serif;padding:20px'>
                                   <h2 style='color:#4f46e5'>✅ Test Email Berhasil</h2>
                                   <p>Email ini dikirim dari sistem admin Temins IoT sebagai uji coba konfigurasi SMTP (Node.js).</p>
                                   <p><strong>Waktu:</strong> ${new Date().toLocaleString()}</p>
                                   <p><strong>From:</strong> ${rows[0].email}</p>
                                   <hr><p style='color:#888;font-size:12px'>Jangan balas email ini.</p>
                                   </body></html>`
                        });
                        return res.json({ status: true, message: `Test email berhasil dikirim ke ${to}` });
                    } catch (e) {
                        return res.status(500).json({ status: false, message: `Gagal kirim email: ${e.message}` });
                    }
                }
            }
        }

        return res.status(404).json({ status: false, message: "Endpoint tidak ditemukan atau aksi tidak valid" });
    } catch (e) {
        console.error(e);
        return res.status(500).json({ status: false, message: "Internal server error", error: e.message });
    }
});

module.exports = router;
