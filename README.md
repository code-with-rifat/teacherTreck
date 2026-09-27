# MEDICO — System flow (4 roles)

Login: http://localhost/teacherTreck/login.php

## 1) Teacher
- **Register** (new user): name, phone + emergency, DOB, medical college, email, address → Admin activates
- **Login** + **Forgot password**
- **Classes**: sees assigned classes (multi batch 7/10/1/4 possible)
- **Check-in** (GPS inside Admin radius) → **Start** logged
- **End class**: student count + quality (Best/Good/Average/Repeat) + notes
- **Branch review** after end: cleaning, fan/AC/sound/projector, staff, reminder call?, transport only if outside Dhaka
- **Profile**: quality counts, check-in history, password change

## 2) Branch Manager (branch-only)
- First login → **Branch location** upload (required)
- **Dashboard**: summary only
- **Teachers** module: registered pool
- **Classes** module: create class → teacher + time + 1st/2nd Timer
- **Track class**: reminder done?, teacher in/out times, quality + branch review, verify students + signature sheet
- Check-in **radius** set by Admin (meters)

## 3) Admin
- Activate teachers
- Set global check-in radius (meters)
- **Class reviews**: per-class quality + full branch logistics (⋯ details)

## 4) Super Admin
- Create users / roles
- Assign manager to branch
- System settings + link to Admin console

## Demo logins
| Role | Email | Password |
|------|-------|----------|
| Teacher | teacher1@medico.local | Teacher@123 |
| Manager | manager.dhanmondi@medico.local | Manager@123 |
| Admin | admin@medico.local | Admin@123 |
| Super | superadmin@medico.local | SuperAdmin@123 |
