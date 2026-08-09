# Architectural decisions and trade-offs

Why FormForge AI is built the way it is. Each entry states the decision, the reasoning, what it costs, and what was rejected. Where a decision is enforced by a test rather than by convention, the test is named.

---

## 1. A form is a JSON schema, and one service owns it

**Decision.** The form definition lives in a single `forms.schema` JSON column, and `App\Services\SchemaService` is the only thing permitted to construct, repair, validate or persist one.

**Why.** Four very different authors produce schemas here: a person dragging fields, a language model answering a prompt, a language model reading a Word document, and a person pasting raw JSON. If each path built its own structure, "a form" would mean four subtly different things and every consumer downstream — the renderer, the validator, the exporter, the differ — would need to cope with all four. Routing everything through one service means a schema is the same object no matter who wrote it.

The alternative, a relational `fields` table, was rejected because a form is read as a whole and essentially never queried by field. Normalizing it would buy query flexibility nobody needs and cost a join-heavy read on the hottest path, plus a versioning story that requires snapshotting several tables at once instead of one column.

**Cost.** The database cannot enforce the shape of a schema; `SchemaService::validationErrors()` is the only guarantee. Field-level querying would require JSON functions or a new table.

---

## 2. ULIDs for application tables, auto-increment for users

**Decision.** Every application model uses `HasUlids`. `User` keeps Laravel's auto-incrementing id.

**Why.** A form id appears in URLs the owner shares and in payloads the client can see. A sequential integer leaks how many forms exist and invites enumeration. A ULID is unguessable in practice, and unlike a UUIDv4 it sorts by creation time, so `order by id` is chronological and index locality stays good on insert — which matters for `form_submissions`, an append-heavy table.

`User` was left alone deliberately. It is Breeze's table, referenced by the framework's own auth, password reset and session machinery, and changing its key would mean diverging from the scaffolding for no user-visible gain.

**Cost.** A mixed key strategy: `foreignId` for `user_id`, `foreignUlid` for everything else. Ids are 26 characters rather than 8 bytes.

---

## 3. Public forms are addressed by an opaque token, not a slug

**Decision.** The public URL is `/f/{public_token}`, where `public_token` is a UUID with a unique index. Slugs are unique per user and used only inside the dashboard.

**Why.** A slug is chosen by the owner and describes the form. A shared URL built from one would leak the form's purpose and, since slugs are unique per user rather than globally, would need the owner in the path too. The token reveals nothing about the owner, the form, or how many forms exist.

**Cost.** Public URLs are not human readable. Rotating a leaked token is not exposed in the UI yet.

---

## 4. An unpublished form 404s rather than 403s

**Decision.** `PublicForm` looks up `published()->where('public_token', $token)` and aborts 404 when nothing matches.

**Why.** A 403 would confirm that a token is real but the form is not currently public — an oracle that lets someone test tokens for validity. A 404 makes a real-but-unpublished token indistinguishable from a fabricated one.

**Cost.** An owner who forgot to publish sees a 404 rather than a message explaining why. Mitigated by showing the live URL and publish state in the builder toolbar and on the forms index.

---

## 5. Every save appends an immutable version

**Decision.** `SchemaService::save()` increments `forms.schema_version` and inserts a `form_versions` row with the schema, the actor and a note, inside one transaction. Version rows are never updated or deleted.

**Why.** A form definition is the thing every submission is interpreted against. If it can change with no record, a submission from last month becomes unreadable the moment a field is renamed. Snapshotting on every save makes the history complete by construction, rather than by anyone remembering to record it. The unique index on `(form_id, version)` makes a duplicate version number a database error rather than silent corruption.

Both writes share one transaction because a form whose `schema_version` says 5 with no version-5 snapshot is a worse state than a failed save.

**Cost.** A row per save, including trivial ones. A busy form accumulates history quickly, which is why the version list is paginated. There is no pruning policy yet.

---

## 6. Rollback appends; it never reopens a version

**Decision.** `FormVersions::rollback()` reads the target snapshot, validates it, and hands it to the same `SchemaService::save()` every other write uses. That appends a **new** version at `current + 1`, attributed to whoever performed the rollback, noted `Rolled back to version N`. The target row is not touched.

**Why.** The purpose of a history is that it records what actually happened. Rewinding `schema_version` or editing the old row would destroy exactly the evidence someone rolling back is most likely to need next — including the ability to undo the rollback itself. Reusing `save()` rather than writing a bespoke path also means rollback inherits the same normalization, the same validation and the same transaction as every other write, so there is no second code path to keep correct.

