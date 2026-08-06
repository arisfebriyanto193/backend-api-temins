const express = require('express');
const router = express.Router();
const verifyToken = require('../../middleware/auth');

// Controllers
const { getDashboard } = require('../../controllers/awlr/dashboard');
const { getHistory } = require('../../controllers/awlr/history');
const { getPower } = require('../../controllers/awlr/power');

// Routes
router.get('/ds.php', verifyToken, getDashboard);
router.get('/history.php', verifyToken, getHistory);
router.get('/power.php', verifyToken, getPower);

module.exports = router;
