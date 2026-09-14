const db = require('../config/db');

async function migrate() {
    console.log("Memulai migrasi: Menambahkan kolom 'is_demo' ke tabel 'users'...");
    try {
        const [columns] = await db.execute(`
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'users' 
            AND COLUMN_NAME = 'is_demo'
        `);

        if (columns.length > 0) {
            console.log("Kolom 'is_demo' sudah ada. Migrasi dilewati.");
        } else {
            await db.execute('ALTER TABLE users ADD COLUMN is_demo TINYINT(1) DEFAULT 0');
            console.log("Migrasi berhasil: Kolom 'is_demo' telah ditambahkan.");
        }
    } catch (err) {
        console.error("Migrasi gagal:", err.message);
    } finally {
        process.exit();
    }
}

migrate();
