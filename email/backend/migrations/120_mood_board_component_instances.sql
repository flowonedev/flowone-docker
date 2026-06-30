-- Component instance linking: items track which component they were placed from
-- so edits to a component can propagate to all placed instances.

ALTER TABLE mood_board_items
  ADD COLUMN component_id INT DEFAULT NULL COMMENT 'Source component ID (null = not from a component)',
  ADD COLUMN component_instance_id VARCHAR(36) DEFAULT NULL COMMENT 'Groups items placed together from one component placement',
  ADD COLUMN component_item_index SMALLINT DEFAULT NULL COMMENT 'Index within the component items_data array',
  ADD INDEX idx_component_id (component_id),
  ADD INDEX idx_component_instance (component_instance_id);

-- Design tokens (global variables) stored per-board: named colors, font presets, etc.
ALTER TABLE mood_boards
  ADD COLUMN design_tokens JSON DEFAULT NULL COMMENT 'Named design tokens: colors, fonts, etc.';
