# Librarian Book Management Setup Guide

## Paano Mag-add ng New Book

### Step 1: I-setup ang Database
1. Buksan ang browser at pumunta sa: `http://localhost/San%20Agustin/setup_books_table.php`
2. Makikita mo ang setup page na mag-create ng books table at mag-add ng sample books
3. Kapag successful, makikita mo ang list ng current books sa database

### Step 2: Mag-login bilang Librarian
1. Pumunta sa: `http://localhost/San%20Agustin/login.php`
2. Login gamit ang librarian account
3. Dapat ma-redirect ka sa librarian dashboard

### Step 3: Mag-add ng New Book
1. Sa librarian sidebar, click ang **"Catalog Management"**
2. Click ang **"Add New Books"**
3. Fill up ang form:
   - **Title*** (Required) - Pangalan ng libro
   - **Author*** (Required) - Sumulat ng libro
   - **ISBN*** (Required) - ISBN number (e.g., 978-0-123456-78-9)
   - **Publisher** (Optional) - Publishing company
   - **Publication Year** (Optional) - Taon ng publication
   - **Quantity*** (Required) - Ilang kopya (default: 1)
   - **Category** (Optional) - Uri ng libro (Fiction, Science, etc.)
   - **Description** (Optional) - Short description ng libro

4. Click ang **"Save Book"** button
5. Kapag successful:
   - Makikita mo ang success message
   - Automatic redirect sa Books Catalog page
   - Makikita mo na ang bagong libro sa list

### Step 4: Verify sa Database at Website

#### Check sa Database:
1. Buksan ang phpMyAdmin: `http://localhost/phpmyadmin`
2. Select ang `librarian_db` database
3. Click ang `books` table
4. Makikita mo ang bagong libro sa list

#### Check sa Website:
1. Pumunta sa: `http://localhost/San%20Agustin/librarian/books.php`
2. Makikita mo ang complete catalog ng books
3. Pwede ka mag-search gamit ang search bar
4. Makikita mo ang details ng bawat libro:
   - Title
   - Author
   - ISBN
   - Publisher
   - Publication Year
   - Category
   - Quantity
   - Available copies

## Troubleshooting

### Problem: Hindi nag-save ang libro
**Solution:**
1. Check kung naka-on ang XAMPP (Apache at MySQL)
2. Run ulit ang `setup_books_table.php` para i-ensure na properly set up ang table
3. Check ang error message sa page
4. Kung duplicate ISBN, palitan ng unique ISBN

### Problem: Hindi makita ang bagong libro sa catalog
**Solution:**
1. Refresh ang books.php page (F5)
2. Check sa database kung naka-save talaga
3. Clear ang search filter kung meron

### Problem: Database connection error
**Solution:**
1. I-check kung running ang MySQL sa XAMPP
2. Verify ang database credentials sa `config/database.php`
3. I-ensure na existing ang `librarian_db` database

## Features ng Add Book Form

✅ **Automatic Table Creation** - Kung wala pang books table, automatic na gagawa
✅ **Form Validation** - Required fields ay validated
✅ **Duplicate Check** - Hindi pwedeng mag-duplicate ng ISBN
✅ **Success/Error Messages** - Clear feedback sa user
✅ **Auto-redirect** - Automatic redirect sa catalog after successful add
✅ **Form Retention** - Kung may error, naka-retain ang input values

## Database Structure

```sql
CREATE TABLE `books` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `isbn` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `publisher` varchar(100) DEFAULT NULL,
  `publication_year` year(4) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `available` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `isbn` (`isbn`)
)
```

## Important Files

- **add_book.php** - Form para mag-add ng new book
- **books.php** - Display ng lahat ng books sa catalog
- **setup_books_table.php** - Setup script para sa database
- **config/database.php** - Database configuration

---

**Note:** Siguraduhing naka-on ang XAMPP (Apache at MySQL) bago gumamit ng system!
