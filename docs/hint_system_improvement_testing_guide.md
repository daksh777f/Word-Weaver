# CodeDungeon Hint System Improvement — Testing Guide

This guide contains manual tests to verify the enhanced hint system that provides specific, contextual hints based on the student's actual code rather than generic advice.

---

## TEST 1 — SPECIFICITY TEST

**Objective:** Verify that hints reference the student's actual code expressions.

**Steps:**
1. Open `play/bug-hunt.php?debug=1` in your browser
2. Load a challenge with an off-by-one error (e.g., "payment loop bug")
3. Type **exactly** this wrong code:
   ```javascript
   for(let i = 0; i <= payments.length; i++)
   ```
4. Wait 2 seconds for the debounce to fire
5. Check the hint bubble that appears

**Expected Result:**
- The hint **MUST** contain one of the student's actual expressions:
  - Contains `"payments.length"`
  - OR contains `"i <="`
  - OR references the variable name `"payments"`
- ✅ **PASS** example: "Your condition `i <= payments.length` will access `payments[5]` on a 5-item array — change `<=` to `<`"
- ❌ **FAIL** example: "Check your loop condition" (generic, no specific code reference)

**Debugging:**
- If hint is generic, check that `$challenge['bug_description']` and `$challenge['broken_code']` are being fetched in `intent_api.php`
- Add error logging: `error_log('Full prompt: ' . $userMessage)` before calling Cerebras
- Check `play/cerebras_errors.log` for API errors

---

## TEST 2 — NO HINT ON CORRECT CODE

**Objective:** Verify that the system doesn't hint when code is already correct.

**Steps:**
1. Open a challenge with an off-by-one error
2. Type the **correct fix**:
   ```javascript
   for(let i = 0; i < payments.length; i++)
   ```
3. Wait 2+ seconds for debounce
4. Wait another 12 seconds (to exceed rate limit)
5. Type more correct code or make a small comment change

**Expected Result:**
- No hint bubble should appear
- `should_show` must be `false` in the API response
- Check Network tab in DevTools: `intent_api.php` should return `{"should_show": false, "hint": ""}`

**Debugging:**
- If hint appears anyway, Cerebras may be ignoring RULE 2 in the system prompt
- Check debug panel (if `debug=1`) for the full API response
- Verify the system prompt was updated correctly in `intent_api.php`

---

## TEST 3 — MEANINGFUL CHANGE GATE

**Objective:** Verify that tiny changes don't trigger API calls.

**Steps:**
1. Open a challenge
2. Type exactly 1-2 characters (e.g., just `"i"` or `"let"`)
3. Stop typing for 3+ seconds — wait for debounce timer
4. Open DevTools → Network tab
5. Watch for `intent_api.php` call

**Expected Result:**
- **No** `intent_api.php` request should appear in Network tab
- The gate `shouldFireIntentApi()` should return `false`
- No hint bubble should show

**Next Step (to trigger API):**
6. Continue typing to add 3+ more characters (e.g., `"let i"`)
7. Wait 2 seconds
8. **Now** the API call should fire

**Debugging:**
- Check browser console for errors (should be none)
- Add `console.log('Gate result:', shouldFireIntentApi(current))` in JavaScript to debug
- Verify `minimumCharacterDelta = 3` in `bug-hunt.php` / `live-coding.php`

---

## TEST 4 — RATE LIMIT GATE

**Objective:** Verify that hints don't fire more than once per 10 seconds.

**Steps:**
1. Open a challenge
2. Type wrong code to trigger a hint (e.g., `for(let i=0; i<=5; i++)`)
3. Verify hint appears in bubble
4. Immediately type more wrong code variation (e.g., `for(let i=0; i<=arr.length; i++)`)
5. Wait **5 seconds total**
6. Stop typing and wait

**Expected Result:**
- **Only 1 hint** should have appeared (from step 3)
- The second change should NOT trigger another hint
- Network tab shows only 1 `intent_api.php` call at step 3

**Next Step (to verify rate limit reset):**
7. Continue waiting until **10+ seconds total** have passed since the first hint
8. Type another code change (e.g., add a space or adjust the loop)

**Expected Result:**
- **Now** a second hint should appear
- Network tab shows a second `intent_api.php` call
- Timing: ~10 seconds after the first call

**Debugging:**
- Verify `minimumTimeBetweenCalls = 10000` (milliseconds) in config
- Check `lastHintTime` is being updated correctly: `lastHintTime = Date.now()`
- Look for gate returning `false` in console logs

---

## TEST 5 — GAME TYPE ROUTING

**Objective:** Verify that the correct database table is queried based on game type.

**Steps:**

### For bug-hunt.php:
1. Open `play/bug-hunt.php` in a private/incognito browser tab
2. Open DevTools → Network tab
3. Type code to trigger a hint
4. Click on `intent_api.php` request in Network tab
5. Go to "Payload" or "Request" tab
6. Look for the JSON body

**Expected Result:**
- Should contain: `"game_type": "bug_hunt"`

### For live-coding.php:
1. Open `play/live-coding.php` in a private/incognito tab  
2. Repeat steps 2-5 above

**Expected Result:**
- Should contain: `"game_type": "live_coding"`

