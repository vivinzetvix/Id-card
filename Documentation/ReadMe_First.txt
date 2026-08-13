README FIRST - ID CARD MANAGEMENT SYSTEM
========================================

Welcome! Please read this file first before running the project.

QUICK START (5 STEPS)
---------------------
1) Copy project to web root (example):
   e:\XAMPP\htdocs\id

2) Install PHP dependencies:
   composer install

3) Create MySQL database (example name: id) and import:
   id.sql

4) Update database credentials in:
   config.php

5) Start Apache + MySQL and open:
   http://localhost/id/id/

DEFAULT FLOW
------------
- Login from index.php

Admin username : admin
Admin Password : admin123

- Add members from Add Member page
- Go to Templates page
- Preview / Print / Download ID cards

IMPORTANT FOLDERS
-----------------
- images/uploads/      (member photos)
- images/avatars/      (profile/avatars)
- saved_cards/         (saved cards)
- backups/             (database backups)

Make sure above folders are writable.

IF SOMETHING FAILS
------------------
A) White screen / PHP error:
- Enable PHP error reporting temporarily.
- Check config.php DB settings.

B) "vendor/autoload.php not found":
- Run: composer install

C) Header warning (Cannot modify header information):
- Remove/avoid output before header() calls.

D) PDF issues:
- Use latest Chrome/Edge.
- Check internet for CDN resources.

DOCUMENTS TO READ NEXT
----------------------
1) Documentation/Installation Guide.txt
2) Documentation/Requirements.txt
3) MIGRATION_GUIDE.md (if applicable)
4) QUICK_REFERENCE.txt

PROJECT ENTRY FILES
-------------------
- index.php        (login)
- dashboard.php    (main dashboard)
- templates.php    (template designer)
- print_card.php   (print/PDF output)

END
