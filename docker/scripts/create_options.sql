CREATE TABLE `options` (
                           `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                           `user_id` int(10) unsigned NOT NULL,
                           `language` enum('SK','EN') NOT NULL DEFAULT 'SK',
                           `theme` enum('light','dark') NOT NULL DEFAULT 'light',
                           `task_filter` enum('all','pending','in_progress','completed') NOT NULL DEFAULT 'all',
                           `task_sort` enum('none','priority_asc','priority_desc','title_asc','title_desc','deadline_asc','deadline_desc') NOT NULL DEFAULT 'none',
                           `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                           `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                           PRIMARY KEY (`id`),
                           UNIQUE KEY `uq_options_user` (`user_id`),
                           CONSTRAINT `fk_options_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci