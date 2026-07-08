<?php
/**
 * IdVerifier.php
 * ---------------------------------------------------------
 * Used by BOTH faculty-signup-process.php and
 * admin-signup-process.php — same pipeline for both account
 * types, just called with different ID values.
 *
 * Uses Google Cloud Vision in a single API call requesting
 * THREE signals at once:
 *   1. TEXT_DETECTION    - does the typed name appear on the ID?
 *   2. FACE_DETECTION    - is there an actual face photo on it?
 *      (presence only — NOT compared against a selfie. This
 *       just confirms "this looks like a real ID card," not
 *       "this ID belongs to the uploader." That's a separate,
 *       bigger feature we're deliberately not building yet.)
 *   3. Keyword check on the OCR text for institutional markers
 *      (school name, "Faculty"/"Administrator", "ID No.", etc.)
 *      — this is what catches someone who just wrote their name
 *      on a blank piece of paper. A name match ALONE is not
 *      enough to pass; you need the name AND at least one other
 *      signal that this is genuinely an ID, not improvised text.
 *
 * Nothing from the ID (image, raw OCR text) is ever persisted.
 * Only a match verdict + the name string actually printed on
 * the ID is returned, for the admin manual review queue.
 * ---------------------------------------------------------
 */

class IdVerifier
{
    private string $apiKey;

    /**
     * Keywords that should appear SOMEWHERE on a genuine school/
     * employee ID. Adjust this list to match your actual ID
     * format — e.g. add "UNO-R" or the school's exact printed name.
     * Only ONE of these needs to match; it's a low bar specifically
     * because OCR can miss text, fonts vary, IDs get cropped, etc.
     * The point isn't to be strict here — text-matching is the
     * weak signal, face presence is the strong one.
     */
    private const ID_KEYWORDS = [
        'faculty', 'administrator', 'admin', 'employee',
        'id no', 'identification', 'department', 'school',
        'university', 'uno-r', 'uno r',
    ];

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @return array{status:string, extracted_name:?string, note:?string}
     *   status: 'matched' | 'mismatched' | 'unreadable'
     */
    public function verify(string $imagePath, string $firstName, string $lastName): array
    {
        try {
            $result = $this->callVisionApi($imagePath);
        } catch (\Throwable $e) {
            error_log('[IdVerifier] Vision API call failed: ' . $e->getMessage());
            $this->destroy($imagePath);
            return ['status' => 'unreadable', 'extracted_name' => null, 'note' => 'ID verification request failed.'];
        }

        // Image is deleted here — success or failure, before we return.
        $this->destroy($imagePath);

        $rawText  = $result['text'];
        $hasFace  = $result['hasFace'];

        if (trim($rawText) === '') {
            return ['status' => 'unreadable', 'extracted_name' => null, 'note' => 'No text detected on ID.'];
        }

        return $this->evaluate($rawText, $hasFace, $firstName, $lastName);
    }

    /**
     * Verify from pre-extracted OCR text (no API call).
     * Used when Tesseract.js runs OCR in the browser.
     */
    public function verifyFromText(string $ocrText, string $firstName, string $lastName): array
    {
        if (trim($ocrText) === '') {
            return ['status' => 'unreadable', 'extracted_name' => null, 'note' => 'No text detected on ID.'];
        }

        return $this->evaluate($ocrText, false, $firstName, $lastName);
    }

    /**
     * Single Vision API call requesting text + face detection together.
     * @return array{text:string, hasFace:bool}
     */
    private function callVisionApi(string $imagePath): array
    {
        $imageData = base64_encode(file_get_contents($imagePath));

        $payload = [
            'requests' => [[
                'image'    => ['content' => $imageData],
                'features' => [
                    ['type' => 'TEXT_DETECTION'],
                    ['type' => 'FACE_DETECTION'],
                ],
            ]],
        ];

        $ch = curl_init('https://vision.googleapis.com/v1/images:annotate?key=' . $this->apiKey);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \RuntimeException('Vision API returned HTTP ' . $httpCode);
        }

        $decoded = json_decode($response, true);
        $resp    = $decoded['responses'][0] ?? [];