Verified by `tests/Feature/FormRollbackTest.php`: the target row is compared field by field and by raw encoded JSON before and after, earlier versions are compared wholesale, and a forced failure inside `save()` is asserted to leave the form and its history untouched.

**Cost.** Rolling back twice grows the history by two rather than returning to a previous state. A reviewer reading version numbers has to read the notes to see what happened.

---

## 7. The policy ability is called `rollback`, not `restore`

**Decision.** `FormPolicy::rollback(User $user, Form $form)`.

**Why.** `Form` uses `SoftDeletes`, and Laravel reserves `restore` for undeleting a trashed model. A policy method with that name would read as "may this user undelete this form" to every Laravel developer and to `Gate::authorize('restore', $form)` in any future code. The collision would be silent and confusing.

The argument order is `(User $user, Form $form)` rather than the `(Form, User)` written in the original specification, because Laravel resolves policy arguments positionally with the user first. The other order simply would not be called correctly.

---

## 8. Publish and rollback are their own abilities, not `update`

**Decision.** `FormPolicy` gains `publish` and `rollback` alongside `view`, `update` and `delete`. All five delegate to the same `owns()` check today.

**Why.** They are the same rule now but not the same *question*. `publish` is the single point at which a private form becomes world-readable; `rollback` is the only action that discards the current schema wholesale. Both are things an auditor should be able to find by name, and both are the first candidates for tightening if roles or team ownership ever arrive. Folding them into `update` would mean that future change touches call sites rather than the policy.

**Cost.** Three lines of apparent duplication in the policy.

---

## 9. The diff never normalizes, and matches on id with a key fallback

**Decision.** `SchemaService::diff(array $from, array $to)` is pure. Neither argument goes through `normalize()`, neither is mutated, and entries are matched on their `id` where present, falling back to a field's `key` or a section's `title`, and finally to position.

**Why.** The whole value of comparing against a stored snapshot is that the snapshot is what was really saved. Running it through `normalize()` first would slugify its keys and regenerate its ids, so the diff would describe a document nobody ever wrote — and would report differences that are artefacts of the repair rather than real edits.

Matching on id is what lets a rename be reported as a rename instead of a deletion plus an addition. The key fallback is what makes a legacy snapshot comparable at all, since ids were not always written. A pair matched by key cannot report a key change, which is inherent: a renamed key with no id is genuinely indistinguishable from a removal and an addition.

Comparison coerces only for the comparison itself — `null` and `""` are the same absent value, `1` and `true` the same yes — because a legacy snapshot writes those differently and reporting them as edits would bury the real changes. Option order *is* significant, because it is the order respondents see.

**Cost.** `diff()` is a pure function that duplicates none of `normalize()`'s tolerance, so it needs its own defensive handling of malformed input. Covered by twenty cases in `tests/Unit/SchemaDiffTest.php`, including malformed ids, legacy schemas, duplicate identities, and proof that neither argument is mutated.

---

## 10. The dirty-builder guard is enforced in two places, neither of them literally

**Decision.** The builder's Versions link is **disabled while `$dirty` is true**, with an explanatory tooltip. Separately, `FormVersions` captures `schema_version` into a `#[Locked]` property at mount and refuses to roll back if the stored value has moved since.

**Why.** "Reject rollback if the builder has unsaved changes" cannot be honoured literally. `dirty` is in-memory state on the `FormBuilder` component instance, and navigating to the versions page destroys that instance before rollback is ever reachable. There is no dirty flag left to check.

So the requirement is enforced where it actually protects work. Disabling the link stops you leaving the builder with unsaved changes at all, which mirrors the existing precedent where `runAi()`, `startImport()` and `acceptImport()` each refuse over unsaved changes. The mounted-version check then catches the case the first guard cannot see: another tab saving, or a queued AI or import job landing, while the versions page sits open. That is the cross-request equivalent, and unlike the literal reading it is genuinely testable.

**Cost.** A user with unsaved changes must save or reload before viewing history. The version check is optimistic, not a lock: it detects a form that moved, not two rollbacks racing in the same instant.

---

## 11. Publishing writes no version

**Decision.** `publish()` and `unpublish()` set `status` and `published_at` on the form row and nothing else.

**Why.** A version snapshot answers "what did this form say at this point". Publishing does not change what the form says, only who may read it. Recording a version would put entries in the history that contain no schema change, making the history harder to read for no gain — and would mean toggling visibility twice produces two snapshots identical to their predecessor.

Verified by `tests/Feature/FormPublishTest.php`: publishing then unpublishing leaves `schema`, `schema_version` and the version count untouched.

