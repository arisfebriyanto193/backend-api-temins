const express = require('express');
const router = express.Router();
const verifyToken = require('../../middleware/auth');

// Controllers
const { getDashboard } = require('../../controllers/awlr/dashboard');
const { getHistory, getHistoryMobile } = require('../../controllers/awlr/history');
const { getPower, getPowerMobile } = require('../../controllers/awlr/power');

// Routes
router.get('/ds.php', verifyToken, getDashboard);
router.get('/history.php', verifyToken, getHistory);
router.get('/history-mobile.php', verifyToken, getHistoryMobile);
router.get('/power.php', verifyToken, getPower);
router.get('/power-mobile.php', verifyToken, getPowerMobile);

module.exports = router;
