"use strict";
/*
 * Phase 71: "when a day or month input is completed, automatically tab
 * the cursor to the next box... so a completed day entry automatically
 * tabs to the month entry box, and completed month entry tabs to the
 * year."
 *
 * Shared across every page with a day/month/year date-entry triplet --
 * add_entry.php (when a memory happened), edit_person.php (born/died,
 * two triplets), and timeline.php (the born-date prompt, the Memory
 * planner's start/finish dates, and its own event rows, added to the
 * DOM later from a JS template well after this script has already run).
 * Every one of them is the same markup shape -- three ".date-slot" divs,
 * each holding one <input maxlength="2|2|4"> for DD / MM / YYYY, as
 * direct siblings under one common parent -- so one delegated listener
 * here, on `document`, covers all of them (present now or added later)
 * rather than each page wiring its own triplet by id. That shared
 * parent is usually ".row-3", but not always -- edit_person.php's own
 * born/died rows use ".date-row" instead -- so this reads straight off
 * el.parentElement.parentElement (the .date-slot's own parent) rather
 * than hunting for a specific wrapper class, which also means a future
 * page doesn't need to reuse ".row-3" by name to get this for free.
 *
 * "Completed" means the field reached its own maxlength with nothing
 * but digits in it. A text input's maxlength is a hard cap the browser
 * already enforces on typing and pasting -- value.length can never
 * exceed it -- so reaching it is exactly the moment there's no more
 * room left to type into this box anyway, the same instant a person
 * would otherwise reach for Tab themselves.
 */
(function () {
  document.addEventListener("input", function (evt) {
    var el = evt.target;
    if (!el.matches || !el.matches(".date-slot input[maxlength]")) return;
    if (el.value.length !== el.maxLength) return;
    if (!/^[0-9]+$/.test(el.value)) return;

    var slotEl = el.closest(".date-slot");
    var row = slotEl ? slotEl.parentElement : null;
    if (!row) return;
    var slots = Array.prototype.slice.call(row.querySelectorAll(":scope > .date-slot input"));
    var i = slots.indexOf(el);
    if (i === -1 || i + 1 >= slots.length) return;

    var next = slots[i + 1];
    next.focus();
    next.select();
  });
})();
