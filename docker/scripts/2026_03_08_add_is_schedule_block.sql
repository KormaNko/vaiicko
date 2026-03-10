-- Add is_schedule_block to tasks and add planning columns to categories

ALTER TABLE `tasks`
  ADD COLUMN `is_schedule_block` TINYINT(1) NOT NULL DEFAULT 0 AFTER `updated_at`;

-- Add planning window and max duration to categories
ALTER TABLE `categories`
  ADD COLUMN `plan_from` TIME NULL DEFAULT NULL AFTER `updated_at`,
  ADD COLUMN `plan_to` TIME NULL DEFAULT NULL,
  ADD COLUMN `max_duration` INT NULL DEFAULT NULL;
