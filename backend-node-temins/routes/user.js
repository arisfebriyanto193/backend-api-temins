const express = require('express');
const router = express.Router();
const verifyToken = require('../middleware/auth');
const { getUserInfo } = require('../controllers/user/info');
const { getMultiDevices, getMultiAwsDs } = require('../controllers/user/multi');

router.get('/timeout.php', (req, res) => {
    // Daftar device ID yang menggunakan timeout panjang (contoh: 3 menit)
    const device_id_long_timeout = ["0035"];

    return res.json({
        status: true,
        data: device_id_long_timeout
    });
});

router.get('/info.php', verifyToken, getUserInfo);
router.get('/multi/devices.php', verifyToken, getMultiDevices);
router.get('/multi/aws/ds.php', verifyToken, getMultiAwsDs);

module.exports = router;
