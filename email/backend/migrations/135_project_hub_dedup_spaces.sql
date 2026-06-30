-- Migration 134: Deduplicate projecthub_spaces and add unique constraint
-- Moves folders from duplicate spaces to the first (lowest id) occurrence, then deletes duplicates.

-- Step 1: Move folders from duplicate spaces to the original (lowest-id) space
UPDATE projecthub_folders f
INNER JOIN projecthub_spaces s ON f.space_id = s.id
INNER JOIN (
    SELECT user_email, name, MIN(id) AS keep_id
    FROM projecthub_spaces
    WHERE archived = 0
    GROUP BY user_email, name
    HAVING COUNT(*) > 1
) dup ON s.user_email = dup.user_email AND s.name = dup.name AND s.id != dup.keep_id
SET f.space_id = dup.keep_id;

-- Step 2: Delete the duplicate space rows (keeping the one with lowest id)
DELETE s FROM projecthub_spaces s
INNER JOIN (
    SELECT user_email, name, MIN(id) AS keep_id
    FROM projecthub_spaces
    GROUP BY user_email, name
    HAVING COUNT(*) > 1
) dup ON s.user_email = dup.user_email AND s.name = dup.name AND s.id != dup.keep_id;

-- Step 3: Add unique constraint to prevent future duplicates
ALTER TABLE projecthub_spaces
ADD UNIQUE KEY unique_user_space_name (user_email, name);