**Debugging:**
- If wrong table was queried, the hint will reference wrong code
- Example FAIL: Bug Hunt queried `bug_challenges` but description matches `live_coding_challenges`
- Check `intent_api.php` line where it routes: `if ($gameType === 'live_coding')...`

---

## TEST 6 — HINT SPECIFICITY LABEL

**Objective:** Verify that hints show "Based on what you wrote" label.

**Steps:**
1. Open any game page with a hint triggered
2. Look at the hint bubble in the bottom-right corner
3. Read the hint text

**Expected Result:**
- Above the hint text, should see:
  ```
  Based on what you wrote
  [then the actual hint below]
  ```
- NOT just the hint text alone
- Label should be small, muted gray color (rgba(255,255,255,0.7))

**Debugging:**
- Check `showHint()` function in `bug-hunt.php` — should have `labelDiv` creation
- Check `showHintBubble()` function in `live-coding.php` — same structure
- If label missing, the HTML is not being built correctly

---

## TEST 7 — FALLBACK ON API FAILURE

**Objective:** Verify graceful handling when Cerebras API fails.

**Steps:**
1. In `onboarding/config.php`, temporarily change `CEREBRAS_API_KEY` to a bad value:
   ```php
   define('CEREBRAS_API_KEY', 'invalid_key_12345');
   ```
2. Open a challenge
3. Type wrong code to trigger hint
4. Wait 2+ seconds

**Expected Result:**
- **No** hint bubble appears (graceful fallback)
- **No** JavaScript errors in console
- Check `play/cerebras_errors.log` — should contain an error logged
- User experience remains smooth

**Restore:**
5. Change the API key back to the correct value

**Debugging:**
- If error appears to user, check `intentFallback()` is being called
- Verify error logging is configured in `intent_api.php`

---

## COMMON FAILURES AND FIXES

### "Hint is still generic — doesn't contain student code"

**Possible Causes:**
1. `$challenge['bug_description']` or `$challenge['broken_code']` not fetched
2. User message not formatted correctly

**Fix:**
- Check the query in `intent_api.php`:
  - Bug Hunt: `SELECT id, title, bug_description, language, broken_code...`
  - Live Coding: `SELECT id, title, description as bug_description, language, starter_code as broken_code...`
- Add logging before Cerebras call:
  ```php
  error_log('User message: ' . $userMessage);
  ```
- Verify system prompt RULE 1 is included: "Reference their actual variable names"

---

### "No hints appearing at all"

**Possible Causes:**
1. Cerebras API key not configured
2. Rate limiting too aggressive
3. Gate functions always returning `false`
4. Challenge query returning no results

**Fix:**
- Check `CEREBRAS_API_KEY` is set in `.env`
- Check `play/cerebras_errors.log` for API errors
- In DevTools Console, add: `console.log('Gate:', shouldFireIntentApi(current))`
- Verify challenge exists in the correct table (check `challenge_id`)
- Check rate limit: `minimumTimeBetweenCalls = 10000` is 10 seconds, not too high

---

### "Hints appearing on every keystroke — not respecting debounce"

**Possible Causes:**
1. `shouldFireIntentApi()` gate is not wired into event listener
2. Gate function returning `true` too often
3. Debounce timer set too low

**Fix:**
- Check in `bug-hunt.php`/`live-coding.php`:
  ```javascript
  if (!shouldFireIntentApi(current)) {
    return;  // ← This line MUST be there
  }
  ```
- Verify gate checks all 4 conditions (time, delta, length, original code)
- Check debounce wait time: should be `900` ms
- Add logging: `console.log('Firing? ', shouldFireIntentApi(current))`

---

### "Hint does not contain student's variable name"

**Possible Cause:**
- Cerebras is ignoring the system prompt instruction to reference student code

**Fix:**
- Add stronger instruction to system prompt:
  ```
  If your hint does not contain at least one identifier 
  from the student's code (variable name, function name, etc), 
  rewrite it until it does. This is mandatory.
  ```
- Check the `check_specificity_generic_warning` in `intent_api.php`:
  - This logs when a hint doesn't contain the student's identifier
  - Check `play/cerebras_errors.log` for warnings

---

### "Gate fires incorrectly after small changes"

**Possible Cause:**
- `minimumCharacterDelta` threshold too low or logic incorrect

**Fix:**
- Verify the gate logic:
  ```javascript
  const lengthDiff = Math.abs(
    currentTrimmed.length - lastTrimmed.length
  );
  
  if (lengthDiff < minimumCharacterDelta && contentChanged) {
    return false;  // ← Too small a change, wait
  }
  ```
- `minimumCharacterDelta = 3` means: wait until 3+ characters have been added/removed
- Test: Add exactly 3 characters and verify the gate allows it

---

## SUCCESS CRITERIA

**All tests pass when:**
1. ✅ Hints reference student's actual code (TEST 1)
2. ✅ Correct code generates no hint (TEST 2)
3. ✅ Tiny changes ignored (TEST 3)
4. ✅ Only 1 hint per 10 seconds (TEST 4)
5. ✅ Correct game_type sent to API (TEST 5)
6. ✅ "Based on what you wrote" label appears (TEST 6)
7. ✅ API failures handled gracefully (TEST 7)
8. ✅ No JavaScript errors in console
9. ✅ No errors in `play/cerebras_errors.log` (except expected warnings)

**Feature is production-ready when all criteria pass.**