**Cost.** Publish and unpublish events are not currently recorded anywhere. The `activity_logs` table and the `FormPublished` enum case exist for exactly this and are not yet written to.

---

## 12. Submission search uses `LIKE` with an explicitly declared escape character

**Decision.** Search runs `search_text like ? escape '~'`, with `~`, `%` and `_` escaped in the user's term. No fulltext index was added, and no migration was written.

**Why.** `search_text` is denormalized at submit time by `SubmissionService` precisely so search does not have to reach into JSON. `LIKE` against it is correct, predictable, and cheap at this scale. A fulltext index would change matching semantics — stemming, stopwords, minimum word length — for a gain nobody can measure here, and would need a migration the specification rules out absent a real defect.

Escaping the wildcards is not optional: without it, a search for `%` matches every row and a search for `a_1` matches `ab1`. The escape character is **declared in the query** rather than assumed, and is `~` rather than a backslash, because the two engines disagree. MySQL defaults to a backslash and also treats one specially inside string literals; SQLite has no default escape character at all. Assuming either would mean the test suite, which runs on SQLite, and production, which runs on MySQL, silently behave differently on the same search.

Verified by `tests/Feature/SubmissionListTest.php`, which asserts literal matching of `%`, `_` and `\`.

**Cost.** No relevance ranking, no stemming, and a leading-wildcard `LIKE` cannot use an index. This is the decision most likely to need revisiting first, at millions of rows.

---

## 13. Submissions are labelled from their own schema version

**Decision.** Opening a submission resolves field labels from the `form_versions` snapshot matching that submission's `form_version`, falling back to the raw payload keys when no snapshot survives. The CSV export does the same, suffixing columns from removed fields with `(removed)`.

**Why.** `form_submissions.form_version` is an integer, deliberately not a foreign key, recording which schema the answers were given against. Rendering an old submission with today's labels would attach the wrong question to a real answer, which is worse than showing a raw key — someone reading a response would be confidently misled. Falling back to the key is honest about what is and is not known.

**Cost.** One extra query per opened submission. A pruned history degrades to raw keys.

---

## 14. Uploads are private, and there is no download route

**Decision.** Submission and import files are written to the private disk under `submissions/<form-id>/<submission-id>/` with generated filenames. `SubmissionService` and `ImportArchiveGuard` both **throw** if the configured disk is public or has public visibility. The submission detail panel lists filenames only.

**Why.** Uploads are the most sensitive thing this application stores — CVs, identity documents, whatever a form asks for. A publicly served disk plus a guessable path is a data breach with no attacker required. Refusing to start rather than quietly writing to a public disk turns a misconfiguration into a loud failure.

The uploader's filename never becomes a path component, because a filename is attacker-controlled and is a traversal and collision risk. It is preserved in `submission_files.original_name` for display, which is the only place it is needed.

No download route exists yet, so the panel shows names only rather than links that would 404 or, worse, a path that hints at the storage layout.

**Cost.** Retrieving an uploaded file currently means reaching into `storage/app/private` directly. A signed, authorized download route is the obvious next addition.

---

## 15. Untrusted documents are inspected before any parser touches them

**Decision.** `ImportArchiveGuard::assertSafe()` reads the ZIP central directory of an uploaded `.docx`/`.xlsx` — extracting nothing — and rejects path traversal, `vbaProject.bin` macros, a missing `[Content_Types].xml`, a missing format marker, too many entries, and an implausible declared uncompressed size. The XLSX reader then runs in data-only mode and reads a formula's cached value via `getOldCalculatedValue()`, never `getCalculatedValue()`.

**Why.** DOCX and XLSX are ZIP archives supplied by a stranger, and handing one straight to a parsing library means trusting that library against hostile input. Checking the container first is cheap and catches the whole zip-bomb and traversal class before any memory is committed. Reading the cached formula value rather than calculating it is what makes "handle formulas without executing arbitrary code" true rather than aspirational.

The row and column bounds are applied through an `IReadFilter`, during the read, rather than by trimming afterwards — trimming later would already have paid the memory cost the bound exists to avoid.

**Cost.** A legitimate macro-enabled document is rejected. A workbook whose formula results were never cached reads as blank.

---

## 16. A candidate schema is gated before it is normalized

**Decision.** `SchemaCandidateGate` runs before `SchemaService::normalize()` for every machine- or hand-authored schema: AI generation, AI editing, document import, and the raw JSON editor. Builder drag-and-drop actions do not go through it.

**Why.** Three of normalize's repairs are helpful for a person and destructive for a machine. An unknown type silently becomes a text field, so a hallucinated `type: "signature"` ships a text box nobody notices. A duplicate key silently becomes `email_2`. `Full Name` silently becomes `full_name`. For someone dragging fields, all three are the right call. For an AI edit, each one silently corrupts an intent, and the second and third orphan every answer already submitted against the old key.

AI **edits** additionally run `keyPreservationErrors()` against the stored schema, both before normalization and again against normalize's output, so a model cannot rename a key by any route. Hand-authored JSON is allowed to rename keys — the author is a person who can see the consequences — which is why the stored schema is not passed in that case. Imports are not key-checked either, because an import replaces a form rather than editing one.

**Cost.** Two validation layers with overlapping concerns. The gate must be kept in step with `FieldType`; it reads the enum rather than duplicating it, which is what stops that drift.

---

## 17. AI work is queued, capped, and retried only by the internal repair loop

**Decision.** Generation, editing and import inference all run in queued jobs. Each job serializes only a row id. `$tries = 1` on both, with a timeout computed from the provider timeout times the repair budget. The repair loop lives inside the job, bounded by `FORMFORGE_AI_MAX_REPAIR_ATTEMPTS` (3, meaning at most 4 provider calls).

**Why.** A provider call takes seconds and can hang; holding a web request open for it is the wrong shape. Serializing an id rather than a model means the job always re-reads current state, which matters when the job may start minutes after it was dispatched.

`$tries = 1` is the important one. The repair loop already retries intelligently, feeding the specific validation errors back to the model. A queue-level retry on top would restart the whole conversation from scratch and pay the provider all over again for it, with no new information. Every failure mode here is either permanent or already handled internally.

Both jobs implement `failed()` defensively: they re-find the row, return early if it is already terminal, and only then record a generic failure — so a late `failed()` callback cannot overwrite a run that actually succeeded.

**Cost.** A genuinely transient network blip fails the request rather than retrying, and the user must click again. That is a deliberate trade: a visible, cheap failure over an invisible, billable one.

---

## 18. Provider errors are mapped to fixed sentences

**Decision.** `AiService` maps HTTP status to one of four fixed messages and never surfaces the provider's response body. Jobs wrap anything that is not a `RuntimeException` in a generic sentence. The same pattern applies to parser failures, rollback failures and submission storage failures: log the detail, show the user a fixed string.

**Why.** A provider error body can echo the request back, which for an edit is the user's own schema and for an import is document content. Error text also names internal paths, XML offsets and library versions. None of that helps the person reading it and all of it helps someone probing the system. The real exception goes to the log, where it is useful.

**Cost.** Support requires reading logs rather than screenshots.

---

## 19. IPs are hashed, never stored

**Decision.** `SubmissionService` stores an HMAC-SHA256 of the submitter's IP keyed on `config('app.key')`. The public rate limiter keys on a SHA-256 of the token and address.

**Why.** An IP address is personal data, and the operational needs — spotting abuse, rate limiting — only require telling two submitters apart, not knowing who they are. Keying the HMAC on the app key means the hashes are not reversible with a rainbow table of the IPv4 space, which a bare SHA-256 would be.

**Cost.** No geolocation, and rotating `APP_KEY` makes historical hashes incomparable with new ones.

---

## 20. Public rate limiting keeps the visitor on the form

**Decision.** `PublicForm::withinRateLimit()` checks the limiter itself and sets an error message rather than throwing. The visitor stays on the page with their answers intact and is told when to retry. Setting the limit to `0` disables it.

**Why.** The `throttle` middleware returns a 429 error page, which for someone halfway through a long form means losing everything they typed. The abuse being prevented is scripted, and a script does not care how the refusal is delivered; a real person very much does. Every attempt counts toward the limit, successful or not, so failed validation cannot be used to probe for free.

**Cost.** Rate limiting is implemented in the component rather than in middleware, so it must be preserved if the submission path is ever restructured. Covered by `tests/Feature/PublicFormSubmissionTest.php`.

---

## 21. CSV export is streamed, chunked, and defends against formula injection

**Decision.** `streamDownload` writing to `php://output`, rows read with `lazyById`, a UTF-8 BOM at the front, and a leading apostrophe on any cell starting with `=`, `+`, `-`, `@`, tab or carriage return.

