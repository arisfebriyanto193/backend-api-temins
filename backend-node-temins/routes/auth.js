const express = require('express');
const router = express.Router();
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const db = require('../config/db');

router.post('/login.php', async (req, res) => {
    try {
        const loginType = req.query.login || 'user';
        const { username, password } = req.body;

        if (!username || !password) {
            return res.json({ status: false, message: "Username dan Password wajib diisi" });
        }

        let user = null;
        let role = '';
        let device_type = null;
        let redirect_path = "users/";

        if (loginType === 'instansi') {
            const [rows] = await db.execute("SELECT id, username, password FROM instansi WHERE username = ? LIMIT 1", [username]);
            if (rows.length !== 1) {
                return res.json({ status: false, message: "Akun Instansi tidak ditemukan!" });
            }
            user = rows[0];

            const isMatch = await bcrypt.compare(password, user.password);
            if (!isMatch) {
                return res.json({ status: false, message: "Password Instansi salah!" });
            }

            role = 'instansi';
            device_type = 'instansi';
            redirect_path = "user/instansi/";
        } else {
            const [rows] = await db.execute("SELECT id, username, password, role FROM users WHERE username = ? LIMIT 1", [username]);
            if (rows.length !== 1) {
                return res.json({ status: false, message: "Username tidak ditemukan!" });
            }
            user = rows[0];

            const isMatch = await bcrypt.compare(password, user.password);
            if (!isMatch) {
                return res.json({ status: false, message: "Password salah!" });
            }

            role = user.role;

            if (role === "admin") {
                redirect_path = "sett/";
                device_type = 'admin';
            } else {
                const uid = user.id;
                const [devRows] = await db.execute("SELECT device_type FROM user_devices WHERE user_id = ? LIMIT 1", [uid]);
                if (devRows.length > 0) {
                    const device = devRows[0];
                    device_type = device.device_type;
                    
                    const devTypeLower = (device_type || '').toLowerCase();
                    if (devTypeLower === "awlr") {
                        redirect_path = "user/awlr/";
                    } else if (devTypeLower === "aws") {
                        redirect_path = "user/aws/";
                    } else if (devTypeLower === "smart_farm") {
                        redirect_path = "user/sf/";
                    }
                }
            }
        }

        const payload = {
            uid: user.id,
            username: user.username,
            role: role,
            device_type: device_type,
            exp: Math.floor(Date.now() / 1000) + (60 * 60 * 24 * 7) // 7 days
        };

        const token = jwt.sign(payload, process.env.JWT_SECRET || 'rahasia_token_jwt_temins');

        return res.json({
            status: true,
            message: "Login berhasil sebagai " + (role === 'instansi' ? 'Instansi' : 'User'),
            token: token,
            data: {
                user_id: user.id,
                username: user.username,
                role: role,
                device_type: device_type,
                redirect_target: redirect_path
            }
        });
    } catch (error) {
        console.error(error);
        return res.json({ status: false, message: "Internal Server Error" });
    }
});

module.exports = router;
