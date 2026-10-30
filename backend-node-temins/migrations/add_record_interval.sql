-- Migrasi: Tambah kolom record_interval ke tabel user_devices
-- Default: 5 (menit)
-- Idempotent: menambahkan kolom jika belum ada tanpa mengubah/menghapus baris data yang ada.

ALTER TABLE `user_devices` 
ADD COLUMN IF NOT EXISTS `record_interval` INT NOT NULL DEFAULT 5 AFTER `timezone`;
