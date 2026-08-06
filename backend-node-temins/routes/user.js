const express = require('express');
const router = express.Router();

router.get('/timeout.php', (req, res) => {
    // Daftar device ID yang menggunakan timeout panjang (contoh: 3 menit)
    const device_id_long_timeout = ["0035"];

    return res.json({
        status: true,
        data: device_id_long_timeout
    });
});

module.exports = router;
