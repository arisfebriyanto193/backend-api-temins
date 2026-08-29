const express = require('express');
const router = express.Router();
const multer = require('multer');
const path = require('path');
const { ewsControl, uploadAudioControl } = require('../controllers/ews');

// Konfigurasi Multer
const storage = multer.diskStorage({
  destination: function (req, file, cb) {
    cb(null, 'uploads/')
  },
  filename: function (req, file, cb) {
    const uniqueSuffix = Date.now() + '-' + Math.round(Math.random() * 1E9)
    cb(null, file.fieldname + '-' + uniqueSuffix + path.extname(file.originalname))
  }
})

const upload = multer({ storage: storage })

// POST /api/v1/ews
router.post('/', ewsControl);

// POST /api/v1/ews/upload-audio
router.post('/upload-audio', upload.single('audio'), uploadAudioControl);

module.exports = router;
