# PennyWise - Budget & Expense Tracker

A full-stack web application for managing personal finances, tracking expenses, and budgeting. Built with vanilla JavaScript, PHP, and MySQL.

![PennyWise Logo](static/images/logo.png)

## Features

- **User Authentication**: Secure registration and login system with password hashing
- **Account Management**: Create and manage multiple financial accounts (Cash, Bank, Savings, Credit)
- **Expense Tracking**: Track all your expenses with categories and descriptions
- **Income Tracking**: Record all sources of income
- **Transfer Funds**: Transfer money between your accounts
- **Categories**: Organize transactions with custom categories
- **Visual Analytics**: Interactive charts showing expense and income breakdown
- **Dashboard Summary**: Quick overview of your financial status
- **Responsive Design**: Works on desktop, tablet, and mobile devices

## Technology Stack

### Frontend
- **HTML5** - Semantic markup
- **CSS3** - Modern styling with flexbox and grid
- **JavaScript (ES6+)** - Vanilla JS with async/await
- **Chart.js** - Interactive doughnut charts for analytics

### Backend
- **PHP 8.x** - Server-side logic
- **MySQL/MariaDB** - Relational database
- **PDO** - Database abstraction layer
- **Session-based Authentication** - Secure user sessions

### Server Requirements
- **XAMPP** (Apache, MySQL, PHP) or similar stack
- PHP 7.4 or higher
- MySQL 5.7 or higher / MariaDB 10.3 or higher
- mod_rewrite enabled (for clean URLs)

## Database Schema

The application uses a relational database with the following tables:

### Users Table
Stores user account information including credentials.

| Column | Type | Description |
|--------|------|-------------|
| id | VARCHAR(36) | UUID primary key |
| username | VARCHAR(50) | Unique username |
| email | VARCHAR(100) | Unique email address |
| password | VARCHAR(255) | Bcrypt hashed password |
| created_at | TIMESTAMP | Account creation date |
| last_login | TIMESTAMP | Last login timestamp |

### Accounts Table
User's financial accounts (Cash, Bank, etc.)

| Column | Type | Description |
|--------|------|-------------|
| id | VARCHAR(36) | UUID primary key |
| user_id | VARCHAR(36) | Foreign key to users |
| name | VARCHAR(100) | Account name |
| balance | DECIMAL(15,2) | Current balance |
| account_type | ENUM | cash, bank, credit, savings, other |
| is_default | BOOLEAN | Default account flag |

### Categories Table
Income and expense categories

| Column | Type | Description |
|--------|------|-------------|
| id | VARCHAR(36) | UUID primary key |
| user_id | VARCHAR(36) | Foreign key to users |
| name | VARCHAR(100) | Category name |
| type | ENUM | income or expense |
| icon | VARCHAR(50) | Icon identifier |
| color | VARCHAR(7) | Hex color code |

### Records Table
All financial transactions

| Column | Type | Description |
|--------|------|-------------|
| id | VARCHAR(36) | UUID primary key |
| user_id | VARCHAR(36) | Foreign key to users |
| type | ENUM | income, expense, transfer |
| amount | DECIMAL(15,2) | Transaction amount |
| category_id | VARCHAR(36) | Foreign key to categories |
| from_account_id | VARCHAR(36) | Source account |
| to_account_id | VARCHAR(36) | Destination account (for transfers) |
| description | VARCHAR(255) | Transaction description |
| date | DATE | Transaction date |

### Budgets Table
Budget goals per category

| Column | Type | Description |
|--------|------|-------------|
| id | VARCHAR(36) | UUID primary key |
| user_id | VARCHAR(36) | Foreign key to users |
| category_id | VARCHAR(36) | Foreign key to categories |
| amount | DECIMAL(15,2) | Budget amount |
| period | ENUM | weekly, monthly, yearly |

## Installation Guide