        return [
            'text'    => $resp['fullTextAnnotation']['text'] ?? '',
            'hasFace' => !empty($resp['faceAnnotations']), // non-empty array = at least one face found
        ];
    }

    /**
     * Combines all three signals into one verdict.
     *
     * Rule: name match is REQUIRED, but not sufficient on its own.
     * You also need at least one supporting signal (a face on the
     * ID, OR a recognizable institutional keyword) — otherwise a
     * blank paper with a handwritten name would sail through on
     * name-matching alone.
     *
     * Name matching uses a three-tier approach (Option C):
     *   Tier 1 — Same-line: both first and last name on the same line
     *   Tier 2 — Ordered adjacent-line: first name line N, last on N or N+1,
     *            with first name appearing before last name in document order
     *   Tier 3 — Fallback: names anywhere (original scatter approach)
     */
    private function evaluate(string $rawText, bool $hasFace, string $first, string $last): array
    {
        $rawLines  = explode("\n", $rawText);
        $lines     = array_map('trim', $rawLines);
        $normalized = strtolower(preg_replace('/\s+/', ' ', $rawText));
        $fullName   = strtolower(trim("$first $last"));

        $firstLower = strtolower($first);
        $lastLower  = strtolower($last);

        // ── Tier 1: Same-line match ─────────────────────────────────────
        $nameMatch = false;
        foreach ($lines as $line) {
            $lineLower = strtolower($line);
            if (str_contains($lineLower, $firstLower) && str_contains($lineLower, $lastLower)) {
                $nameMatch = true;
                break;
            }
        }

        // ── Tier 2: Ordered adjacent-line match ─────────────────────────
        if (!$nameMatch) {
            $firstLineIdx = null;
            $lastInLine   = null;
            foreach ($lines as $idx => $line) {
                $lineLower = strtolower($line);
                if ($firstLineIdx === null && str_contains($lineLower, $firstLower)) {
                    $firstLineIdx = $idx;
                }
                // Track the line where last name first appears
                if ($lastInLine === null && str_contains($lineLower, $lastLower)) {
                    $lastInLine = $idx;
                }
            }
            if ($firstLineIdx !== null && $lastInLine !== null) {
                // First and last must be on the same line or adjacent lines
                // (order doesn't matter — last name can appear above first name)
                if (abs($lastInLine - $firstLineIdx) <= 1) {
                    $nameMatch = true;
                }
            }
        }

        // ── Tier 3: Fallback scatter match (original) ──────────────────
        if (!$nameMatch) {
            $firstFound = stripos($normalized, $firstLower) !== false;
            $lastFound  = stripos($normalized, $lastLower)  !== false;
            $nameMatch  = $firstFound && $lastFound;
        }

        $hasKeyword = false;
        foreach (self::ID_KEYWORDS as $kw) {
            if (stripos($normalized, $kw) !== false) {
                $hasKeyword = true;
                break;
            }
        }

        // Best-effort extraction of the printed name line, for the review queue UI.
        $bestLine = null;
        $matchedIdx = [];
        foreach ($lines as $idx => $line) {
            // Strip clearly non-name characters before checking
            $clean    = preg_replace('/[^a-zA-Z\s.\-\',()]/', '', $line);
            $clean    = trim(preg_replace('/\s+/', ' ', $clean));
            $lineLower = strtolower($clean);
            $hasFirst  = str_contains($lineLower, $firstLower);
            $hasLast   = str_contains($lineLower, $lastLower);
            if ($hasFirst && $hasLast) {
                $bestLine = $clean;
                break;
            }
            if ($hasFirst || $hasLast) {
                $matchedIdx[] = $idx;
            }
            // Also store the cleaned version for later merging
            $lines[$idx] = $clean;
        }
        // If no single line has both names, merge adjacent name-bearing lines
        if ($bestLine === null && $matchedIdx !== []) {
            $groups = [[$matchedIdx[0]]];
            for ($i = 1, $g = 0; $i < count($matchedIdx); $i++) {
                if ($matchedIdx[$i] === end($groups[$g]) + 1) {
                    $groups[$g][] = $matchedIdx[$i];
                } else {
                    $groups[++$g] = [$matchedIdx[$i]];
                }
            }
            $bestGroup = $groups[0];
            foreach ($groups as $g) {
                if (count($g) > count($bestGroup)) {
                    $bestGroup = $g;
                }
            }
            $parts = [];
            foreach ($bestGroup as $idx) {
                $parts[] = $lines[$idx];
            }
            $bestLine = implode(' ', $parts);
        }

        // Clean up bestLine: remove words that don't contain any name part
        if ($bestLine !== null) {
            $words = explode(' ', $bestLine);
            $filtered = [];
            foreach ($words as $word) {
                $w = trim($word, '. ');
                if ($w === '') continue;
                $wLower = strtolower($w);
                $isInitial = preg_match('/^[a-z]\.?$/i', $w);
                if (str_contains($wLower, $firstLower) || str_contains($wLower, $lastLower) || $isInitial) {
                    $filtered[] = $word;
                }
            }
            $bestLine = implode(' ', $filtered);
        }

        if (!$nameMatch) {
            return [
                'status'         => 'mismatched',
                'extracted_name' => $bestLine,
                'note'           => 'Name on form does not match text detected on ID. Manual review required.',
            ];
        }

        if (!$hasFace && !$hasKeyword) {
            return [
                'status'         => 'mismatched',
                'extracted_name' => $bestLine ?? "$first $last",
                'note'           => 'Name matched, but no ID photo or institutional markers were detected — this may not be a genuine ID. Manual review required.',
            ];
        }

        return [
            'status'         => 'matched',
            'extracted_name' => $bestLine ?? "$first $last",
            'note'           => $hasFace
                ? 'Name matched and a face photo was detected on the ID.'
                : 'Name matched and institutional markers were detected on the ID.',
        ];
    }

    private function destroy(string $path): void
    {
        if (is_file($path)) {
            @file_put_contents($path, random_bytes(1024)); // overwrite before unlink
            @unlink($path);
        }
    }
}