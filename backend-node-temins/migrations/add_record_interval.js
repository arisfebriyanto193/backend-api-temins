const db = require('../config/db');

async function migrate() {
    console.log("Memulai migrasi: Menambahkan kolom 'record_interval' ke tabel 'user_devices'...");
    try {
        const [columns] = await db.execute(`
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'user_devices' 
            AND COLUMN_NAME = 'record_interval'
        `);

        if (columns.length > 0) {
            console.log("Kolom 'record_interval' sudah ada. Migrasi dilewati.");
        } else {
            await db.execute('ALTER TABLE user_devices ADD COLUMN record_interval INT NOT NULL DEFAULT 5 AFTER timezone');
            console.log("✅ Migrasi berhasil: Kolom 'record_interval' telah ditambahkan dengan default 5 menit.");
        }
    } catch (err) {
        console.error("❌ Migrasi gagal:", err.message);
    } finally {
        process.exit();
    }
}

migrate();
