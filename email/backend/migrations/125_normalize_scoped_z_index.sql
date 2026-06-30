-- Normalize z_index per scope (parent_id + lane) so values are contiguous 1..n.
-- This eliminates cross-contamination from legacy global renumbering.
-- Safe to re-run: items already contiguous within their scope will not be updated.

-- Temporary table to hold new z values
CREATE TEMPORARY TABLE _z_fix (
    item_id INT NOT NULL,
    new_z INT NOT NULL,
    PRIMARY KEY (item_id)
);

-- MariaDB does not support window functions in UPDATE directly, so we use
-- a session-variable ranking trick grouped by board_id.

-- 1) Root non-slide items: renumber per board_id
INSERT INTO _z_fix (item_id, new_z)
SELECT id, new_z FROM (
    SELECT id,
           @rn := IF(@b = board_id, @rn + 1, 1) AS new_z,
           @b := board_id
    FROM (
        SELECT id, board_id
        FROM mood_board_items
        WHERE deleted_at IS NULL AND parent_id IS NULL AND type <> 'slide'
        ORDER BY board_id, z_index, id
    ) t
    CROSS JOIN (SELECT @rn := 0, @b := 0) v
) ranked;

UPDATE mood_board_items mi
INNER JOIN _z_fix f ON mi.id = f.item_id
SET mi.z_index = f.new_z
WHERE mi.z_index <> f.new_z;

TRUNCATE TABLE _z_fix;

-- 2) Root slide items: renumber per board_id
INSERT INTO _z_fix (item_id, new_z)
SELECT id, new_z FROM (
    SELECT id,
           @rn := IF(@b = board_id, @rn + 1, 1) AS new_z,
           @b := board_id
    FROM (
        SELECT id, board_id
        FROM mood_board_items
        WHERE deleted_at IS NULL AND parent_id IS NULL AND type = 'slide'
        ORDER BY board_id, z_index, id
    ) t
    CROSS JOIN (SELECT @rn := 0, @b := 0) v
) ranked;

UPDATE mood_board_items mi
INNER JOIN _z_fix f ON mi.id = f.item_id
SET mi.z_index = f.new_z
WHERE mi.z_index <> f.new_z;

TRUNCATE TABLE _z_fix;

-- 3) Nested items (parent_id IS NOT NULL): renumber per (board_id, parent_id)
INSERT INTO _z_fix (item_id, new_z)
SELECT id, new_z FROM (
    SELECT id,
           @rn := IF(@bp = CONCAT(board_id, '-', parent_id), @rn + 1, 1) AS new_z,
           @bp := CONCAT(board_id, '-', parent_id)
    FROM (
        SELECT id, board_id, parent_id
        FROM mood_board_items
        WHERE deleted_at IS NULL AND parent_id IS NOT NULL
        ORDER BY board_id, parent_id, z_index, id
    ) t
    CROSS JOIN (SELECT @rn := 0, @bp := '') v
) ranked;

UPDATE mood_board_items mi
INNER JOIN _z_fix f ON mi.id = f.item_id
SET mi.z_index = f.new_z
WHERE mi.z_index <> f.new_z;

DROP TEMPORARY TABLE IF EXISTS _z_fix;
