# PHINMA COC Multi-Event Tabulation System

Scoring and tabulation for PHINMA Cagayan de Oro College events. One **event** holds many
**activities** (pageant, dance, quiz bee…). Each activity has its own criteria, contestants,
judge panel and live rankings, and placements roll up into **overall team standings**.

Built with vanilla HTML / CSS / JavaScript (front end) and object-oriented PHP 8.1+ with MySQL (API).

**Look:** the "Scoreboard" theme in PHINMA COC colours: deep green leads everywhere (buttons, tabs, table headers, badges, header lines), the seal's gold marks honours only (trophies, 1st/2nd/3rd), with black lines, warm off-white paper and maroon for warnings and match-ups. Solid colours only.
- It loads no web fonts and no icon libraries, so it works offline on the campus network. Headings and numbers use Bahnschrift (built into Windows), or a condensed system font on other devices. Body text uses the device's own font.
- All icons are inline SVGs.

**Sidebar & borders:** white sidebar with black borders; cards, tables, tabs, fields and buttons use black borders too (the bracket keeps its own style). Menu clicks redraw the sidebar and top bar instantly, and a spinner only appears when a page takes a moment to load.

## Live updates

Every page updates by itself, with no refresh needed: the dashboard, event pages (activities, teams, access codes, standings, logs), activity pages (contestants, judges, results, bracket), staff accounts, activity logs, and the judge screens. Each page checks the server every few seconds (`sync.version`) and reloads its data only when something changed. It waits while someone is typing, has a dialog open or is arranging the bracket, and a full-screen bracket updates in place.

## Roles

| Role | Signs in with | Can do |
|---|---|---|
| Administrator | username + password | Everything, including staff accounts and every event |
| Program Head | username + password | Create and run their own events: activities, criteria, teams, access codes, results. Cannot see activity logs (recent activity) |
| Facilitator | **access code** (no account) | Their event only: contestants, open/close scoring, judge progress, unlock submissions, live results |
| Judge | **access code** (no account) | Score the activities assigned to them and **submit each contestant** (Submit on every contestant; after the last one the whole sheet counts as submitted) |

## Setup

1. Copy `.env.example` to `.env` and set the database, file server and OCR values.
2. Serve the `Scoring` folder with Apache + PHP 8.1+ (extensions: pdo_mysql, zip, dom, mbstring, fileinfo, curl; `ftp` only for the FTP driver).
3. Open `http://your-server/Scoring/install/setup.php`. It creates the database and tables, then asks you to create the first administrator.
4. Sign in at `http://your-server/Scoring/` (`index.html`).

## Criteria scanning

Upload the criteria sheet on an activity's **Criteria** tab:

- **Photo (JPG/PNG/WEBP…)** is read with Tesseract OCR (`TESSERACT_PATH`), with OCR.space as the fallback (`OCR_SPACE_API_KEY`).
- **PDF** is read from its text layer in pure PHP; scanned PDFs go to OCR.space.
- **Word (.docx)**: paragraphs and tables are read in pure PHP.

The detected criteria (name, description, points) appear in an editable review table. Fix any
OCR mistakes, then save. The original file stays on the file server and judges can open it.

Install Tesseract on Windows with `winget install tesseract-ocr.tesseract`.

## Activity formats

Every activity chooses **how it is decided**:

| Format | How results are entered | Visualization |
|---|---|---|
| **Score-based** | Judges score each contestant against the criteria (access codes) | Podium, average-score bars, full tabulation table |
| **Bracket** | Facilitators or staff record match scores. Teams play in order (1 vs 2, 3 vs 4, 5 vs 6…) by contestant number or a random draw. **Arrange teams** lets you drag a team onto another (or tap one, then the other) to swap them before any result. With an uneven number, a team only gets a bye where a round has nobody to play; a bye moves the team on but is never shown as a win. Record a result with a score, or tap **“… wins”** for a quick result without a score (W/L marks show on the bracket). Winners advance, and there is an optional 3rd-place match | Classic bracket: a box for each team, the two boxes of a match joined by a line (with the VS where they meet) running on to the winner box in the next round with a champion card, podium and placements. **Full screen** shows just the bracket (Esc or Exit full screen to leave). A team with a bye is shown alone, marked "Bye · advances". **Pictures** uploads each team logo (or an entry picture for this activity only) straight from the bracket |
| **Round robin** | Everyone plays everyone; record each game | Standings (P / W / D / L / +− / Pts) with a points chart, fixtures by round |
| **Ranking** | One result per contestant (points, time or placement), with higher or lower set as better | Podium and leaderboard bars |

