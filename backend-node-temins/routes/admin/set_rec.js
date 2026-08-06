const express = require('express');
const router = express.Router();
const verifyToken = require('../../middleware/auth');
const { handleSetRec } = require('../../controllers/admin/set_rec');

router.all('/', verifyToken, handleSetRec);

module.exports = router;
