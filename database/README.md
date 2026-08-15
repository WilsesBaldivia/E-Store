# CSCQC E-Store database

The main schema is [`../e_storedb.sql`](../e_storedb.sql). It is designed for the MariaDB 10.4 server used by XAMPP.

## Import with phpMyAdmin

1. Back up the current database before making structural changes.
2. Open phpMyAdmin and select the **Import** tab.
3. Choose `e_storedb.sql` from the project root.
4. Keep the SQL format selected and start the import.
5. Confirm that the tables below appear under `e_storedb`.

## Tables

- `users` - students, staff, and administrators
- `categories` - product groupings such as uniforms and books
- `products` - shared product information and base prices
- `product_variants` - sizes, colors, and variant-specific prices
- `inventory` - available, reserved, and reorder quantities
- `reservations` - reservation headers and status history
- `reservation_items` - products included in each reservation
- `announcements` - College, SHS, JHS, and general announcements
- `inventory_movements` - stock audit trail

## Security requirements

- Never save plain-text passwords. PHP must use `password_hash()` when registering and `password_verify()` when logging in.
- Do not commit real database passwords to GitHub.
- Do not add a default administrator password to the SQL file. Create the first administrator through a controlled setup script later.

The original exported `users` definition is preserved in `users_legacy_export.sql` for reference only. Do not import both files into the same database.
