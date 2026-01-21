CREATE TABLE `tasks` (
                         `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                         `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                         `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                         `status` enum('pending','in_progress','completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
                         `priority` int(11) NOT NULL DEFAULT 2,
                         `user_id` int(10) unsigned NOT NULL,
                         `deadline` datetime DEFAULT NULL,
                         `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                         `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                         `category_id` int(10) unsigned DEFAULT NULL,
                         PRIMARY KEY (`id`),
                         KEY `fk_tasks_user` (`user_id`),
                         KEY `idx_category_id` (`category_id`),
                         CONSTRAINT `fk_tasks_category_v2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                         CONSTRAINT `fk_tasks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=113 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci