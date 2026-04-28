Update (Mar 16) | folder name: Gemar'sDevs | commit message: Faculty Homepage

on Folder Structure:
- pages > faculty > *.html - all pages related to faculty

on PHP Significance
* .html files with database integrations have "ALERT: PHP" as comments.
* Special Cases: 
    - "ALERT: PHP | DISPLAY" on text or headings - indicates login-specific display for text like whomever or ano iya first name (display: Welcome first name)


___

Update (Mar 8-12) | folder name: Gemar'sDevs | commit message: Gemar's Commit

on Folder Structure:
* pages > *.html - all subfolders
* script - JS or React context or use states

on CSS files:
* containers.css - styling for containers and modals in all .html
* global.css - global styling applicable for all .html
* landing.css - css for landing.html
* registration.css - css for both faculty and admin signup/login pages

on PHP Significance
* .html files with database integrations have "ALERT: PHP" as comments.
* Special Cases: 
    - "ALERT: PHP | REQUIRE FORMS" Comment on buttons - ensure form validation of preceding fields as well as if email is not validated or not.
    - "ALERT: PHP | DISPLAY" on text or headings - indicates login-specific display for text like whomever or ano iya first name (display: Welcome first name)
    - "ALERT: PHP | INPUT VALIDATION" Comment on input fields - ensure fields when attempted to be submitted have correct formatting (e.g. dots in M.I., proper casing on first and last names)

on JavaScript compilation
* expect .js scripts for the entire be compiled/organized for later reference. Elements required to be compiled have "ALERT: JS" as comments.

___

LumineSense — Project assets

Drop the official raster logo into the project root as `logo.png`.

Files updated to use `logo.png`:
- `luminesense_landign.html` (splash)
- `index.html` (login)
- `admin.html` and `faculty.html` (placeholders)

If you prefer a different filename, update the `src` attributes in those files or replace `logo.png` with the provided image.

To test locally, open `luminesense_landign.html` or `index.html` in your browser.
