# 🎓 SAFE_SYSTEM/FP - Faculty Profile System

A comprehensive web-based Faculty Profile System designed for managing faculty information, attendance, Personal Data Sheets (PDS), requirements submissions, and payroll management.

---

## ✨ Features

### 👨‍💼 Admin Features
- **Faculty Management** - Create, edit, delete faculty accounts
- **Attendance Tracking** - Real-time QR code-based time logging
- **PDS Review** - Review and approve faculty Personal Data Sheets
- **Requirements Management** - Create and track document submissions
- **Payroll Management** - Calculate salaries with deductions
- **Analytics Dashboard** - Comprehensive system statistics
- **Bulk Operations** - Import faculty via CSV
- **Announcements** - System-wide notifications
- **Station Management** - Configure time-keeping stations

### 👨‍🏫 Faculty Features
- **Profile Management** - Update personal information
- **PDS Submission** - Complete Civil Service Form 212
- **Requirements Submission** - Upload required documents
- **Attendance View** - View personal time logs
- **Pardon Requests** - Request attendance corrections
- **Calendar** - View official schedules and holidays
- **Notifications** - Real-time system updates
- **QR Code Access** - Personal QR code for time logging

### 🕐 Timekeeper Features
- **QR Scanner** - Scan faculty QR codes for time logging
- **Manual Entry** - Manual time log entry
- **Real-time Logging** - Instant attendance recording
- **Station-based** - Multiple timekeeper stations

---

## 🚀 Portability

**This system is 100% portable!** It can be moved to any computer or server without modifying code.

### Key Features:
- ✅ No hardcoded paths
- ✅ Auto-detection of URLs and base paths
- ✅ Relative file includes
- ✅ Dynamic upload paths
- ✅ Works on Windows, Linux, macOS
- ✅ Works in any directory (root or subdirectory)

See **[PORTABILITY_GUIDE.md](PORTABILITY_GUIDE.md)** for detailed transfer instructions.

---

## 📋 System Requirements

### Minimum Requirements
- **PHP:** 7.4+ (8.0+ recommended)
- **MySQL/MariaDB:** 5.7+ (8.0+ recommended)
- **Web Server:** Apache 2.4+ or nginx
- **Disk Space:** 100MB + uploads
- **Memory:** 128MB PHP memory limit

### Required PHP Extensions
- PDO & pdo_mysql
- GD (for QR codes)
- mbstring
- curl
- zip

---

## 🔧 Quick Installation

### 1. Clone/Download
```bash
# Clone or copy the FP folder to your web directory
# Example: C:\xampp\htdocs\FP
# Or: /var/www/html/FP
```

### 2. Configure Database
Edit `includes/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'wpu_faculty_system');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 3. Import Database
```bash
# Create database
mysql -u root -p -e "CREATE DATABASE wpu_faculty_system;"

# Import SQL file
mysql -u root -p wpu_faculty_system < wpu_faculty_system.sql
```

### 4. Install Dependencies
```bash
cd /path/to/FP
composer install
```

### 5. Verify Installation
Open in browser:
```
http://localhost/FP/verify_portability.php
```

All checks should pass ✅

### 6. Access System
```
http://localhost/FP/
```

**Default Admin Login:**
- Check your database for admin credentials
- Or create via registration

---

## 📁 Directory Structure

```
FP/
├── admin/              # Admin panel
├── faculty/            # Faculty panel
├── timekeeper/         # Timekeeper interface
├── includes/           # Core system files
│   ├── config.php     # Main configuration ⚙️
│   ├── database.php   # Database class
│   ├── functions.php  # Helper functions
│   └── ...
├── assets/            # CSS, JS, images
├── uploads/           # User uploads
│   ├── submissions/
│   ├── profiles/
│   ├── pds/
│   └── qr_codes/
├── storage/logs/      # System logs
├── vendor/            # Composer dependencies
├── db/                # Database files
├── home.php           # Entry point
├── login.php
└── README.md         # This file
```

---

## 🔐 Default Credentials

**Admin Account:**
- Check your imported database for credentials
- Default admin can be found in the `users` table where `user_type='admin'`

**Creating New Admin:**
- Register via the registration page
- Update `user_type` in database to `'admin'`

---

## 🎨 Technology Stack

### Backend
- **PHP 8.0+** - Server-side logic
- **MySQL 8.0** - Database
- **PDO** - Database abstraction
- **Composer** - Dependency management

### Frontend
- **Bootstrap 5** - UI framework
- **jQuery** - DOM manipulation
- **Chart.js** - Analytics charts
- **FontAwesome** - Icons
- **HTML5-QRCode** - QR scanning

### Libraries
- **PHPMailer** - Email sending
- **Endroid QR Code** - QR code generation
- **PHPSpreadsheet** - Excel exports
- **ZipStream** - File compression

---

## 📦 Moving to Another Computer

See **[PORTABILITY_GUIDE.md](PORTABILITY_GUIDE.md)** for complete instructions.

**Quick steps:**
1. Copy the entire `FP` folder
2. Update database credentials in `includes/config.php` (if needed)
3. Import `wpu_faculty_system.sql`
4. Run `composer install` (if vendor folder missing)
5. Access the system

**That's it!** The system auto-detects all paths and URLs.

---

## 🛠️ Configuration

### Database Settings
`includes/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'wpu_faculty_system');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### SMTP Settings (Optional)
For email notifications:
```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'your-app-password');
define('ENABLE_MAIL', true);
```

