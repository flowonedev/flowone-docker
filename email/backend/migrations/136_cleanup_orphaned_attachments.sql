-- Remove card attachment records that reference deleted drive files
DELETE wa FROM webmail_card_attachments wa
LEFT JOIN drive_files df ON wa.drive_file_id = df.id
WHERE wa.drive_file_id IS NOT NULL AND df.id IS NULL;
