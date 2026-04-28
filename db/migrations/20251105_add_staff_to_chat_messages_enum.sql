-- Add 'staff' to chat_messages sender_type and receiver_type enums
ALTER TABLE chat_messages
  MODIFY sender_type ENUM('faculty','admin','staff') NOT NULL,
  MODIFY receiver_type ENUM('faculty','admin','staff') NOT NULL;
