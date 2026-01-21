CREATE TABLE `categories` (
                              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                              `user_id` int(10) unsigned NOT NULL,
                              `name` varchar(100) NOT NULL,
                              `color` varchar(7) DEFAULT NULL,
                              `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                              `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                              PRIMARY KEY (`id`),
                              UNIQUE KEY `uq_user_name` (`user_id`,`name`),
                              KEY `idx_user_id` (`user_id`),
                              CONSTRAINT `fk_categories_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci