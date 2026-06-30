-- Migration: Fix blueprint_packages UNIQUE constraint
-- Version: 005

-- First, remove duplicates (keep the first one by ID)
DELETE bp1 FROM blueprint_packages bp1
INNER JOIN blueprint_packages bp2 
WHERE bp1.id > bp2.id 
  AND bp1.blueprint_id = bp2.blueprint_id 
  AND bp1.package_name = bp2.package_name;

-- Now add the UNIQUE constraint
ALTER TABLE blueprint_packages 
ADD UNIQUE KEY unique_blueprint_package (blueprint_id, package_name);

