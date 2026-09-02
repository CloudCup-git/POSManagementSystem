-- Run this once on an existing Cloud Cup database before opening Branch Management.
-- The branches table already exists; these fields add map and customer-facing details.
ALTER TABLE branches
  ADD COLUMN operating_hours VARCHAR(120) NULL AFTER contact_number,
  ADD COLUMN latitude DECIMAL(10,7) NULL AFTER operating_hours,
  ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
