const express = require('express');
const router = express.Router();
const verifyToken = require('../../middleware/auth');
const { handleInstansi } = require('../../controllers/admin/instansi');

router.all('/', verifyToken, handleInstansi);

module.exports = router;