### Prerequisites
1. Install [XAMPP](https://www.apachefriends.org/) (or similar stack like WAMP, MAMP)
2. Ensure Apache and MySQL services are running

### Step 1: Clone or Download
```bash
# If using Git
git clone https://github.com/Yoseph777/Skills.git

# Or download the ZIP and extract to your XAMPP htdocs folder
```

### Step 2: Place Files in XAMPP
Copy all files to your XAMPP htdocs directory:
```
C:\xampp\htdocs\pennywise\
```

### Step 3: Create Database
1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Click on "SQL" tab
3. Run the SQL script from `database/schema.sql` or execute:
```sql
-- Option 1: Import the schema file
-- Click "Import" and select database/schema.sql

-- Option 2: Run this command
CREATE DATABASE pennywise_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Step 4: Configure Database Connection
Edit `config/database.php` if your MySQL credentials differ from defaults:
```php
define('DB_HOST', 'localhost');        // Database host
define('DB_NAME', 'pennywise_db');     // Database name
define('DB_USER', 'root');             // Database username (default XAMPP: root)
define('DB_PASS', '');                 // Database password (default XAMPP: empty)
```

### Step 5: Access the Application
Open your browser and navigate to:
```
http://localhost/pennywise/login.html
```

### Step 6: Register a New Account
1. Click "Register here" on the login page
2. Fill in your details
3. Start tracking your finances!

## API Endpoints

### Authentication (`api/auth.php`)

| Action | Method | Description |
|--------|--------|-------------|
| `?action=register` | POST | Register new user |
| `?action=login` | POST | Login user |
| `?action=logout` | GET | Logout user |
| `?action=check` | GET | Check session status |
| `?action=user` | GET | Get current user data |

### Accounts (`api/accounts.php`)

| Method | Description |
|--------|-------------|
| GET | Get all accounts for current user |
| POST | Create new account |
| PUT | Update existing account |
| DELETE | Delete account |

### Categories (`api/categories.php`)

| Method | Description |
|--------|-------------|
| GET | Get all categories (optional `?type=income/expense`) |
| POST | Create new category |
| PUT | Update existing category |
| DELETE | Delete category (soft delete) |

### Records (`api/records.php`)

| Method | Description |
|--------|-------------|
| GET | Get records with filtering (`?type=`, `?start_date=`, `?end_date=`) |
| POST | Create new record (income/expense/transfer) |
| PUT | Update existing record |
| DELETE | Delete record |

### Summary (`api/summary.php`)

| Method | Description |
|--------|-------------|
| GET | Get dashboard summary and analytics |

## Project Structure

```
pennywise/
├── api/
│   ├── auth.php          # Authentication endpoints
│   ├── accounts.php      # Account CRUD operations
│   ├── categories.php    # Category CRUD operations
│   ├── records.php       # Transaction CRUD operations
│   └── summary.php       # Dashboard summary data
├── config/
│   ├── config.php        # Application configuration
│   └── database.php      # Database connection
├── database/
│   └── schema.sql        # Database schema
├── static/
│   ├── css/
│   │   ├── styles.css    # Main stylesheet
│   │   └── login.css     # Login/register styles
│   └── js/
│       └── app.js        # Main JavaScript application
├── index.html            # Main dashboard
├── login.html            # Login page
├── register.html         # Registration page
└── README.md             # This file
```

## Security Features

- **Password Hashing**: Bcrypt with cost factor 12
- **SQL Injection Prevention**: PDO prepared statements
- **XSS Protection**: Input sanitization and output encoding
- **Session Management**: Secure session handling
- **CORS Headers**: Configured for API access

## Future Enhancements

- [ ] Budget tracking and alerts
- [ ] Recurring transactions
- [ ] Export data to CSV/Excel
- [ ] Dark/Light theme toggle
- [ ] Multi-currency support
- [ ] Financial goals and savings targets
- [ ] Email notifications
- [ ] Two-factor authentication
- [ ] Mobile app (PWA)

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## License

This project is open-source and available under the MIT License.

## Support

If you encounter any issues or have questions, please open an issue on GitHub.

---

**Built with ❤️ by Yoseph777**