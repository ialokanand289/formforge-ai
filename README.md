# FormForge AI

An AI-powered dynamic form builder built with Laravel 11, Livewire 3, MySQL and OpenAI. Build a form by hand, describe it in a sentence and let a model draft it, or upload the Word or Excel document you already have and turn that into a form. Every form is a JSON schema, every save is a versioned snapshot, and every version can be compared and rolled back to.

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Setup](#setup)
- [Demo credentials](#demo-credentials)
- [Running it](#running-it)
- [Tests, linting and build](#tests-linting-and-build)
- [Architecture](#architecture)
- [Feature guide](#feature-guide)
- [Sample import files](#sample-import-files)
- [Database dump](#database-dump)
- [Configuration reference](#configuration-reference)
- [Known limitations](#known-limitations)

---

## What it does

| Capability | Where it lives |
|---|---|
| Drag-and-drop form builder with a live JSON editor | `App\Livewire\Forms\FormBuilder` |
| Generate a form from a prompt, or edit an existing one with an instruction | `App\Jobs\ProcessAiGenerationJob`, `App\Services\AiService` |
| Import a form from a `.docx` or `.xlsx` document | `App\Jobs\ProcessImportJob`, `App\Services\DocxImportParser`, `App\Services\XlsxImportParser` |
| Publish a form to a public, anonymous URL | `FormBuilder::publish()`, `App\Livewire\Forms\PublicForm` |
| Collect, browse, search and filter submissions | `App\Livewire\Forms\FormSubmissions` |
| Stream submissions out as CSV | `App\Http\Controllers\SubmissionExportController` |
| Version history, structural compare and safe rollback | `App\Livewire\Forms\FormVersions`, `SchemaService::diff()` |

---

## Requirements

- PHP 8.2 or newer, with the `zip`, `gd`, `mbstring` and `pdo_mysql` extensions
- Composer 2
- MySQL 8 (or MariaDB 10.6+). XAMPP defaults work as-is.
- Node.js 18 or newer, with npm
- An OpenAI API key, only if you want the AI and import features. Everything else runs without one.

---

## Setup

```bash
git clone <repository-url> formforge-ai
cd formforge-ai

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Create the database and point `.env` at it:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=formforge_ai
DB_USERNAME=root
DB_PASSWORD=
```

```bash
mysql -u root -e "CREATE DATABASE formforge_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate:fresh --seed
npm run build
```

To enable AI generation and document import, add your key:

```dotenv
OPENAI_API_KEY=sk-...
```

Without a key the AI and Import buttons refuse up front with a clear message rather than failing mid-request. No other feature is affected.

### A note on file storage

Submission uploads and import documents are written to the **private** disk, `storage/app/private`. Do not run `php artisan storage:link` for them and do not set `FORMFORGE_UPLOAD_DISK=public`. `SubmissionService` and `ImportArchiveGuard` both refuse to write to a disk whose visibility is public, so a misconfiguration fails loudly instead of quietly publishing people's uploads.

---

## Demo credentials

`php artisan migrate:fresh --seed` creates one verified account:

| Email | Password |
|---|---|
| `demo@formforge.test` | `password` |

It owns a **published** demo form with fifteen fields across two sections, its version-1 snapshot, and six submissions. That is enough to exercise the public URL, the submission list, search, the status filter, CSV export, version history, compare and rollback without touching the builder first.

A hosted demo URL is not provided with this repository. Run it locally with the steps above.

---

## Running it

You need three processes for the full feature set:

```bash
php artisan serve      # http://127.0.0.1:8000
npm run dev            # asset dev server, or use `npm run build` once
php artisan queue:work # required for AI generation and document import
```

The queue worker is not optional for AI or import: both dispatch to the queue so a slow provider call never holds a web request open. With `QUEUE_CONNECTION=sync` they run inline instead, which is convenient for a quick look but blocks the browser for the length of the provider call.

An hourly scheduled command, `formforge:prune-import-files`, fails imports that were abandoned mid-flight and deletes files left behind. In production, run the scheduler:

```bash
php artisan schedule:work
```

---

## Tests, linting and build

```bash
php artisan test              # full suite, SQLite in memory
php artisan test --filter=Rollback
vendor/bin/pint --test        # code style check
vendor/bin/pint               # code style fix
npm run build                 # production assets
```

Tests run against an in-memory SQLite database with the queue set to `sync`, the cache and session set to `array`, and `OPENAI_API_KEY` blanked, so no test can reach a real provider or touch your development database.

---

## Architecture

A form is a **JSON schema** stored on the `forms.schema` column. Nothing outside `SchemaService` is allowed to build, repair or persist one, which is what keeps a schema written by a human, by a model, and by a document importer identical in shape.

```
                      ┌──────────────────────┐
   prompt ───────────►│                      │
   .docx / .xlsx ────►│  SchemaCandidateGate │──► rejected, with reasons
   raw JSON editor ──►│                      │
   builder actions ──►└──────────┬───────────┘
                                 │ accepted
                                 ▼
                      ┌──────────────────────┐
                      │    SchemaService     │  normalize → validate → save
                      └──────────┬───────────┘
                                 │ one transaction
                    ┌────────────┴────────────┐
                    ▼                         ▼
              forms.schema            form_versions (append only)
              schema_version += 1     immutable snapshot
```

### The services

**`SchemaService`** is the single owner of schema construction, repair, validation, versioned persistence and structural diffing. `normalize()` is a repair pass: it slugifies keys, makes duplicates unique with a `_2` suffix, falls back to a text field for an unknown type, and regenerates ids that are not ULIDs. `save()` is the only path to the database, and it runs the form update and the version snapshot in one transaction so the two can never disagree. `diff()` compares two schemas without touching either.

**`SchemaCandidateGate`** runs *before* `normalize()` and rejects a candidate schema outright rather than letting it be silently repaired. This matters because three of normalize's repairs are destructive when the author was a machine: an unknown type quietly becomes a text field, a duplicate key quietly becomes `email_2`, and `Full Name` quietly becomes `full_name`. For a human dragging fields around that is helpful. For an AI edit it would mean a model hallucinating a `type: "signature"` field silently ships a text box, and a renamed key silently orphans every historical answer. The gate is shared by AI generation, AI editing, document import and the raw JSON editor.

**`ValidationService`** turns a schema into Laravel validation rules, messages and attribute names for the public renderer. It is generated from the schema on every request rather than stored, so a rule can never drift from the field it guards.

**`SubmissionService`** owns everything that happens after a public submission validates: projecting the payload against the schema, writing uploads to the private disk, hashing the submitter's IP with HMAC-SHA256 keyed on the app key, and building the `search_text` column the owner's submission search reads. Files are written before the transaction opens and deleted by hand if it rolls back, because a filesystem cannot participate in a database transaction.

**`SubmissionExportService`** streams the CSV. Columns are the union of the current schema, the historical snapshots the submissions actually reference, and any orphan payload keys, so an answer to a field that was later deleted still has a column.

**`ImportArchiveGuard`** inspects an uploaded `.docx` or `.xlsx` before PhpWord or PhpSpreadsheet is handed the path. Nothing is extracted; only the ZIP central directory is read. It rejects unreadable archives, path traversal entries, `vbaProject.bin` macros, a missing `[Content_Types].xml`, a missing format marker, too many entries, and an implausible declared uncompressed size.

**`AiService`** is transport only. It builds the system contract from the `FieldType` enum so the prompt can never drift from the code, posts to the chat-completions endpoint in JSON mode, and maps provider failures onto fixed user-facing sentences. It makes no accept-or-reject judgement; that belongs to the gate.

### Queued jobs

Both jobs serialize only a row id, never a model, so state is always re-read from the database rather than trusted from the payload.

**`ProcessAiGenerationJob`** turns one `ai_generation_logs` row into a saved schema or a recorded failure. `$tries = 1`, because the repair loop is internal and a queue retry would pay the provider all over again. Its timeout is computed from the provider timeout multiplied by the repair budget. It re-checks that the form still belongs to the requesting user before it writes, which is defence in depth outside the request lifecycle. `failed()` marks the log failed with a generic message unless it already reached a terminal state.

**`ProcessImportJob`** turns one uploaded document into a schema *preview*. It never persists a schema. It stops at `preview` with the candidate parked on the row, and the owner commits it explicitly from the builder. The source file is deleted as soon as the preview is written, and on every failure path.

---

## Feature guide

### The builder

`/dashboard/forms/{form}/builder`. The working schema lives in a `#[Locked]` property and every mutation goes through a `commit()` that normalizes and validates before assigning, so a rejected change leaves the builder exactly as it was rather than half-applied. A **raw JSON editor** lets you paste a schema directly; it runs through the gate before normalize and reports any repairs afterwards.

The toolbar carries Save, Publish/Unpublish, AI, Import, and links to Versions and Submissions. **The Versions link is disabled while you have unsaved changes**, with an explanatory tooltip, because navigating away destroys the in-memory schema and would silently discard your work.

### AI generation and editing

Describe the form you want and a queued job drafts it. Or select an existing form and give an instruction like "make the phone number optional and add a section for dietary requirements".

Editing is deliberately stricter than generation. The prompt forbids renaming or retyping an existing field, and `SchemaCandidateGate::keyPreservationErrors()` enforces that in code both before and after normalization — a model cannot talk its way past it. A renamed key would orphan every answer already submitted against it.

When the model returns something that will not validate, the job feeds the specific errors back and asks for a repair, up to `FORMFORGE_AI_MAX_REPAIR_ATTEMPTS` times (default 3, meaning at most 4 provider calls). If the budget runs out the form is left untouched and the failure is recorded on the log row.

### DOCX and XLSX import

Upload a Word or Excel document and the same schema pipeline builds a form from it.

1. The file is stored on the private disk under `imports/<form-id>/` with a generated name; the uploader's filename never becomes a path component.
2. `ImportArchiveGuard` inspects the ZIP container, at rest, before any parser touches it.
3. `DocxImportParser` or `XlsxImportParser` reduces the document to a bounded text payload. The XLSX reader runs in data-only mode and reads a formula's *cached* value, never evaluating it.
4. A queued job asks the model to infer a schema from that payload, with the same repair loop.
5. You get a **preview** listing every field it found plus warnings, and nothing is saved until you accept it.

### Publishing and the public form

Publishing sets `status` and `published_at` on the form row and nothing else. The schema is not touched and no version is created, because publishing is a decision about who can see the form rather than about what it says.

A published form is reachable at `/f/{public_token}` by anyone, with no account. The URL uses the UUID `public_token` rather than the slug, so it reveals nothing about the owner or how many forms they have. An unpublished, archived, or unknown token returns **404 rather than 403**, so a real token cannot be distinguished from a fabricated one.

Public submissions are rate limited per token and per IP, at `FORMFORGE_PUBLIC_RATE_LIMIT` per minute (default 10). The key is a SHA-256 hash of the token and the address, so no raw IP reaches the cache. Exceeding the limit keeps the visitor on the form with their answers intact and a message telling them when to retry, rather than throwing a 429 at them.

### Submissions

`/dashboard/forms/{form}/submissions`. Read-only, owner-only, fifteen per page, newest first.

Search runs against the `search_text` column with `LIKE`. The `%`, `_` and the escape character itself are escaped in your term and the escape character is declared explicitly in the query, so searching for `50%` finds the row containing a percent sign instead of matching everything. Filtering is by `SubmissionStatus`.

Opening a submission shows the full payload with **labels resolved from the schema version that submission was filed against**, not the current one, so an answer written before a field was renamed still reads the way its author saw it. If no snapshot survives for that version, the raw payload keys are shown instead. Attached files are listed by filename only — there is no download route, and no storage path reaches the page.

### CSV export

A plain link, streamed with `streamDownload` and read in chunks with `lazyById`, so a large export never loads into memory. The file opens with a UTF-8 BOM so Excel reads accents correctly, and any cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return is prefixed with an apostrophe so a spreadsheet cannot be tricked into treating a submitted answer as a formula.

### Version history, compare and rollback

`/dashboard/forms/{form}/versions`. Every save writes an immutable `form_versions` row, so the history is complete by construction rather than by anyone remembering to record it.

- **View** renders one stored snapshot exactly as it was written. It is built defensively — a missing key reads as blank, a malformed entry is skipped — and it never calls `normalize()`, because repairing a snapshot for display would show you a form nobody ever saved.
- **Compare** runs `SchemaService::diff(historical, current)` server-side and reports added, removed, changed and unchanged sections and fields, with the old and new value for each changed property. Viewing and comparing issue zero writes, which is asserted in the test suite with a `DB::listen` hook.
- **Rollback** does not reopen the old version. It reads the snapshot, validates it, and hands it to the same `SchemaService::save()` every other write uses, which appends a **new** version at `current + 1` attributed to you with the note `Rolled back to version N`. The row you rolled back to is left byte for byte identical.

Rollback is refused, safely and with nothing written, if the form's `schema_version` has moved since you opened the page. Another tab, or a queued AI job landing, would otherwise be silently discarded.

---

## Sample import files

`samples/imports/` holds two documents you can upload straight into the importer, plus the script that produced them.

`generate-samples.php` is a development utility. It lives outside `app/`, is not autoloaded, and is never reachable by the web process. It exists so a reviewer can see how the two binaries were made instead of having to trust them:

```bash
php samples/imports/generate-samples.php
```

Neither parser expects a magic format — the AI inference step reads the extracted text — but these are the layouts it reads most reliably.

### `sample-form.docx` — a questionnaire

Written the way a person writes a paper form, and exercising the four structures the DOCX path has to handle:

| Structure | In the document | Expected interpretation |
|---|---|---|
| Heading 1 | *Volunteer Registration Form* | The form title |
| Heading 2 | *Your details*, *Your availability*, *Anything else* | Section boundaries |
| Paragraph question | *Full name (required)* | A `text` field, marked required |
| Paragraph question | *Email address (required)* | An `email` field, marked required |
| Paragraph question | *Which date would you prefer…* | A `date` field |
| Prompt plus list | *Preferred shift (choose one):* followed by three list items | A `radio` field with those three options |
| Prompt plus list | *…tick all that apply* followed by four list items | A `checkbox` field with those four options |
| Paragraph question | *…(long answer)* | A `textarea` field |

The parenthetical hints — `(required)`, `(choose one)`, `(tick all that apply)`, `(long answer)` — are the signals the model reads for requiredness and field type. Headings are detected by their Word paragraph style, not by font size.

### `sample-form.xlsx` — a specification

One sheet named `Fields`, one row per question, with a documented header row:

| Column | Meaning |
|---|---|
| `Question` | The field label |
| `Type` | One of the `FieldType` values: `text`, `textarea`, `number`, `email`, `phone`, `date`, `dropdown`, `radio`, `checkbox`, `file`, `heading`, `rating` |
| `Required` | `required` or `optional` |
| `Options` | Choices for `dropdown`, `radio` and `checkbox`, **pipe separated** so a comma inside a label does not split it |
| `Help Text` | Guidance shown under the field |
| `Placeholder` | Ghost text inside the input |

For example:

| Question | Type | Required | Options | Help Text | Placeholder |
|---|---|---|---|---|---|
| Full Name | text | required | | As it appears on your ID | Ada Lovelace |
| Department | dropdown | required | Engineering\|Design\|Customer Support | Where you will be working | |
| Equipment Needed | checkbox | optional | Laptop\|Monitor\|Headset\|Docking station | Tick everything you need | |

The parser treats the first row carrying at least two populated cells as the header, so a title row above the table does not throw the columns off.

---

## Database dump

A schema-only dump, with no rows and no credentials, is committed at `database/dumps/schema.sql`. Regenerate it with:

```bash
mysqldump --no-data --skip-comments --result-file=database/dumps/schema.sql -u root formforge_ai
```

On Windows, invoke the binary by its full path, for example `C:\xampp\mysql\bin\mysqldump.exe`.

`--no-data` is what makes it schema only. `--result-file` is not cosmetic: shell redirection under Windows PowerShell writes UTF-16, which produces a `.sql` file most tools will not read. Use `--result-file` and mysqldump writes the bytes itself.

The dump is a convenience for inspecting the schema. **The migrations are the source of truth** — build a real database with `php artisan migrate`.

---

## Configuration reference

Everything lives in `config/formforge.php`. Field types are deliberately *not* duplicated there; `App\Enums\FieldType` is the single source of truth.

| Variable | Default | What it controls |
|---|---|---|
| `FORMFORGE_UPLOAD_DISK` | `local` | Disk for submission and import files. Must not be public. |
| `FORMFORGE_MAX_FILE_SIZE_KB` | `5120` | Largest submission upload |
| `OPENAI_API_KEY` | *(none)* | Enables AI and import. Absent means both refuse cleanly. |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | Provider endpoint |
| `FORMFORGE_AI_MODEL` | `gpt-4o-mini` | Model name |
| `FORMFORGE_AI_MAX_REPAIR_ATTEMPTS` | `3` | Repair calls *after* the first, so 3 means at most 4 calls |
| `FORMFORGE_AI_TIMEOUT` | `60` | Seconds per provider call |
| `FORMFORGE_IMPORT_MAX_FILE_SIZE_KB` | `10240` | Largest import document |
| `FORMFORGE_IMPORT_MAX_ARCHIVE_ENTRIES` | `512` | ZIP entry ceiling in the archive guard |
| `FORMFORGE_IMPORT_MAX_UNCOMPRESSED_BYTES` | `67108864` | Declared-size ceiling, against zip bombs |
| `FORMFORGE_IMPORT_STALE_AFTER_MINUTES` | `60` | When the pruner reclaims an abandoned import |
| `FORMFORGE_PUBLIC_RATE_LIMIT` | `10` | Public submissions per minute, per token and IP. `0` disables. |
| `FORMFORGE_EXPORT_CSV_CHUNK` | `200` | Rows per chunk while streaming a CSV |
| `FORMFORGE_QUEUE_AI` / `_IMPORT` | `default` | Queue names for the two jobs |

---

## Known limitations

Stated plainly rather than left for you to discover:

- **Conditional logic is stored but not enforced.** A field's `conditions` are persisted, diffed and versioned, but `ValidationService` treats every field as visible. Nothing conditionally shows or hides at fill time yet.
- **No download route for submitted files.** Uploads are stored privately and listed by filename. Retrieving one means reaching into `storage/app/private` directly.
- **No activity log is written.** The `activity_logs` table, its model and its enum exist and are ready, but nothing records to them yet.
- **No schema cache.** `config('formforge.cache.*')` is defined but unused; the public renderer reads the schema from the database once per request.
- **CSV export is synchronous.** It is streamed and chunked, so it is safe for large exports, but it is not queued.
- **Submission search uses `LIKE`, not a fulltext index.** Correct and predictable at this scale; it would need revisiting at millions of rows.
- **Rollback protects against a stale page, not against a truly concurrent write.** The guard compares `schema_version` before saving. Two rollbacks landing in the same instant are still ordered by the transaction rather than by an explicit lock.

---

## Licence

MIT.
