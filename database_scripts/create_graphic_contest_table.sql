-- Script per creare la tabella graphic_contest
-- Eseguire questo script sul database MySQL

CREATE TABLE IF NOT EXISTS `graphic_contest` (
  `id` int NOT NULL AUTO_INCREMENT,
  `school_id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `uploader_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phone_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `class` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` tinyint NOT NULL DEFAULT '0',
  `likes` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_school_id` (`school_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Commenti sui campi
ALTER TABLE `graphic_contest` 
COMMENT = 'Tabella per gestire il contest grafico delle scuole';

-- Inserisci permessi necessari nella tabella permissions (se non esistono già)
INSERT IGNORE INTO `permissions` (`name`, `display_name`) VALUES
('graphics.approve', 'Approvare/Disapprovare Grafiche Contest'),
('graphics.view_all', 'Visualizzare Tutte le Grafiche Contest'),
('graphics.update', 'Modificare Grafiche Contest');

-- Esempio di inserimento di ruoli con permessi (opzionale - adatta ai tuoi ruoli esistenti)
-- Supponendo che esista un ruolo 'admin' o 'moderator' con id 1
-- INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) 
-- SELECT 1, p.id FROM `permissions` p WHERE p.name IN ('graphics.approve', 'graphics.view_all', 'graphics.update');