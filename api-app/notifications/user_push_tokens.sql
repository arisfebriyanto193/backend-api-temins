-- Tabel untuk menyimpan multi-device token user Expo Push Notifications
CREATE TABLE IF NOT EXISTS `user_push_tokens` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `expo_push_token` varchar(255) NOT NULL,
    `device_name` varchar(100) DEFAULT NULL,
    `device_id` varchar(255) NOT NULL,
    `created_at` datetime DEFAULT current_timestamp(),
    `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `device_id_unique` (`device_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
