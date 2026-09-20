-- Phase 72: "allow the 'Best regards' prefilled text to be edited" -- run
-- this once via phpMyAdmin against the live database before deploying
-- this phase's code -- db/schema.sql has already been updated to match,
-- so a FRESH install never needs this file, only the already-live one.
--
-- Separates the letter composer's sign-off PHRASE from the signer's NAME
-- (from_line): closing_line is edited the same single-line contenteditable
-- way to_line/from_line already are, independent of from_line, so a
-- shortened name and a customized phrase don't disturb each other. NULL
-- on every letter already sent -- every render site falls back to the
-- original literal "Best regards," in that case, so old letters look
-- exactly as they always did. See create_letter()/
-- save_letter_copy_to_timeline() in includes/letters.php and letter.php's
-- send action.
ALTER TABLE letters
  ADD COLUMN closing_line VARCHAR(80) NULL AFTER from_line;