Ties share a rank. Every format feeds its placements into the **overall standings** (team points bar chart and table). Bracket placements: champion 1, runner-up 2, 3rd-place match 3 and 4 (or both semifinal losers share 3), quarterfinal losers 5.
Activity status reads **Not started / In progress / Final** for bracket, round robin and ranking activities. A final activity is locked.

## Setting up an event

1. **New event**: choose the **Event type**:
   - **One competition**: the event itself is the contest, for example a pageant or a battle of the bands. The system creates its single competition automatically, with the same title, venue and schedule. After saving, you go straight to its criteria, contestants, judges or bracket. Nature of Activity and an optional criteria sheet are entered in the event form. The competition cannot be deleted by itself, and no other activities can be added unless you switch the event to Multiple activities.
   - **Multiple activities**: an event such as Foundation Week or Intramurals, with several activities.

   Then enter the event title, venue, starting and ending date/time, Project Head, status, and **how the activities are decided** (the main format: score-based, bracket, round robin or ranking). The main format is preselected for each new activity, and each activity can still use a different one. The event status (Draft, Upcoming, Ongoing, Completed, Cancelled) can be changed at any time from the event page.
2. Right after the event is created, the **Add activity** form opens. For each activity, enter its title and **Nature of Activity**, and choose how it is decided (score-based, bracket, round robin or ranking). For score-based activities you can attach the **criteria sheet** (photo, PDF or Word) in the same form. It is scanned when you save, and the activity page then opens with the detected criteria ready to review and save.
3. **Overview** shows an event setup checklist: activities → teams → criteria / brackets → access codes.
4. **Give access codes → Generate several** creates "Judge 1…N" (or facilitators) at once, assigned to chosen activities, ready to print as slips.

Overall team points use a fixed scale: 1st = 10, 2nd = 7, 3rd = 5, and 2 for every other placing.

- Standings update automatically as soon as an activity has winners. Tick "Only count closed (final) activities" to count finished ones only.
- A contestant counts for a team when it is linked to that team, or when its name is the team's name ("cite" counts for CITE). Entries named after a team are linked automatically when saved.
- Ranked entries that belong to no team are listed in a warning above the standings.
- Rankings use **gold, silver and bronze** for 1st, 2nd and 3rd on podiums, rank badges and table rows.

## Team colours and pictures

- **Teams**: choose a colour (Deep Green, Dark Green, Forest, Dark Blue, Navy, Royal Blue, Maroon, Crimson, Purple, Gold, Black, Slate, or any custom colour) and upload a logo. Only admins and program heads can do this.
- **Contestants**: can have their own colour and picture, which facilitators may also set. A contestant without one uses its team's colour and logo, so "Add all teams" entries are styled automatically.
- Pictures (JPG, PNG or WEBP, up to 5 MB) are resized in the browser and stored on the file server under `pictures/event-<id>/`.
  - They are served only to people signed in to that event.
  - They are deleted when replaced, removed, or when the contestant, team, activity or event is deleted.
- The colour stripe and picture appear in bracket slots, match results, round robin fixtures and standings, ranking leaderboards, podiums, overall standings and the judge score sheet.

## Scanning an event document

Use **Scan document** on the dashboard to create a new event, or on a multi-activity event page to add to that event.

