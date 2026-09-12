# ourthology.com

*The arc of a life — and the family it's part of.*

ourthology.com is a personal life-archive site: a private, media-rich timeline of your
whole life, a diary alongside it, and a family tree you build by hand (no
algorithmic matching, no social graph). Every entry — photo, video, or
thought — is tagged public or private at the moment you add it. Private
entries stay sealed to you alone until you die, at which point you choose in
advance whether they're released to the family or destroyed.

## Principles

- **Manual, not algorithmic.** No auto-suggested relatives, no "people you
  may know," no scraped record matching. The family tree is built entirely
  by the user's own hand.
- **Private by default, public by choice.** Every upload asks the
  public/private question explicitly — there is no ambient sharing.
- **Built for one lifetime.** The product has to plan, from day one, for a
  user who will eventually die, and for data that outlives them.
- **Ease of use above all.** Family members across all ages and technical
  comfort levels need to be able to use this without help.

## Status

Moving from a single-user, localStorage-only prototype
(`prototypes/timeline.html`) to a real multi-user PHP + MySQL backend on
Krystal. Full design in the project doc `architecture.md`. Build phases:

1. **Schema + auth skeleton (live)** — `db/schema.sql`, signup/login/logout,
   sessions. `index.php`, `login.php`, `signup.php`, `logout.php`,
   `dashboard.php` (placeholder), `includes/` (db/auth/csrf helpers).
2. Family graph — relationships/partnerships, claim-token invites, tree UI
   rebuilt against the DB.
3. Timeline entries + media upload, visibility wired to shared family
   groups.
4. Relationship approval flow for linking to already-claimed accounts.
5. Polish — hover-to-timeline feature, one-time import of any existing
   localStorage data.

**Secrets:** DB credentials live in `<home>/ourthology-secrets/config.php` on
the server, one directory above the `ourthology.com` document root — never
in this repo. See `config.example.php` for the format; `includes/db.php`
loads it by path.

## GDPR & privacy — running notes

These are the things to design around from the start, not bolt on later.
Nothing here is implemented yet beyond what's noted.

**Addressed in the timeline prototype (client-side only, no backend yet):**
- Public/private is asked at the point of capture, not set globally — data
  minimization starts with the user deciding what leaves their private space
  at all.
- The prototype runs entirely in the browser (no network calls, no server);
  anything saved persists only via `localStorage` in that one browser.

**Open items for when a real backend exists:**
- **Lawful basis & consent** — consent needs to be captured (and re-confirmed)
  per family connection, since one user's "public" entry becomes another
  family member's personal data too. Consider a DPIA before launch —
  diary content, health/relationship disclosures, and photos of minors are
  all special-category-adjacent even when the user never labels them that way.
- **Data residency** — likely needs EU/UK hosting and storage given a
  UK-based platform and family users who may be anywhere; check standard
  contractual clauses for any non-EEA processor (image storage, backups, AI
  features if any are added later).
- **Right to erasure vs. the "record for future generations" premise** — a
  living user's right to delete conflicts directly with a relative's later
  view of a shared family tree/timeline; needs an explicit policy (e.g.
  deletion removes your private vault outright, but a public entry another
  family member has already seen/relied on may need a "tombstone" rather
  than silent disappearance).
- **The death trigger** — every competitor in this space fudges this with a
  manual "trusted contact" step; we should too, but decide deliberately:
  who can attest a death, what evidence is required, how long the account
  waits before defaulting to the user's pre-chosen release/delete instruction,
  and how that default is chosen (release vs. delete vs. "ask my executor").
- **Retention & deletion after death** — define a retention schedule for the
  private vault of a deceased user pending the death-trigger decision, and
  for backups/logs that may retain deleted content longer than the primary
  store.
- **Data portability** — users (and eventually their heirs) should be able to
  export the full timeline/diary/tree in a usable format.
- **Encryption** — private-vault content should be encrypted at rest with
  access scoped tightly enough that even ourthology.com staff can't casually browse
  it; worth deciding early since retrofitting encryption onto an existing
  private-content store is painful.
- **Minors** — family trees and shared timelines will inevitably include
  children who never consented to anything; need a policy for content
  involving minors (who can add it, who can see it, what happens when that
  child becomes an adult user themselves).
- **Processor agreements** — any third-party storage/CDN for media needs a
  proper DPA; audit this before picking infrastructure, not after.
