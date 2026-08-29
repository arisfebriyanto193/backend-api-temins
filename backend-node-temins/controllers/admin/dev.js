const db = require('../../config/db');

// Helper to insert params
async function insertParams(connection, templateId, params) {
    if (!params || !Array.isArray(params)) return;
    
    for (let p of params) {
        if (p.label && p.key) {
            await connection.execute(
                "INSERT INTO template_params (template_id, param_name, mqtt_suffix, unit, data) VALUES (?, ?, ?, ?, ?)",
                [templateId, p.label, p.key, p.unit || '', p.data || '']
            );
        }
    }
}

const handleDevTemplates = async (req, res) => {
    try {
        // Cek Role Admin
        const user = req.user;
        let isAdmin = false;
        if (user && user.role === 'admin') {
            isAdmin = true;
        } else {
            // Fallback cek DB
            const uid = user.uid || user.id;
            const [rows] = await db.execute("SELECT role FROM users WHERE id=?", [uid]);
            if (rows.length > 0 && rows[0].role === 'admin') {
                isAdmin = true;
            }
        }

        if (!isAdmin) {
            return res.status(403).json({ status: false, message: "Access Denied" });
        }

        const method = req.method;
        const action = (req.query && req.query.action) || (req.body && req.body.action) || '';

        // A. GET ALL TEMPLATES
        if (method === 'GET') {
            const [rows] = await db.execute(`
                SELECT t.id, t.template_code, t.template_name, p.param_name, p.mqtt_suffix, p.unit, p.data 
                FROM device_templates t 
                LEFT JOIN template_params p ON t.id = p.template_id 
                ORDER BY t.id DESC, p.id ASC
            `);

            const templatesMap = {};
            for (let row of rows) {
                const tid = row.id;
                if (!templatesMap[tid]) {
                    templatesMap[tid] = {
                        id: row.id,
                        code: row.template_code,
                        name: row.template_name,
                        params: []
                    };
                }
                if (row.param_name) {
                    templatesMap[tid].params.push({
                        label: row.param_name,
                        key: row.mqtt_suffix,
                        unit: row.unit,
                        data: row.data
                    });
                }
            }

            return res.json({ status: true, data: Object.values(templatesMap) });
        }

        // B. POST METHODS (CREATE, UPDATE, DELETE)
        if (method === 'POST') {
            const { code, name, params, id } = req.body;

            // 1. CREATE TEMPLATE
            if (action === 'create') {
                const [cek] = await db.execute("SELECT id FROM device_templates WHERE template_code = ?", [code]);
                if (cek.length > 0) {
                    return res.json({ status: false, message: "Kode Template sudah ada!" });
                }

                const [result] = await db.execute(
                    "INSERT INTO device_templates (template_code, template_name) VALUES (?, ?)",
                    [code, name]
                );
                const t_id = result.insertId;
                await insertParams(db, t_id, params);
                return res.json({ status: true, message: "Template berhasil dibuat" });
            }

            // 2. UPDATE TEMPLATE
            if (action === 'update') {
                await db.execute("UPDATE device_templates SET template_code=?, template_name=? WHERE id=?", [code, name, id]);
                await db.execute("DELETE FROM template_params WHERE template_id=?", [id]);
                await insertParams(db, id, params);
                return res.json({ status: true, message: "Template diperbarui" });
            }

            // 3. DELETE TEMPLATE
            if (action === 'delete') {
                await db.execute("DELETE FROM template_params WHERE template_id=?", [id]);
                await db.execute("DELETE FROM device_templates WHERE id=?", [id]);
                return res.json({ status: true, message: "Template dihapus" });
            }
            
            return res.status(400).json({ status: false, message: "Invalid action" });
        }

        return res.status(405).json({ status: false, message: "Method Not Allowed" });

    } catch (error) {
        console.error("Admin Dev Error:", error);
        return res.status(500).json({ status: false, message: "Internal Server Error" });
    }
};

module.exports = { handleDevTemplates };
