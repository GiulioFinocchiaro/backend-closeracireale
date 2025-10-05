-- Schema di base per il sistema graphic contest
-- Questo script crea le tabelle fondamentali necessarie per il funzionamento

-- Tabella schools
CREATE TABLE IF NOT EXISTS `schools` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabella permissions
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL UNIQUE,
  `display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabella roles
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `level` int NOT NULL DEFAULT 0,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '#000000',
  `school_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_school_id` (`school_id`),
  CONSTRAINT `fk_roles_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabella users
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL UNIQUE,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `school_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_school_id` (`school_id`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_users_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_users_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabella role_permissions
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_id` int NOT NULL,
  `permission_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_permission` (`role_id`, `permission_id`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_permission_id` (`permission_id`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tabella user_role
CREATE TABLE IF NOT EXISTS `user_role` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `role_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_role` (`user_id`, `role_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_role_id` (`role_id`),
  CONSTRAINT `fk_user_role_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_role_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Inserisci dati di base
-- Scuola di default
INSERT IGNORE INTO `schools` (`id`, `name`, `address`, `email`, `phone`) VALUES
(1, 'Scuola Demo', 'Via Roma 1, 00100 Roma', 'demo@scuola.it', '06-1234567');

-- Permessi di base
INSERT IGNORE INTO `permissions` (`name`, `display_name`, `description`) VALUES
('graphics.approve', 'Approvare/Disapprovare Grafiche Contest', 'Permesso per approvare o disapprovare le grafiche del contest'),
('graphics.view_all', 'Visualizzare Tutte le Grafiche Contest', 'Permesso per visualizzare tutte le grafiche, incluse quelle non approvate'),
('graphics.update', 'Modificare Grafiche Contest', 'Permesso per modificare i dati delle grafiche'),
('users.register_new_users', 'Registrare Nuovi Utenti', 'Permesso per registrare nuovi utenti nel sistema'),
('media.upload', 'Caricare Media', 'Permesso per caricare file media'),
('media.view_all', 'Visualizzare Tutti i Media', 'Permesso per visualizzare tutti i file media'),
('media.view_own_school', 'Visualizzare Media Propria Scuola', 'Permesso per visualizzare i media della propria scuola'),
('media.update_all', 'Modificare Tutti i Media', 'Permesso per modificare tutti i file media'),
('media.update_own_school', 'Modificare Media Propria Scuola', 'Permesso per modificare i media della propria scuola'),
('media.delete_all', 'Eliminare Tutti i Media', 'Permesso per eliminare tutti i file media'),
('media.delete_own_school', 'Eliminare Media Propria Scuola', 'Permesso per eliminare i media della propria scuola');

-- Ruoli di base
INSERT IGNORE INTO `roles` (`id`, `name`, `display_name`, `level`, `color`, `school_id`) VALUES
(1, 'admin', 'Amministratore', 100, '#ff0000', NULL),
(2, 'moderator', 'Moderatore', 50, '#0066cc', NULL),
(3, 'user', 'Utente Base', 10, '#008800', NULL);

-- Assegna tutti i permessi all'admin
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, p.id FROM `permissions` p;

-- Assegna permessi di base al moderator
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) 
SELECT 2, p.id FROM `permissions` p WHERE p.name IN (
  'graphics.approve', 
  'graphics.view_all', 
  'graphics.update',
  'media.view_all',
  'media.update_own_school'
);

-- Utente admin di default (password: admin123)
INSERT IGNORE INTO `users` (`id`, `email`, `password`, `name`, `school_id`) VALUES
(1, 'admin@system.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Amministratore Sistema', 1);

-- Assegna ruolo admin all'utente admin
INSERT IGNORE INTO `user_role` (`user_id`, `role_id`) VALUES (1, 1);