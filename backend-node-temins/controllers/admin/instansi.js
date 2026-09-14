const db = require('../../config/db');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');

const handleInstansi = async (req, res) => {
    try {
        const user = req.user;
        let isAdmin = false;
        
        if (user && user.role === 'admin') {
            isAdmin = true;
        } else {
            const uid = user.uid || user.id;
            const [rows] = await db.execute("SELECT role FROM users WHERE id=?", [uid]);
            if (rows.length > 0 && rows[0].role === 'admin') {
                isAdmin = true;
            }
        }

        if (!isAdmin) {
            return res.status(403).json({ status: false, message: "Access Denied (Admin Only)" });
        }

        const method = req.method;
        const action = req.query.action || (req.body && req.body.action) || '';

        switch (action) {
            case 'get_all_users': {
                const [rows] = await db.execute("SELECT id, username, email FROM users WHERE instansi_id IS NULL");
                return res.json(rows);
            }
            case 'assign_user': {
                const { instansi_id, user_id } = req.body;
                await db.execute("UPDATE users SET instansi_id = ? WHERE id = ?", [instansi_id, user_id]);
                return res.json({ status: true, message: "User berhasil ditambahkan ke instansi" });
            }
            case 'remove_user_from_instansi': {
                const { id } = req.query;
                await db.execute("UPDATE users SET instansi_id = NULL WHERE id = ?", [id]);
                return res.json({ status: true });
            }
            case 'get_instansi': {
                const [rows] = await db.execute("SELECT id, name, username FROM instansi");
                return res.json(rows);
            }
            case 'add_instansi': {
                const { username, password, name } = req.body;
                if (!name || !username || !password) {
                    return res.json({ status: false, message: "Semua field harus diisi" });
                }
                const hashed = await bcrypt.hash(password, 10);
                await db.execute("INSERT INTO instansi (name, username, password) VALUES (?, ?, ?)", [name, username, hashed]);
                return res.json({ status: true });
            }
            case 'update_instansi': {
                const { id, name, username, password } = req.body;
                if (password) {
                    const hashed = await bcrypt.hash(password, 10);
                    await db.execute("UPDATE instansi SET name = ?, username = ?, password = ? WHERE id = ?", [name, username, hashed, id]);
                } else {
                    await db.execute("UPDATE instansi SET name = ?, username = ? WHERE id = ?", [name, username, id]);
                }
                return res.json({ status: true });
            }
            case 'delete_instansi': {
                const { id } = req.query;
                await db.execute("DELETE FROM instansi WHERE id = ?", [id]);
                return res.json({ status: true });
            }
            case 'get_instansi_token': {
                const instansi_id = req.query.instansi_id;
                if (!instansi_id) {
                    return res.json({ status: false, message: "instansi_id diperlukan" });
                }
                const [rows] = await db.execute("SELECT id, name, username FROM instansi WHERE id = ?", [instansi_id]);
                if (rows.length === 0) {
                    return res.json({ status: false, message: "Instansi tidak ditemukan" });
                }
                const inst = rows[0];
                
                const payload = {
                    id: inst.id,
                    uid: inst.id,
                    username: inst.username,
                    instansi_id: inst.id,
                    role: 'instansi'
                };
                
                const token = jwt.sign(payload, process.env.JWT_SECRET || 'rahasia_token_jwt_temins', { expiresIn: '1y' });
                
                return res.json({
                    status: true,
                    token: token,
                    instansi_id: inst.id,
                    instansi_name: inst.name,
                    username: inst.username
                });
            }
            case 'get_users_by_instansi': {
                const instansi_id = req.query.instansi_id;
                const [instRows] = await db.execute("SELECT name FROM instansi WHERE id = ?", [instansi_id]);
                const instName = instRows.length > 0 ? instRows[0].name : '';
                
                const [users] = await db.execute(
                    "SELECT id, username as device_unique_id, username as device_name, email as location FROM users WHERE instansi_id = ?",
                    [instansi_id]
                );
                
                return res.json({
                    status: true,
                    institution: instName,
                    devices: users
                });
            }
            case 'add_user': {
                const { username, password, email, instansi_id } = req.body;
                const hashed = await bcrypt.hash(password, 10);
                await db.execute(
                    "INSERT INTO users (username, password, role, email, instansi_id) VALUES (?, ?, 'user', ?, ?)",
                    [username, hashed, email, instansi_id]
                );
                return res.json({ status: true });
            }
            case 'delete_user': {
                const { id } = req.query;
                await db.execute("DELETE FROM users WHERE id = ?", [id]);
                return res.json({ status: true });
            }
            default:
                return res.status(400).json({ status: false, message: "Invalid action" });
        }
    } catch (error) {
        console.error("Admin Instansi Error:", error);
        return res.status(500).json({ status: false, message: "Internal Server Error" });
    }
};

module.exports = { handleInstansi };
