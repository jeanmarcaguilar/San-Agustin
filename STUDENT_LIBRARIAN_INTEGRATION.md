# 📚 Student-Librarian Integration Guide

## Overview
This document explains how students from `student_db` are integrated with the library system in `librarian_db`.

---

## How It Works

### 1. **Patron System**
Students must be registered as **patrons** in the `librarian_db.patrons` table to:
- Browse available books
- Borrow books
- View borrowing history
- Return books

### 2. **Automatic Synchronization**
When a student logs in and accesses any library feature, they are automatically synced to the patrons table.

**Files Involved:**
- `includes/sync_patron.php` - Auto-sync script
- `student/available-books.php` - Includes auto-sync
- `student/borrow-history.php` - Includes auto-sync
- `student/return-books.php` - Includes auto-sync

---

## Manual Sync (One-Time Setup)

### Run the Sync Script:
```
http://localhost/San%20Agustin/sync_student_patrons.php
```

This will:
✅ Sync all active students to patrons table
✅ Update existing patron records
✅ Set membership dates and expiry
✅ Configure borrowing limits (5 books per student)

---

## Database Structure

### `librarian_db.patrons` Table
```sql
CREATE TABLE `patrons` (
  `patron_id` int(11) PRIMARY KEY AUTO_INCREMENT,
  `user_id` int(11) NOT NULL UNIQUE,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `contact_number` varchar(20),
  `address` text,
  `membership_date` date NOT NULL,
  `membership_expiry` date,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `max_books_allowed` int(11) DEFAULT 5,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### `librarian_db.transactions` Table
```sql
CREATE TABLE `transactions` (
  `id` int(11) PRIMARY KEY AUTO_INCREMENT,
  `book_id` int(11) NOT NULL,
  `patron_id` int(11) NOT NULL,
  `librarian_id` int(11),
  `checkout_date` datetime NOT NULL,
  `due_date` datetime NOT NULL,
  `return_date` datetime,
  `status` enum('checked_out','returned','overdue','lost') DEFAULT 'checked_out',
  `fine_amount` decimal(10,2) DEFAULT 0.00,
  `fine_paid` tinyint(1) DEFAULT 0,
  `notes` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## Student Library Features

### 1. **Available Books** (`student/available-books.php`)
- View all books with `available > 0`
- Search by title, author, ISBN
- Filter by category
- Borrow books with duration selection (1-14 days)
- View book images or gradient placeholders

### 2. **Borrow History** (`student/borrow-history.php`)
- View all past and current transactions
- See checkout dates, due dates, return dates
- Status badges (Borrowed, Returned, Overdue)
- Search functionality

### 3. **Return Books** (`student/return-books.php`)
- View currently borrowed books
- Return books with one click
- Automatic update of available count
- Overdue indicators

### 4. **Recommendations** (`student/recommendations.php`)
- View recommended books
- "View Book" button links to Available Books
- No direct borrowing (redirects to Available Books)

---

## Key Integration Points

### Student Login → Patron Creation
```php
// In student pages
require_once '../includes/sync_patron.php';
```

This automatically:
1. Checks if student exists in patrons table
2. Creates new patron record if not exists
3. Updates patron info if exists
4. Sets membership dates and limits

### Borrowing Process
```php
// 1. Check book availability
SELECT * FROM books WHERE id = ? AND available > 0

// 2. Check student's active loans
SELECT COUNT(*) FROM transactions 
WHERE patron_id = ? AND return_date IS NULL

// 3. Create transaction
INSERT INTO transactions 
(book_id, patron_id, checkout_date, due_date, status)
VALUES (?, ?, ?, ?, 'checked_out')

// 4. Update book availability
UPDATE books SET available = available - 1 WHERE id = ?
```

### Return Process
```php
// 1. Update transaction
UPDATE transactions 
SET return_date = NOW(), status = 'returned'
WHERE id = ? AND patron_id = ?

// 2. Update book availability
UPDATE books SET available = available + 1 WHERE id = ?
```

---

## Configuration

### Borrowing Limits
Default: **5 books per student**

To change:
```sql
UPDATE patrons SET max_books_allowed = 10 WHERE user_id = ?;
```

### Membership Duration
Default: **1 year from sync date**

To extend:
```sql
UPDATE patrons 
SET membership_expiry = DATE_ADD(CURDATE(), INTERVAL 2 YEAR)
WHERE user_id = ?;
```

### Loan Duration
Default: **14 days** (configurable per borrow: 1-14 days)

---

## Troubleshooting

### Problem: Student can't borrow books
**Solution:**
1. Run `sync_student_patrons.php`
2. Check patron status: `SELECT * FROM patrons WHERE user_id = ?`
3. Verify patron status is 'active'

### Problem: "Table patrons doesn't exist"
**Solution:**
Run the librarian_db.sql schema:
```sql
SOURCE c:/xampp/htdocs/San Agustin/database/librarian_db.sql
```

### Problem: Transactions not showing
**Solution:**
1. Check if using correct user_id (not student.id)
2. Verify transactions table uses `patron_id` matching `user_id`
3. Check query: `SELECT * FROM transactions WHERE patron_id = ?`

---

## Testing Checklist

- [ ] Run `sync_student_patrons.php`
- [ ] Login as student
- [ ] Navigate to Available Books
- [ ] Verify books display with images
- [ ] Borrow a book
- [ ] Check Borrow History shows transaction
- [ ] Navigate to Return Books
- [ ] Return the book
- [ ] Verify book available count increased
- [ ] Check Borrow History shows return date

---

## Database Relationships

```
login_db.users (id)
    ↓
student_db.students (user_id)
    ↓ [Auto-sync]
librarian_db.patrons (user_id)
    ↓
librarian_db.transactions (patron_id)
    ↓
librarian_db.books (book_id)
```

---

## Files Modified for Integration

1. **student/available-books.php** - Added auto-sync, uses librarian_db
2. **student/borrow-history.php** - Uses librarian_db.transactions
3. **student/return-books.php** - Uses librarian_db.transactions
4. **student/recommendations.php** - Links to Available Books
5. **includes/sync_patron.php** - NEW: Auto-sync script
6. **sync_student_patrons.php** - NEW: Manual sync tool

---

## Success Indicators

✅ Students automatically become patrons on first library access
✅ All active students synced to patrons table
✅ Students can borrow up to 5 books
✅ Transactions tracked in librarian_db
✅ Book availability updates automatically
✅ Borrowing history displays correctly
✅ Return functionality works properly

---

**Last Updated:** October 14, 2025
**Status:** ✅ Fully Integrated
