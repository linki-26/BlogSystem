# StudentBlog

StudentBlog is a web-based student blogging system developed for the Internet Technology course. The project includes a public post listing, user registration and login, student dashboards, moderator/admin dashboards, post management, comments, image uploads, Google authentication support, and email notification support.

## Main Features

- User registration and login
- Different user roles: guest, registered student, moderator, and administrator
- User profile editing
- Permanent account deletion
- Post creation, viewing, editing, deleting, and searching
- Comment creation and management
- Database-connected back-end using PHP and MySQL
- Form validation and error messages for incorrect input
- Ownership protection so normal users can edit or delete only their own content
- Admin and moderator tools for managing content
- Cloudinary support for post image uploads
- Google OAuth support for login
- Resend email API support for notifications

## Technology Stack

- Front-end: HTML, CSS, JavaScript
- Back-end: PHP
- Database: MySQL / MariaDB
- Local server environment: XAMPP
- Third-party services:
  - Cloudinary for image uploads
  - Google OAuth for authentication
  - Resend for email notifications

## Project Structure

```text
Blog_system/
├── api/
│   ├── auth.php
│   ├── categories.php
│   ├── comments.php
│   ├── db.example.php
│   ├── email_logs.php
│   ├── email_service.php
│   ├── google_config.example.php
│   ├── install.php
│   ├── posts.php
│   ├── resend_config.example.php
│   ├── seed_users.php
│   └── users.php
├── images/
├── app.js
├── cloudinary-config.example.js
├── dashboard-admin.html
├── dashboard-moderator.html
├── dashboard-student.html
├── database.sql
├── google-config.example.js
├── index.html
├── login.html
├── post.html
├── styles.css
└── README.md
```

## Files Not Pushed to GitHub

The project uses local configuration files that contain private database credentials and API keys. These files must not be pushed to a public repository:

```text
api/db.php
api/google_config.php
api/resend_config.php
cloudinary-config.js
google-config.js
```

Instead, the repository includes example files:

```text
api/db.example.php
api/google_config.example.php
api/resend_config.example.php
cloudinary-config.example.js
google-config.example.js
```

On a new server, copy each example file, rename it to the real config filename, and add the correct private values.

## Local Installation

1. Install XAMPP.
2. Copy the project folder into:

```text
C:\xampp\htdocs\Blog_system
```

3. Start Apache and MySQL from the XAMPP Control Panel.
4. Create a MySQL database named:

```text
studentblog
```

5. Copy the example database config:

```text
api/db.example.php
```

Rename the copy to:

```text
api/db.php
```

6. Update the database values in `api/db.php` if needed:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'studentblog');
define('DB_USER', 'root');
define('DB_PASS', '');
```

7. Open the installer in the browser:

```text
http://localhost/Blog_system/api/install.php
```

8. Open the website:

```text
http://localhost/Blog_system/
```

## Database Setup

The database structure is stored in:

```text
database.sql
```

The system uses several related tables, including users, posts, comments, categories, and email logs. The main post entity contains multiple fields such as title, content, category, status, image URL, author ID, and timestamps.

The database can be created in two ways:

1. Import `database.sql` using phpMyAdmin.
2. Run `api/install.php` from the browser to create the required tables.

## CRUD Functionality

The project implements CRUD operations for posts:

- Create: registered users can create posts from the student dashboard.
- Read: guests and users can view approved/public posts.
- Update: users can edit their own posts.
- Delete: users can delete their own posts.
- Search: users can search posts and receive matching results or a clear no-results message.

Comments also support database-connected creation and display.

## User Roles and Permissions

Guest users can:

- View public approved posts
- Search public posts
- Register or log in

Registered users can:

- Create posts
- Edit and delete their own posts
- Comment on posts
- Edit their profile
- Delete their account permanently

Moderators can:

- Review and manage posts depending on moderation permissions
- Help control inappropriate content

Administrators can:

- Manage users and content
- View system/admin dashboard information
- Access email notification logs
- Perform wider system management actions

Normal users are not allowed to edit or delete posts created by another user.

## Security Implementation

The project includes the following security measures:

- Passwords are stored using hashing.
- PHP sessions are used for login state.
- PDO prepared statements are used for database queries.
- User input is validated before being saved.
- Incorrect or missing form data returns error messages.
- Protected actions require authentication.
- Ownership checks prevent users from editing or deleting other users' records.
- Private API keys and database credentials are excluded from GitHub.

## Third-Party API Configuration

### Cloudinary

Cloudinary is used for post image uploads.

Setup:

1. Copy:

```text
cloudinary-config.example.js
```

2. Rename the copy to:

```text
cloudinary-config.js
```

3. Add the Cloudinary cloud name and unsigned upload preset.

### Google OAuth

Google OAuth is used for Google login.

Setup:

1. Copy:

```text
api/google_config.example.php
google-config.example.js
```

2. Rename the copies to:

```text
api/google_config.php
google-config.js
```

3. Add the Google OAuth Web Client ID to both files.
4. In Google Cloud Console, add the correct authorized JavaScript origin, for example:

```text
http://localhost
```

or the real deployed domain.

### Resend

Resend is used for email notifications and email log tracking.

Setup:

1. Copy:

```text
api/resend_config.example.php
```

2. Rename the copy to:

```text
api/resend_config.php
```

3. Add the Resend API key and sender email.

## Migration and Deployment Plan

This section describes how to migrate the project from one server to another.

### Files to Transfer

Transfer the following:

- All HTML, CSS, and JavaScript files
- The `api/` folder
- The `images/` folder
- `database.sql`
- `README.md`
- Example config files

Do not transfer real private config files through GitHub. Recreate them manually on the new server from the example files.

### Database Transfer

If the project already has real data:

1. Export the database from the old server using phpMyAdmin or `mysqldump`.
2. Create a new database on the new server.
3. Import the exported SQL file into the new database.
4. Update `api/db.php` with the new database credentials.

If the project is installed fresh:

1. Create a new database.
2. Import `database.sql` or run `api/install.php`.

### New Server Setup Steps

1. Install or prepare a PHP-compatible server.
2. Install or enable MySQL/MariaDB.
3. Enable required PHP extensions, especially PDO and PDO MySQL.
4. Upload or clone the project files.
5. Create the database.
6. Import the database structure and data.
7. Create the real configuration files from the example files.
8. Add the correct database credentials and API keys.
9. Update Google OAuth authorized URLs for the new domain.
10. Check that the web server points to the correct project directory.
11. Open the website in the browser and test the main features.

### Post-Migration Testing

After migration, test:

- Home page loads correctly.
- Public posts are visible.
- Search returns correct results.
- Registration works.
- Login and logout work.
- Google login works if configured.
- A registered user can create a post.
- Image upload works through Cloudinary.
- Created posts are saved in the database.
- Users can edit and delete only their own posts.
- Comments can be created and displayed.
- Profile editing works.
- Account deletion works.
- Admin/moderator dashboard pages load.
- Email notifications are sent or logged correctly.
- Invalid form data produces clear error messages.

## GitHub Push Notes

Push these files:

- Source code files
- HTML/CSS/JavaScript files
- PHP API files
- `database.sql`
- `README.md`
- `images/`
- `.example` configuration files
- `.gitignore`

Do not push real local configuration files with passwords or API keys.

## Assignment Requirement Coverage

The project covers the LD4 back-end requirements:

- Database connection and table structure
- Create, read, update, delete operations
- Search functionality
- User registration and login
- Different user roles
- Profile editing
- Security validation
- Error messages for incorrect data
- Ownership restrictions for editing and deleting
- Deployment and migration plan

