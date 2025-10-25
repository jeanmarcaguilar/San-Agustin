# Teacher Announcement Integration

## Overview
This document describes the integration of teacher announcements into the student portal, allowing teachers to post class-related announcements that students can view alongside general school announcements.

## Features

### For Teachers
- **Create Announcements**: Teachers can create announcements from their portal (`teacher/announcements.php`)
- **Target Audience Options**:
  - All users
  - Students only
  - Specific grade level
  - Specific section
  - Specific class
- **Priority Levels**: Announcements can be pinned (high priority) or regular (medium priority)
- **Date Range**: Set start and end dates for announcements
- **Status Management**: Announcements can be draft, published, or archived

### For Students
- **Unified View**: Students see both school-wide and teacher announcements in one place
- **Smart Filtering**: Only relevant announcements are shown based on student's grade and section
- **Visual Distinction**: 
  - Teacher announcements: Purple badge with "Class Announcement" label
  - School announcements: Blue badge with "School Announcement" label
- **Posted By**: Shows who posted the announcement (teacher name or "School Administration")
- **Priority Indicators**: High, Medium, and Low priority badges
- **Read/Unread Status**: Track which announcements have been viewed

## Database Structure

### Teacher Database (`teacher_db`)
```sql
-- Announcements table
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `target_audience` enum('all','specific_class','specific_grade','specific_section') NOT NULL DEFAULT 'all',
  `target_class_id` int(11) DEFAULT NULL,
  `target_grade` int(11) DEFAULT NULL,
  `target_section` varchar(10) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Announcement views table
CREATE TABLE IF NOT EXISTS `announcement_views` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `announcement_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('teacher','student','parent') NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_view` (`announcement_id`, `user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Student Database (`student_db`)
```sql
-- Announcements table (for school-wide announcements)
CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `start_date` datetime NOT NULL,
  `end_date` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Announcement views table
CREATE TABLE `announcement_views` (
  `id` int(11) NOT NULL,
  `announcement_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Implementation Details

### API Endpoints

#### 1. `api/get_announcements.php`
**Purpose**: Fetch all announcements (both school and teacher) for a student

**Method**: GET

**Authentication**: Requires active session with `user_id` and `role`

**Response**:
```json
{
  "success": true,
  "announcements": [
    {
      "id": 1,
      "title": "Math Quiz Next Week",
      "description": "There will be a quiz on Chapter 1 next Monday...",
      "priority": "high",
      "start_date": "2025-10-20",
      "end_date": "2025-10-25",
      "is_read": false,
      "source": "teacher",
      "posted_by": "John Doe",
      "formatted_date": "Oct 20, 2025"
    },
    {
      "id": 2,
      "title": "School Event Next Week",
      "description": "Join us for the annual school fair...",
      "priority": "high",
      "start_date": "2025-10-01 08:00:00",
      "end_date": "2025-10-07 17:00:00",
      "is_read": true,
      "source": "school",
      "posted_by": "School Administration",
      "formatted_date": "Oct 1, 2025"
    }
  ]
}
```

**Logic**:
1. Fetches student information (grade level and section)
2. Retrieves school-wide announcements from student database
3. Retrieves relevant teacher announcements based on:
   - Target audience = 'all'
   - Target grade matches student's grade
   - Target section matches student's section
   - Target class matches student's class
4. Merges and sorts announcements by priority and date

#### 2. `api/get_teacher_announcements.php`
**Purpose**: Fetch only teacher announcements for a student (standalone endpoint)

**Method**: GET

**Authentication**: Requires active session

**Response**: Similar to `get_announcements.php` but only includes teacher announcements

#### 3. `api/save_announcement.php`
**Purpose**: Save a new announcement (teacher only)

**Method**: POST

**Authentication**: Requires teacher role

**Request Body**:
```json
{
  "title": "Math Quiz Next Week",
  "content": "There will be a quiz on Chapter 1 next Monday...",
  "type": "important",
  "target": "students",
  "pinned": false,
  "teacher_id": "T-001",
  "teacher_name": "John Doe",
  "start_date": "2025-10-20",
  "end_date": "2025-10-25"
}
```

### Student Portal Integration

#### File: `student/announcements.php`

**Key Changes**:
1. **Dual Database Connection**: Connects to both student and teacher databases
2. **Merged Announcements**: Combines announcements from both sources
3. **Smart Filtering**: Filters teacher announcements based on student's grade/section
4. **Source Tracking**: Each announcement includes a `source` field ('teacher' or 'school')
5. **Visual Differentiation**: Different badges and icons for teacher vs school announcements

**Display Features**:
- **Badge Colors**:
  - Teacher announcements: Purple background (`bg-purple-50 text-purple-600`)
  - School announcements: Blue background (`bg-blue-50 text-blue-600`)
- **Icons**:
  - Teacher: `fa-chalkboard-teacher`
  - School: `fa-school`
- **Posted By**: Shows teacher name or "School Administration"

### Teacher Portal

#### File: `teacher/announcements.php`

