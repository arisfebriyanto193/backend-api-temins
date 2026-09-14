const express = require('express');
const router = express.Router();
const multer = require('multer');
const path = require('path');
const { ewsControl, uploadAudioControl } = require('../controllers/ews');

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

router.post('/', ewsControl);

router.post('/upload-audio', upload.single('audio'), uploadAudioControl);

module.exports = router;
