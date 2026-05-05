CREATE TABLE `users` (
                         `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                         `firstName` varchar(100) NOT NULL,
                         `lastName` varchar(100) NOT NULL,
                         `email` varchar(255) NOT NULL,
                         `password` varchar(255) NOT NULL,
                         `isStudent` tinyint(1) NOT NULL DEFAULT 0,
                         `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                         `role` varchar(50) NOT NULL DEFAULT 'user',
                         PRIMARY KEY (`id`),
                         UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci