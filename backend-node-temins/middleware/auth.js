const jwt = require('jsonwebtoken');

function verifyToken(req, res, next) {
    const authHeader = req.headers['authorization'];
    if (!authHeader) {
        return res.status(401).json({ status: false, message: "Token tidak ditemukan di Header" });
    }

    const token = authHeader.split(' ')[1];
    if (!token) {
        return res.status(401).json({ status: false, message: "Format token tidak valid" });
    }

    try {
        const decoded = jwt.verify(token, process.env.JWT_SECRET || 'rahasia_token_jwt_temins');
        req.user = decoded;
        next();
    } catch (err) {
        return res.status(401).json({ status: false, message: "Token tidak valid atau expired" });
    }
}

module.exports = verifyToken;