**Why.** Loading every submission into memory to build a string would put a hard ceiling on export size. `lazyById` is used rather than `chunk` because offset paging drifts when rows are inserted mid-export.

The BOM is there because Excel assumes the system codepage without it and mangles every accented character. The formula guard is there because a CSV is usually opened in a spreadsheet, and `=HYPERLINK(...)` typed into a form field becomes executable content the moment it is. Escaping on write is the only reliable point of control.

Export is synchronous. It is streamed and chunked, so it stays safe for large volumes, and queuing it would require somewhere to park the artefact and a way to notify the user — real work for no benefit at this scale. `formforge.export.sync_max_rows` and `formforge.queue.export` are reserved for that future and are currently unused.

---

## 22. Reads are provably reads

**Decision.** The version history and submission list tests install a `DB::listen` hook and assert that **zero** insert, update or delete statements are issued while browsing, viewing, comparing, searching and filtering.

**Why.** "This page is read-only" is easy to say and easy to break: a `touch()` in an accessor, an eager-loaded relation with a side effect, a cache warm that writes. Asserting on the actual statements issued turns the claim into something the suite enforces rather than something a reviewer has to take on trust.

---

## 23. The seeder produces a demonstrable application, not just a row

**Decision.** `DatabaseSeeder` creates a verified demo user and a **published** form with fifteen fields across two sections, its version-1 snapshot, and six submissions written through the real `SubmissionService`.

