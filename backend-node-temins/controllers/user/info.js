const db = require('../../config/db');

const getUserInfo = async (req, res) => {
    try {
        const user_id = req.user.uid || req.user.id;

        const sql = `
            SELECT 
                id,
                user_id,
                device_name,
                owner_name,
                device_type,
                device_unique_id,
                city,
                location,
                internet_no,
                pic_contact,
                pic,
                timezone,
                status
            FROM user_devices
            WHERE user_id = ?
        `;

        const [rows] = await db.execute(sql, [user_id]);

        if (rows.length === 0) {
            return res.json({
                status: false,
                message: "No device found"
            });
        }

        return res.json({
            status: true,
            user_id: user_id,
            total: rows.length,
            data: rows
        });

    } catch (error) {
        console.error(error);
        return res.status(500).json({ status: false, message: "Internal Server Error" });
    }
};

module.exports = { getUserInfo };
