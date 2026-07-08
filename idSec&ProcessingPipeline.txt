Viewed purge-expired-quarantine.php:38-38
Viewed faculty-signup-process.php:1-250
Viewed admin-signup-process.php:1-220


### Step 1: Uploading & Temporary Storage
1. When a user fills out the signup form and uploads an ID photo, the backend saves the image **only to a temporary folder** on the server (e.g., `/tmp/idcheck_a8b9c...jpg`) using `sys_get_temp_dir()`. 
2. It is **never** stored in the public `/uploads/` folder.

---

### Step 2: Google Cloud Vision API Call
1. The system reads the raw image bytes from the temporary path into memory (keeping a copy in memory in case it needs to be sent to quarantine).
2. It calls the [`IdVerifier`](file:///c:/xampp/htdocs/LUMINESENSE-finals/php/idverifier.php) class, which sends the image to the **Google Cloud Vision API** to request two signals:
   * **`TEXT_DETECTION`:** Extracting all text printed on the ID.
   * **`FACE_DETECTION`:** Finding if there is a face photo present on the ID.
3. **Immediate Deletion:** Immediately after the Vision API returns the results, the temporary file on disk is **overwritten with random bytes and permanently deleted (unlinked)** from the server.

---

### Step 3: AI Verdict Evaluation
The [`IdVerifier::evaluate()`](file:///c:/xampp/htdocs/LUMINESENSE-finals/php/idverifier.php#L133-L181) method runs the text and face signals through three rules:
* **Name Match (Required):** The first and last name filled in the signup form must exist in the extracted ID text.
* **Supporting Signals (Required):** The ID must have **either** a face photo **or** at least one institutional keyword (e.g., *faculty*, *administrator*, *uno-r*, *school*). This prevents someone from bypassing the system by uploading a blank sheet of paper with their name written on it.

This yields one of three statuses:
1. **`matched`:** Both name and supporting signals are verified.
2. **`mismatched`:** Name doesn't match, or supporting signals are missing.
3. **`unreadable`:** No text detected, or the Vision API request failed.

---

### Step 4: Branching Logic
#### Scenario A: If AI status is `matched` (Success)
* The user's account is inserted into the DB (e.g., `admins` or `faculty`) with `is_verified = 0` (pending OTP verification).
* **The ID image is discarded entirely** from memory. No photo is ever stored.

#### Scenario B: If AI status is `mismatched` or `unreadable` (Quarantine)
* The user's account is still created (allowing them to receive their OTP so their signup isn't abruptly broken).
* **Encryption:** The raw image bytes in memory are encrypted using **AES-256-GCM** via the [`IdQuarantine`](file:///c:/xampp/htdocs/LUMINESENSE-finals/php/idquarantine.php) class and the secret key you just configured in `config.php`.
* **Database Insert:** The base64-encoded encrypted blob is inserted into the `id_review_queue` table with a **24-hour expiration window**. The plain text image bytes are immediately cleared from memory.

---

### Step 5: Admin Review & Purging
1. **Human Review:** An existing admin views the pending queue. When they request to inspect the ID, [`review-id.php`](file:///c:/xampp/htdocs/LUMINESENSE-finals/api/review-id.php#L67-L87) decrypts the blob in memory and streams it directly to the browser (again, it is never saved to the disk).
2. **Review Decision:** 
   * **Approve:** The account is verified, and the `encrypted_blob` in the database is set to `NULL` (permanently deleted).
   * **Reject:** The account is set to unverified.
3. **Auto-Purge:** If no admin reviews the registration within 24 hours, the [`purge-expired-quarantine.php`](file:///c:/xampp/htdocs/LUMINESENSE-finals/script/purge-expired-quarantine.php) script (which we just fixed) automatically clears the encrypted blob and deletes the queue record.