**Features**:
- Create new announcements with modal form
- Select target audience (all, students, specific grade/section/class)
- Set priority (via pinned checkbox)
- Set date range
- View published announcements
- Manage drafts
- View announcement statistics

## Usage Examples

### Teacher Creating an Announcement

1. Navigate to `teacher/announcements.php`
2. Click "New Announcement" button
3. Fill in the form:
   - **Title**: "Math Quiz Next Week"
   - **Content**: "There will be a quiz on Chapter 1 next Monday. Please review sections 1.1 to 1.5."
   - **Type**: Important Notice
   - **Target Audience**: Select specific class (e.g., "Math - Grade 6A")
   - **Start Date**: 2025-10-20
   - **End Date**: 2025-10-25
   - **Pin**: Check if high priority
4. Click "Publish Announcement"

### Student Viewing Announcements

1. Navigate to `student/announcements.php`
2. View all announcements sorted by priority and date
3. See visual indicators:
   - Purple badge = Teacher announcement
   - Blue badge = School announcement
   - "Posted by [Teacher Name]" or "Posted by School Administration"
4. Click "Read more" to view full announcement
5. Announcement is automatically marked as read

## Filtering Logic

### Teacher Announcements Shown to Students

A student will see a teacher announcement if:
- **Status** = 'published'
- **End date** is NULL or >= current date
- **AND** one of the following:
  - `target_audience` = 'all'
  - `target_audience` = 'specific_grade' AND `target_grade` = student's grade
  - `target_audience` = 'specific_section' AND `target_grade` = student's grade AND `target_section` = student's section
  - `target_audience` = 'specific_class' AND `target_class_id` matches a class the student is enrolled in

## Priority System

### Priority Levels
1. **High**: Red badge - Pinned teacher announcements or high-priority school announcements
2. **Medium**: Yellow badge - Regular teacher announcements or medium-priority school announcements
3. **Low**: Blue badge - Low-priority school announcements

### Sorting Order
1. Priority (High → Medium → Low)
2. Date (Newest → Oldest)

## Read Tracking

### Student Database
- Tracks views in `announcement_views` table
- Fields: `announcement_id`, `student_id`, `is_read`, `read_at`

### Teacher Database
- Tracks views in `announcement_views` table
- Fields: `announcement_id`, `user_id`, `user_type`, `viewed_at`
- `user_type` = 'student' for student views

## Testing

### Test Scenarios

1. **Teacher creates announcement for all students**
   - Create announcement with target_audience = 'all'
   - Verify all students can see it

2. **Teacher creates announcement for specific grade**
   - Create announcement with target_audience = 'specific_grade' and target_grade = 6
   - Verify only Grade 6 students can see it

3. **Teacher creates announcement for specific section**
   - Create announcement with target_audience = 'specific_section', target_grade = 6, target_section = 'A'
   - Verify only Grade 6A students can see it

4. **Student views announcement**
   - Student clicks on announcement
   - Verify announcement is marked as read
   - Verify read status persists on page reload

5. **Mixed announcements display**
   - Create both school and teacher announcements
   - Verify both appear in student portal
   - Verify proper visual distinction (badges, icons, posted by)

## Troubleshooting

### Issue: Teacher announcements not showing
**Solution**: 
- Check student's grade_level and section in students table
- Verify teacher announcement target_audience settings
- Check announcement status is 'published'
- Verify end_date is NULL or in the future

### Issue: Duplicate announcements
**Solution**:
- Check for duplicate announcement IDs across databases
- Ensure proper source tracking ('teacher' vs 'school')

### Issue: Read status not updating
**Solution**:
- Check announcement_views table in appropriate database
- Verify student_id/user_id matches session user_id
- Check database connection is successful

## Future Enhancements

1. **Real-time Notifications**: Implement WebSocket or SSE for instant notification of new announcements
2. **Rich Text Editor**: Add WYSIWYG editor for announcement content
3. **File Attachments**: Allow teachers to attach files to announcements
4. **Comments/Replies**: Enable students to comment on announcements
5. **Analytics Dashboard**: Show teachers which students have read their announcements
6. **Email Notifications**: Send email alerts for high-priority announcements
7. **Mobile App Integration**: Extend to mobile application

## Security Considerations

1. **Authentication**: All endpoints verify user session and role
2. **Authorization**: Students can only view announcements relevant to them
3. **SQL Injection Prevention**: All queries use prepared statements
4. **XSS Prevention**: All output is properly escaped with htmlspecialchars()
5. **CSRF Protection**: Consider adding CSRF tokens for POST requests

## Maintenance

### Regular Tasks
1. Archive old announcements (end_date passed by > 30 days)
2. Clean up orphaned announcement_views records
3. Monitor database size and optimize if needed
4. Review and update target audience logic as needed

### Database Optimization
- Add indexes on frequently queried fields (status, start_date, end_date, target_grade, target_section)
- Consider partitioning announcement_views table if it grows large
- Implement soft deletes instead of hard deletes for audit trail

## Support

For issues or questions regarding this integration, please contact the development team or refer to the main project documentation.
