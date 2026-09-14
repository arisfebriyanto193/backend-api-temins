const fs = require('fs');
const path = require('path');

const dataFile = path.join(__dirname, '../../../rekam-data/py/1.json');

const handleSetRec = async (req, res) => {
    try {
        const user = req.user;
        let isAdmin = false;
        
        if (user && user.role === 'admin') {
            isAdmin = true;
        } else {
            const db = require('../../config/db');
            const uid = user.uid || user.id;
            const [rows] = await db.execute("SELECT role FROM users WHERE id=?", [uid]);
            if (rows.length > 0 && rows[0].role === 'admin') {
                isAdmin = true;
            }
        }

        if (!isAdmin) {
            return res.status(403).json({ status: false, message: "Access Denied" });
        }

        const dir = path.dirname(dataFile);
        if (!fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
        }

        if (!fs.existsSync(dataFile)) {
            fs.writeFileSync(dataFile, JSON.stringify({ device_type: {} }, null, 4));
        }

        let data;
        try {
            data = JSON.parse(fs.readFileSync(dataFile, 'utf8'));
        } catch (err) {
            data = { device_type: {} };
        }
        
        if (!data.device_type || Array.isArray(data.device_type)) {
            data.device_type = {};
        }

        function saveData(dataObj) {
            fs.writeFileSync(dataFile, JSON.stringify(dataObj, null, 4));
        }

        const method = req.method;

        if (method === 'GET') {
            return res.json({ status: true, data: data });
        }

        if (method === 'POST') {
            const action = req.body.action || '';
            let message = '';
            let status = true;

            switch (action) {
                case 'add_device_type': {
                    const type = (req.body.device_type || '').trim();
                    if (type && !data.device_type[type]) {
                        data.device_type[type] = { def_topic: [], devices: [] };
                        message = `Tipe perangkat '${type}' berhasil dibuat.`;
                    } else {
                        status = false;
                        message = "Gagal: Nama kosong atau sudah ada.";
                    }
                    break;
                }

                case 'update_def_topic': {
                    const type = req.body.device_type;
                    let topics = req.body.def_topic;
                    if (!Array.isArray(topics)) {
                        topics = topics ? topics.split(',').map(t => t.trim()).filter(t => t) : [];
                    }
                    
                    if (data.device_type[type]) {
                        data.device_type[type].def_topic = topics;
                        message = `Default topik untuk '${type}' diperbarui.`;
                    }
                    break;
                }

                case 'add_device': {
                    const type = req.body.device_type;
                    const devId = (req.body.dev_id || '').trim();
                    
                    if (data.device_type[type]) {
                        const exists = data.device_type[type].devices.some(d => d.dev_id === devId);

                        if (devId && !exists) {
                            data.device_type[type].devices.push({
                                dev_id: devId,
                                use_default: true,
                                topic: []
                            });
                            message = `Device '${devId}' ditambahkan.`;
                        } else {
                            status = false;
                            message = "Gagal: Device ID kosong atau sudah ada.";
                        }
                    }
                    break;
                }

                case 'update_device': {
                    const type = req.body.device_type;
                    const index = parseInt(req.body.index);
                    const newDevId = (req.body.new_dev_id || '').trim();
                    const useDefault = !!req.body.use_default;

                    if (data.device_type[type] && data.device_type[type].devices[index]) {
                        if (newDevId) {
                            data.device_type[type].devices[index].dev_id = newDevId;
                        }

                        if (useDefault) {
                            data.device_type[type].devices[index].topic = [];
                            data.device_type[type].devices[index].use_default = true;
                        } else {
                            let rawTopic = req.body.topic;
                            let topics = Array.isArray(rawTopic) ? rawTopic : 
                                         (rawTopic ? rawTopic.split(',').map(t => t.trim()).filter(t => t) : []);
                            
                            data.device_type[type].devices[index].use_default = false;
                            data.device_type[type].devices[index].topic = topics;
                        }
                        message = "Konfigurasi device diperbarui.";
                    }
                    break;
                }

                case 'delete_device': {
                    const type = req.body.device_type;
                    const index = parseInt(req.body.index);
                    if (data.device_type[type] && data.device_type[type].devices[index]) {
                        data.device_type[type].devices.splice(index, 1);
                        message = "Device berhasil dihapus.";
                    }
                    break;
                }
                    
                case 'delete_device_type': {
                    const type = req.body.device_type;
                    if (data.device_type[type]) {
                        delete data.device_type[type];
                        message = `Tipe perangkat '${type}' dihapus.`;
                    }
                    break;
                }
                    
                default:
                    status = false;
                    message = "Action tidak valid.";
            }

            if (status) {
                saveData(data);
            }

            return res.json({ status, message });
        }

        return res.status(405).json({ status: false, message: "Method Not Allowed" });

    } catch (error) {
        console.error("Admin Set Rec Error:", error);
        return res.status(500).json({ status: false, message: "Internal Server Error" });
    }
};

module.exports = { handleSetRec };
