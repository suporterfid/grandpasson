-- Self-enrollment with admin approval: new signups start 'pending' until
-- an admin approves them, and may be permanently 'rejected'.
ALTER TABLE users
  MODIFY COLUMN status ENUM('active','disabled','pending','rejected') NOT NULL DEFAULT 'active';
