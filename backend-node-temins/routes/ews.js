const express = require('express');
const router = express.Router();
const { ewsControl } = require('../controllers/ews');

// POST /api/v1/ews
router.post('/', ewsControl);

module.exports = router;