1. **Upload** the event memo, program or proposal: a photo, PDF, Word (.docx) or text file.
2. **Scan.** The file goes to the file server and is read (PDF text, Word paragraphs and tables, or OCR for photos). A scanning animation shows the percentage and each step.
3. **Confirm, step by step.** After scanning, the review opens as a full-screen page (not a small window) so there is room to work. On wide screens each activity shows its details on the left, and its criteria, judges and facilitators on the right. Closing the page asks first, because unsaved changes would be lost. The confirmation is a wizard with a booking-style step bar: circles joined by a line that turns green as you go. Numbering starts at the first activity ("Activity 2 of 15"); Event details comes before it and Review & create after it. Finished activities show a check. Use **Next** and **Back**, or tap a circle to jump. Nothing typed is lost when you move between steps.
   - **Step 1, Event details:** title, venue, starting and ending date/time, and the **Project Head**. A name found in the document is matched to a staff account. The step also lists every activity found.
   - **One step per activity:** the activity can be **Included** or skipped, and you can edit its format, nature, category, type, who can join, segments, scoring, rules and criteria (with the running total). Each activity also has **Add judge** and **Add facilitator**. People named on other activities appear as one-tap "Also add" chips. A circle with an orange dot is a score-based activity that still has no criteria.
   - **Last step, Review & create:** a table of all activities (format, criteria, judges, facilitators, with **Edit** links), warnings for missing criteria or judges, the list of people who get access codes, and the **Create event** button (**Confirm** when adding to an existing event).

   What is detected:
   - **Activities:** each with a format (bracket, round robin, ranking or score-based) and a Nature of Activity. Opening programs, registration, breaks and awarding are left out.
   - **Category** of each activity: from headings such as "II. PAGEANTRY" or "ESPORTS", or from "Category:" lines.
   - **Segments** ("Segments: Casual Wear, Formal Wear, Q&A") and **scoring** ("Format: Single elimination, best of 3"). Scoring also sets the format.
   - **Criteria**, read from any common layout:
     - one-line lists: `Criteria: Craftsmanship & Accuracy (40%), Character Embodiment (30%) …`
     - one criterion per line, with dotted leaders or points
     - Word or OCR tables (`Criteria | Percentage`)
     - names and percentages in separate columns
     - weighted segments (used as the criteria when no separate criteria are given)
   - **Judges and facilitators** ("Judges:", "Board of Judges", "Facilitators:", "Marshals:", "Referees:"), for the whole event or per activity. When the document names none, add them on each activity's step.
   - An activity already in the event is never added twice. When the document has its criteria or rules it stays **Included** and they are filled in; otherwise it is skipped.
4. After **Create event** and the final **"Yes, confirm"**:
   - The event and activities are created, and the category, segments and scoring are stored in each activity's description.
   - Criteria are saved to score-based activities.
   - Judges and facilitators get access codes, with each judge assigned to their activities.
   - The new codes are listed, ready to print.

The scanned file becomes the event's document, linked in the event header.

**Layouts the scanner understands**
- **Structured catalogs:** `[ACTIVITY_START] … [IRR_START] … [ACTIVITY_END]` sections.
- **Numbered activities** followed by their details, such as `Category: … | Type: … | Target: …`.
- **Bulleted rules** (`• Criteria: A (40%), B (30%) …`), including lines that wrap onto the next line in PDFs.
- **Scoring rules** understood in rules text:
  - "Single Elimination (Bo1)" and "Best 2 out of 3" → bracket
  - "Match wins grant 3 points" → round robin
  - "Swiss System" and points per question → ranking
  - the score unit: Points, Sets, Rounds or Games

**On the activity page**, a **Rules & scoring** card shows everything taken from the document:
- category, type and who can join
- segments and scoring
- every rule line, such as time limits, penalties, rounds and how points are earned

The criteria appear in the Criteria tab.
## Archiving and deleting events

- **Archive** (event page → Archive):
  - The event moves to the **Archived** tab of the events list.
  - Its judges' and facilitators' access codes stop working, and anyone already signed in with one is signed out.
  - Nothing in it can be changed: the API refuses all edits with "restore it first".
  - Results stay viewable and printable.
  - **Restore** brings everything back as it was.
- **Delete** (event page → trash button, or the archived-event banner):
  - The confirmation lists how many activities, contestants, scores, teams and access codes will be removed.
  - The delete button unlocks only after the event's title is typed. The server checks the typed title too.
  - Deleting removes the event, everything in it and its uploaded files. The activity log keeps a record.
- Only administrators and the event's program head can archive, restore or delete.

## Activity logs

