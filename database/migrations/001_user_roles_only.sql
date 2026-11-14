-- Apply once to the legacy database, preserving user_roles before dropping users.role_id.
START TRANSACTION;

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT user_id, role_id
FROM users;

ALTER TABLE users
    DROP FOREIGN KEY fk_users_role;

ALTER TABLE users
    DROP INDEX fk_users_role,
    DROP COLUMN role_id;

COMMIT;