### Offline Mode
Disable email for offline use:
```php
define('OFFLINE_MODE', true);
```

### Upload Settings
```php
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_FILE_TYPES', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);
```

---

## 🔍 Troubleshooting

### Database Connection Failed
1. Check credentials in `includes/config.php`
2. Verify MySQL is running
3. Ensure database exists

### Assets Not Loading
1. Clear browser cache (Ctrl + F5)
2. Check `assets/` folder exists
3. Run `verify_portability.php` to check paths

### File Upload Errors
1. Check folder permissions (755 or 777)
2. Verify PHP settings: `upload_max_filesize` >= 5MB
3. Ensure `uploads/` folder is writable

### Session/Login Issues
1. Clear browser cookies
2. Check session directory permissions
3. Try: `home.php?force_login=1`

**More solutions:** See [PORTABILITY_GUIDE.md](PORTABILITY_GUIDE.md#-troubleshooting)

---

## 📚 Documentation

- **[PORTABILITY_GUIDE.md](PORTABILITY_GUIDE.md)** - System portability & transfer guide
- **[CSP_FIX_SUMMARY.md](CSP_FIX_SUMMARY.md)** - Content Security Policy fixes
- **[DESIGNATIONS_QUICK_START.txt](DESIGNATIONS_QUICK_START.txt)** - Position setup
- **[includes/MOBILE_FUNCTIONS_README.md](includes/MOBILE_FUNCTIONS_README.md)** - Mobile features

---

## 🔒 Security Features

- ✅ Prepared statements (SQL injection protection)
- ✅ Password hashing (bcrypt)
- ✅ Session management
- ✅ CSRF protection
- ✅ Input validation & sanitization
- ✅ File upload restrictions
- ✅ Secure headers (CSP, X-Frame-Options)
- ✅ Access control (role-based)

---

## 🎯 Key Features Detail

### QR Code System
- Automatic QR code generation for each faculty
- Station-based time logging
- Mobile-friendly QR scanner
- Real-time attendance tracking

### PDS Management
- Complete Civil Service Form 212 (Revised 2017)
- Auto-save functionality
- Multi-page form with navigation
- Review and approval workflow

### Payroll System
- Salary calculation based on position
- Deduction management (SSS, PhilHealth, Pag-IBIG, Tax)
- Monthly payroll generation
- Export to Excel

### Requirement Tracking
- Create custom requirements
- Set deadlines and file type restrictions
- Track submission status
- Bulk operations support

### Analytics Dashboard
- Total faculty count
- Attendance statistics
- Submission tracking
- Visual charts and graphs

---

## 📊 Database Schema

**Main Tables:**
- `users` - User accounts
- `faculty_profiles` - Faculty information
- `faculty_pds` - Personal Data Sheets
- `requirements` - System requirements
- `faculty_submissions` - Document submissions
- `employee_logs` - Attendance records
- `timekeeping_stations` - Station configuration
- `pardon_requests` - Attendance corrections
- `announcements` - System announcements
- `positions` - Job positions with salary

---

## 🚦 System Status

- ✅ **Fully Functional** - All features working
- ✅ **Portable** - Can be moved to any computer
- ✅ **Tested** - Windows, Linux, localhost
- ✅ **Documented** - Comprehensive guides
- ✅ **Secure** - Best practices implemented
- ✅ **Maintained** - Active development

---

## 🤝 Contributing

This is a private institutional system. For modifications:

1. Test thoroughly on local environment
2. Backup database before changes
3. Document any configuration changes
4. Follow existing code style
5. Update documentation as needed

---

## 📝 Changelog

### Version 2.0 (December 2025)
- ✅ Full portability implementation
- ✅ Auto URL and path detection
- ✅ Comprehensive documentation
- ✅ Verification script
- ✅ Security enhancements
- ✅ Mobile optimizations

---

## 📞 Support

**For technical issues:**
1. Run `verify_portability.php` for diagnostics
2. Check error logs in `storage/logs/`
3. Review [PORTABILITY_GUIDE.md](PORTABILITY_GUIDE.md)
4. Check browser console (F12) for errors

**System Administrator:**
- Contact your institution's IT department
- Review system logs for detailed error information

---

## ⚖️ License

**Proprietary System**  
West Philippine University Faculty Profile System  
© 2025 All Rights Reserved

This system is for institutional use only.

---

## 🎓 About

**System Name:** SAFE_SYSTEM/FP (Faculty Profile System)  
**Institution:** West Philippine University  
**Purpose:** Faculty information and attendance management  
**Version:** 2.0  
**Last Updated:** December 4, 2025

---

## ✅ Quick Test

After installation, test these features:

- [ ] Login as admin
- [ ] Login as faculty  
- [ ] Create a new faculty account
- [ ] Upload a profile picture
- [ ] Generate a QR code
- [ ] Submit a requirement
- [ ] View attendance logs
- [ ] Create an announcement
- [ ] Export data to Excel

**All working?** ✅ System is ready!

---

## 🚀 Getting Started

1. **Read:** [PORTABILITY_GUIDE.md](PORTABILITY_GUIDE.md)
2. **Install:** Follow Quick Installation above
3. **Verify:** Run `verify_portability.php`
4. **Test:** Login and explore features
5. **Configure:** Update SMTP, positions, etc.
6. **Use:** Start managing faculty data!

---

**Need help?** Check the [PORTABILITY_GUIDE.md](PORTABILITY_GUIDE.md) for detailed troubleshooting.

**Ready to move?** The system is fully portable - just copy and go! 🚀