Every important action is recorded in `activity_logs`: who did it, their role, when, and from which IP address. This covers sign-ins (including failed ones), sign-outs, events, activities, scoring status, judge panels, teams, contestants, criteria uploads and saves, access codes (issued, replaced, disabled, deleted), submissions, unlocks, score resets, CSV exports and staff account changes.
- **Event → Activity logs** shows the event's history (admin, program head, facilitator) with filters, search and "load older".
- **Activity logs** in the admin menu shows the whole system.
- Logs are kept when an event is deleted.

## File server (uploads)

Every upload goes through `App\Storage\StorageInterface`, chosen with `STORAGE_DRIVER` in `.env`:

- `local`: a folder on this machine, a mapped drive, or a UNC share (`STORAGE_LOCAL_PATH=\\FILESERVER\tabulation`)
- `ftp`: a remote FTP/FTPS server (`FTP_HOST`, `FTP_USER`, `FTP_PASS`, `FTP_ROOT`…)

### Slow connections

Uploads have no time limit:
- **PHP:** `max_input_time -1` and `max_execution_time 0` are set in `.htaccess` for mod_php and in `.user.ini` for PHP-FPM or CGI.
- **API:** requests call `set_time_limit(0)`.
- **OCR:** the online OCR fallback only gives up after 5 minutes with no data at all.
- **Browser:** uploads never time out. If the signal drops, the upload waits until the device is back online and retries up to 5 times.
- **Apache:** on a new server, also set `Timeout 3600` and, if `mod_reqtimeout` is loaded, `RequestReadTimeout header=20-120,MinRate=1 body=0`. Reload Apache afterwards.

## Scoring rules

- A judge's total for a contestant is the sum of their criterion scores (each 0 to the criterion maximum).
- The final score is the average of judge totals from judges who **submitted**. "Include unsubmitted scores" gives a live preview.
- Ties share a rank (1, 1, 3).
- Overall: each activity marked "counts toward overall" gives placement points (default 10 / 7 / 5) to the team of each placing entry. Other ranked entries earn participation points. Tied teams are ordered by number of 1st, 2nd, then 3rd places.

## Project structure

```
index.html            Sign-in (the only page in the root)
pages/                dashboard, event, activity, judge, score, users, account, print
assets/css/app.css    Deep green / black / white responsive theme
assets/js/app.js      Core: API client, session guard, shell, dialogs, tabs
assets/js/modules/    forms, results, criteria (upload + review editor)
assets/js/pages/      One script per page
api/index.php         JSON API front controller (?r=resource.action)
app/
  bootstrap.php       Autoloader, .env, config, session
  routes.php          Route table with allowed roles
  Core/               Env, Config, Database, Request, Response, Router, Auth, Gate, Csrf, RateLimiter
  Controllers/        Auth, User, Event, Team, Activity, Contestant, Criteria, AccessCode, Score, Result
  Repositories/       SQL for each table
  Services/           Tabulator, CriteriaScanService, UploadedFile
  Documents/          DocumentReader, PdfTextExtractor, DocxTextExtractor, CriteriaParser, Ocr/*
  Storage/            StorageInterface, LocalStorage, FtpStorage, StorageManager
config/app.php        Configuration built from .env
database/schema.sql   Tables
install/setup.php     Installer
storage/              Default upload folder and scratch files (not web-accessible)
```

## Database indexes

`database/indexes.php` lists the indexes, each matched to a query in `app/Repositories`. Examples: activities by event and display order, criteria by activity and order, contestants by activity and number, judge codes by event, role and name. A covering index on scores makes tabulation read from the index only.
`install/setup.php` applies the list through `App\Core\SchemaIndexer` to new and existing databases. The step is safe to re-run.
After upgrading the code, open `install/setup.php` once and it adds any new indexes.

## Security notes

- Every POST requires the session CSRF token (sent automatically by `app.js`).
- Sign-in attempts are rate-limited per IP address.
- Disabled accounts and access codes lose access on their next request.
- `app/`, `config/`, `storage/`, `database/` and dotfiles such as `.env` are blocked by `.htaccess`. On Nginx or IIS, add equivalent deny rules.
- Set `APP_DEBUG=false` in production.
#   M u l t i _ T a b u l a t i o n _ S y s t e m  
 #   M u l t i _ T a b u l a t i o n _ S y s t e m  
 