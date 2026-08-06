const express = require('express');
const router = express.Router();
const verifyToken = require('../../middleware/auth');

// Controllers
const { getDashboard } = require('../../controllers/aws/dashboard');
const { getPower, getPowerMobile } = require('../../controllers/aws/power');
const { getHistory, getHistoryMobile } = require('../../controllers/aws/history');
const { getCuaca } = require('../../controllers/aws/cuaca');
const { getWind } = require('../../controllers/aws/wind');

// Routes
router.get('/ds.php', verifyToken, getDashboard);
router.get('/power.php', verifyToken, getPower);
router.get('/power-mobile.php', verifyToken, getPowerMobile);
router.get('/history.php', verifyToken, getHistory);
router.get('/history-mobile.php', verifyToken, getHistoryMobile);
router.get('/cuaca.php', verifyToken, getCuaca);
router.get('/wind.php', verifyToken, getWind);

module.exports = router;
