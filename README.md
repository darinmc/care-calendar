# Care Calendar

A multi-user calendar application designed for families supporting someone with memory loss. 

Built to provide a shared, reliable space to track daily events, appointments, and medication, while keeping a permanent, safe record of every change.

## The Problem

When families coordinate care for a loved one with memory loss, standard calendars fall short. Family members need to know what happened, who changed what, and they need a system that forgives mistakes (like accidental deletions). This application solves that by combining a simple day/week/month calendar with role-based access, full version history, and restore capabilities.

## Tech Stack

- **Language:** PHP 8 (Plain PHP, no framework)
- **Database:** MySQL 8
- **Frontend:** Vanilla JavaScript, CSS3, HTML5
- **Architecture:** Custom MVC-style structure with modular `includes/` for shared logic.

## Features

- **Role-Based Permissions:** Distinct roles for Owner, Admin, Family, Guest, and the Subject (the person being cared for).
- **Full Version History:** Every edit, deletion, or restore is tracked. Nothing is ever truly lost.
- **Soft Delete & Restore:** Deleted events are moved to a history view and can be restored with a single click.
- **Append-Only Messaging:** Families can leave comments and thread updates on events.
- **Audit Logging:** A comprehensive log of every action taken in the system, tracking who did what and when.
- **Per-User Theming:** Users can customise their view.
- **Security:** Built-in CSRF protection, password hashing, prepared statements to prevent SQL injection, and strict session management.

## Architecture

The application is structured for clarity and security:
- `api/`: Endpoints for asynchronous requests (like marking messages as read).
- `auth/`: Login, logout, and session handling.
- `bin/`: Command-line scripts for administrative tasks (like creating users).
- `calendar/`, `events/`: The core calendar logic and event CRUD operations.
- `includes/`: Modular PHP files handling database connections (`db.php`), authentication (`auth.php`), permissions (`permissions.php`), and audit logging (`audit.php`).
- `templates/`: Shared header and footer layouts.

## Setup / Installation

1. Clone the repository.
2. Copy `config.example.php` to `config.php` and fill in your database credentials and a secure `app_key`.
3. Import the database schema (run the SQL in `schema.sql` if provided, or use the provided `bin/` scripts).
4. Configure your web server (Apache/Nginx) to point to the `public` or root directory, ensuring `.htaccess` rules are respected.
5. Ensure the `bin/create_account.php` script is run to set up your first admin user.
