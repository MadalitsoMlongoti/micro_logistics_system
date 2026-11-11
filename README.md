# Micro-Logistics Fleet Management System (MLFMS)
Starter implementation — PHP 8.2 + MySQL/MariaDB + Bootstrap, intended for XAMPP.

## Setup
1. Copy `micro_logistics_system` into `C:\xampp\htdocs\`.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin and import `database/schema.sql`.
4. Edit `config/database.php` if your MySQL credentials differ.
5. Visit `http://localhost/micro_logistics_system/`.
6. Default admin: `admin@mlfms.local` / `ChangeMe123!` (change immediately).

## Included in this starter
- Secure login/logout (password hashing, session regeneration)
- Dashboard summary
- Drivers CRUD (create/list)
- Fleet CRUD (create/list)
- Delivery order creation/list/status updates
- Pricing calculator (bicycle/tricycle bands based on the project document)
- Revenue and operating-cost summary
- Maintenance records
- Users/roles schema
- CSRF protection and prepared statements

## Project pricing assumptions (from Version 2)
Bicycles: 0–2 km K15; >2–4 km K22; >4–6 km K30.
Tricycles: 0–3 km K50; >3–6 km K70; >6–10 km K100; >10–15 km K135.
Surcharges: extra stop K10; waiting/loading beyond 10 minutes K2/min; rush +25%; heavy/bulky bicycle +K5 or tricycle +K30; rain bicycle +K5 or tricycle +K10.
These are configurable starter rules, not final commercial tariffs.

## Important next production tasks
- Change the default password and create secure backups.
- Add role-specific authorization (the roles table is seeded, but starter routes are admin-only).
- Add driver mobile/PWA view, SMS/WhatsApp notifications, GPS tracking, payment integrations, and audit logs.
- Confirm ZICTA/RTSA requirements, insurance, payload limits, and actual operating costs before deployment.

7. Run `http://localhost/micro_logistics_system/database/reset_admin.php` once to set the documented admin password, then DELETE `database/reset_admin.php` immediately. (Or replace the seed hash manually using PHP.)
