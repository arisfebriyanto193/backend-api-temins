const express = require('express');
const router = express.Router();
const verifyToken = require('../../middleware/auth');

// Controllers
const { getDashboard } = require('../../controllers/sf/dashboard');
const { getHistory } = require('../../controllers/sf/history');
const { getPower } = require('../../controllers/sf/power');

// Routes
router.get('/ds.php', verifyToken, getDashboard);
router.get('/history.php', verifyToken, getHistory);
router.get('/power.php', verifyToken, getPower);

module.exports = router;