**Why.** The previous seed produced an empty draft form, which meant the public URL, the submission list, search, the status filter and CSV export could not be exercised without manual setup first. Anyone evaluating the project would have had to build a form before seeing any of it work.

Submissions go through `SubmissionService` rather than being inserted directly so that `search_text` is generated by the same code the application uses — a hand-written approximation would let the seed drift from real behaviour and make search look like it works when it does not. The schema is built through `SchemaService::normalize()` for the same reason.

**Cost.** The existing seeder assertion that the demo form has no sections had to be updated, since the whole point was to give it some. `tests/Feature/DatabaseSchemaTest.php` now asserts the richer shape.

---

## 24. The database dump is a convenience; migrations are the source of truth

**Decision.** `database/dumps/schema.sql` is committed, schema only, produced with:

```bash
mysqldump --no-data --skip-comments --result-file=database/dumps/schema.sql -u root formforge_ai
```

**Why.** `--no-data` is what makes it schema only, so no submission, no user and no seeded row is in the file. `--skip-comments` drops mysqldump's header, which otherwise records the host and server version. The dump contains no credentials — the only matches for "password" in it are the `password_reset_tokens` table and the `users.password` column.

`--result-file` matters more than it looks. Shell redirection under Windows PowerShell writes UTF-16 with a BOM, producing a `.sql` file most tools reject; letting mysqldump write the bytes itself produces clean UTF-8 on every platform.

The dump exists so a reviewer can read the schema without running anything. It is **not** the way to build a database — migrations are, and they are the only definition kept in step with the code.

**Cost.** A committed artefact that can go stale. Regenerate it after any migration.

---

## 25. The sample generator is committed, but is not application code

**Decision.** `samples/imports/generate-samples.php` produces the two sample documents using the already-installed PhpWord and PhpSpreadsheet. It sits outside `app/`, is not autoloaded, and is not reachable by the web process.

**Why.** Committing two binaries with no explanation asks a reviewer to trust them. Committing the script that made them makes their content auditable and regenerable. Keeping it out of the autoloaded tree means a file that takes a path and writes to disk can never be reached from a request.

**Cost.** The binaries and the script can drift apart if someone edits a sample by hand. Re-running the script is the only supported way to change them.

---

## 26. What was deliberately not built

Recorded so the gaps read as decisions rather than oversights.

- **Conditional logic is stored, versioned and diffed, but not enforced.** Wiring it into `ValidationService` means deciding what happens to a hidden field's `required` rule and to answers already given before a condition hid the field. That is a design question, not a coding one, and half-implementing it would be worse than leaving the data model ready.
- **No activity log is written.** The table, model and enum exist and are indexed for it. Writing entries without a UI to read them, or a retention policy, would just accumulate rows.
- **No schema cache.** `config('formforge.cache.*')` is defined and unused. The public renderer reads the schema once per request, which is one indexed primary-key lookup; adding a cache would introduce an invalidation path — on save, on rollback, on publish — for no measured gain.
- **No `ValidFormSchema` usage.** The rule exists and is tested, but every current entry point validates through `SchemaService` directly. It is kept for form-request validation if an HTTP API is ever added.
- **No new migrations in this phase.** The `(form_id, created_at)` and `(form_id, status)` indexes the submission list needs were already in place, and its migration comment already reads "Paginated submission list".
- **Pre-existing code style failures were left alone.** `vendor/bin/pint --test` fails on seven files untouched by this work. Fixing them would mix unrelated churn into the diff; they are reported separately instead.
