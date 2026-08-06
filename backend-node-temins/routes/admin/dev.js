const express = require('express');
const router = express.Router();
const verifyToken = require('../../middleware/auth');
const { handleDevTemplates } = require('../../controllers/admin/dev');

router.all('/', verifyToken, handleDevTemplates);

module.exports = router;
