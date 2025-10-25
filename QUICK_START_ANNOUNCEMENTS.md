# Quick Start Guide - Teacher Announcements

## Paano Gumawa ng Announcement (Tagalog)

### Para sa Teachers:

1. **Mag-login** sa teacher account
   - Pumunta sa `http://localhost/San%20Agustin/login.php`
   - I-enter ang teacher username at password

2. **Pumunta sa Announcements Page**
   - Click ang "Announcements" sa sidebar
   - O direktang pumunta sa `http://localhost/San%20Agustin/teacher/announcements.php`

3. **Gumawa ng Bagong Announcement**
   - Click ang **"New Announcement"** button (green button sa taas)
   - Pupunta sa modal form

4. **Punan ang Form**
   - **Title** (required): Lagay ang title ng announcement
     - Example: "Math Quiz Next Week"
   
   - **Content** (required): Isulat ang buong mensahe
     - Example: "May quiz tayo sa Math next Monday. Please review Chapter 1-3."
   
   - **Type**: Piliin ang uri ng announcement
     - General Announcement
     - Important Notice
     - Event
     - Reminder
   
   - **Target Audience**: Piliin kung sino ang makakakita
     - **All Users** - Lahat ng students
     - **Students Only** - Students lang
     - **Specific Classes** - Specific class lang (kung may classes ka)
   
   - **Start Date**: Kailan magsisimula ang announcement (optional)
   
   - **End Date**: Kailan matatapos ang announcement (optional)
   
   - **Pin this announcement**: I-check kung gusto mo nasa taas (high priority)

5. **I-publish ang Announcement**
   - Click ang **"Publish Announcement"** button
   - Maghintay ng success message
   - Makikita mo agad sa list ng announcements

### Para sa Students:

1. **Mag-login** sa student account
   - Pumunta sa `http://localhost/San%20Agustin/login.php`
   - I-enter ang student username at password

2. **Pumunta sa Announcements Page**
   - Click ang "Announcements" sa sidebar
   - O direktang pumunta sa `http://localhost/San%20Agustin/student/announcements.php`

3. **Makikita ang Announcements**
   - **Purple badge** = Galing sa teacher (Class Announcement)
   - **Blue badge** = Galing sa school (School Announcement)
   - Makikita ang name ng teacher na nag-post
   - Automatic na nag-refresh every 30 seconds

4. **Basahin ang Announcement**
   - Click ang "Read more" para makita ang buong announcement
   - Automatic na ma-mark as "read"

---

## How to Create Announcements (English)

### For Teachers:

1. **Login** to your teacher account
   - Go to `http://localhost/San%20Agustin/login.php`
   - Enter your teacher credentials

2. **Navigate to Announcements**
   - Click "Announcements" in the sidebar
   - Or go directly to `http://localhost/San%20Agustin/teacher/announcements.php`

3. **Create New Announcement**
   - Click the **"New Announcement"** button (green button at the top)
   - A modal form will appear

4. **Fill in the Form**
   - **Title** (required): Enter announcement title
     - Example: "Math Quiz Next Week"
   
   - **Content** (required): Write your full message
     - Example: "There will be a quiz on Monday covering Chapter 1-3. Please review."
   
   - **Type**: Select announcement type
     - General Announcement
     - Important Notice
     - Event
     - Reminder
   
   - **Target Audience**: Choose who can see it
     - **All Users** - Everyone
     - **Students Only** - Students only
     - **Specific Classes** - Only students in selected class
   
   - **Start Date**: When announcement becomes active (optional)
   
   - **End Date**: When announcement expires (optional)
   
   - **Pin this announcement**: Check to make it high priority (appears at top)

5. **Publish the Announcement**
   - Click **"Publish Announcement"** button
   - Wait for success message
   - Announcement appears immediately in your list

### For Students:

1. **Login** to your student account
   - Go to `http://localhost/San%20Agustin/login.php`
   - Enter your student credentials

2. **Navigate to Announcements**
   - Click "Announcements" in the sidebar
   - Or go directly to `http://localhost/San%20Agustin/student/announcements.php`

3. **View Announcements**
   - **Purple badge** = From teacher (Class Announcement)
   - **Blue badge** = From school (School Announcement)
   - See teacher name who posted it
   - Auto-refreshes every 30 seconds for new announcements

4. **Read Announcement**
   - Click "Read more" to see full details
   - Automatically marked as "read"

---

## Features

### Teacher Features
✅ Create announcements  
✅ Edit announcements  
✅ View announcement details  
✅ Target specific classes  
✅ Pin important announcements  
✅ Set start/end dates  
✅ View announcement statistics  
✅ See view counts  

### Student Features
✅ View all relevant announcements  
✅ See teacher and school announcements together  
✅ Visual distinction (purple vs blue badges)  
✅ Auto-refresh for new announcements  
✅ Mark as read tracking  
✅ Search and filter announcements  
✅ Priority sorting  

---

## Troubleshooting

### "Column not found" error
**Fixed!** The API now uses the correct database columns.

### Announcement not appearing in student portal
**Check:**
1. Announcement status is "published" (not draft)
2. End date is not in the past
3. Target audience includes the student's class/grade
4. Wait 30 seconds for auto-refresh or click refresh button

### Cannot edit announcement
**Check:**
1. You are logged in as the teacher who created it
2. The announcement exists in the database

### View/Edit buttons not working
**Fixed!** Created `view_announcement.php` and `edit_announcement.php`

---

## Testing

### Quick Test
1. Run `http://localhost/San%20Agustin/test_announcement_integration.php`
2. Check all tests pass (green checkmarks)
3. Follow the "Next Steps" at the bottom

### Manual Test
1. Login as teacher
2. Create announcement
3. Logout
4. Login as student
5. Check if announcement appears (purple badge)
6. Click "Read more"
7. Verify it marks as read

---

## File Locations

### Teacher Pages
- Main: `teacher/announcements.php`
- View: `teacher/view_announcement.php`
- Edit: `teacher/edit_announcement.php`

### Student Pages
- Main: `student/announcements.php`

### API Endpoints
- Save: `api/save_announcement.php`
- Get All: `api/get_announcements.php`
- Get Teacher Only: `api/get_teacher_announcements.php`

### Documentation
- Integration Guide: `TEACHER_ANNOUNCEMENT_INTEGRATION.md`
- Fixes Summary: `ANNOUNCEMENT_FIXES_SUMMARY.md`
- Quick Start: `QUICK_START_ANNOUNCEMENTS.md` (this file)

---

## Support

Kung may problema o tanong, check ang:
1. Browser console (F12) para sa errors
2. `logs/php_errors.log` para sa server errors
3. Documentation files sa root folder

If you have issues or questions, check:
1. Browser console (F12) for errors
2. `logs/php_errors.log` for server errors
3. Documentation files in root folder

---

**Status**: ✅ READY TO USE  
**Last Updated**: October 15, 2025  
**Version**: 1.0
