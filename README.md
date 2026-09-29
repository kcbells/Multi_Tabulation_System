# PHINMA COC Multi-Event Tabulation System

Scoring and tabulation for PHINMA Cagayan de Oro College events. One **event** holds many
**activities** (pageant, dance, quiz bee…). Each activity has its own criteria, contestants,
judge panel and live rankings, and placements roll up into **overall team standings**.

Built with vanilla HTML / CSS / JavaScript (front end) and object-oriented PHP 8.1+ with MySQL (API).

**Look:** the "Scoreboard" theme in PHINMA COC colours: deep green leads everywhere (buttons, tabs, table headers, badges, header lines), the seal's gold marks honours only (trophies, 1st/2nd/3rd), with black lines, warm off-white paper and maroon for warnings and match-ups. Solid colours only.
- It loads no web fonts and no icon libraries, so it works offline on the campus network. Headings and numbers use Bahnschrift (built into Windows), or a condensed system font on other devices. Body text uses the device's own font.
- All icons are inline SVGs.

**Sidebar & borders:** white sidebar with black borders; cards, tables, tabs, fields and buttons use black borders too (the bracket keeps its own style). Menu clicks redraw the sidebar and top bar instantly, and a spinner only appears when a page takes a moment to load.

## Getting around

- **Back and "You are here":** every page below the start page begins with a **Back to …** button and the path to the page, e.g. `Events › Foundation Week › Pageant › Results`. *Back* always goes one level up (activity → its event's activities, Live Ops / Big screen → the event, event → the events list, score sheet → My activities, public activity → its event), never to wherever the browser happened to be. The last part of the path is the open tab, and every earlier part is a link. On phones the button just says *Back*.
- **Headers keep the everyday actions visible** (Live Ops, Big screen, Publish; the status switch and Certify on an activity). Everything else — print, results book, backup, edit, scan, archive, delete — is in the **More** menu, with delete last.
- **Tabs remember where you are:** each tab is in the address (`#results`) and the browser's **Back** button returns to the previous tab.
- **"Setup needed"** under an activity lists what is missing (criteria, contestants, judges, bracket), each linked to the tab where it is done; the disabled *Go live* button says the same when hovered.
- **Judges in one place:** an activity's **Judges** tab ticks who scores it and has **New judge**, which creates the judge's access code already assigned to that activity. The event's *Access codes* tab is for printing, renaming and disabling codes.
- **Facilitators** have *Event console, Live Ops, Big screen* and *Print standings* in their menu.

## Live Ops (event day)

`pages/ops.html` — **Live Ops** on the event header, the sidebar, or the facilitator menu. One screen for running the day:
- counters: live, not started, final, certified, judges still scoring, warnings
- every activity with its progress (judges submitted, matches played or results entered) and the unofficial leader
- a chip per judge (submitted / scored so far / not started, warnings in maroon); tap a submitted judge of a live activity to **unlock** them
- **Go live / Finalize** and **On screen** (puts the activity's standings or bracket on the big screen) on every row
- updates by itself

## Results book, backup and offline judging

- **More → Print results book:** overall standings, then every activity (score tables with deductions, awards and certificate codes; placements for brackets, round robins and rankings), one activity per printed page. Print it or save it as PDF.
- **More → Download backup (JSON):** everything in the event — activities, criteria, contestants, scores, submissions, history, deductions, awards, matches, results and the overall standings. Access codes themselves are left out. Administrators and the event's program head only.
- **Offline app:** on https (or localhost) the site installs a small service worker (`sw.js`) that keeps the pages, styles and scripts on the device. A judge whose signal drops can still open the score sheet; scores typed offline are kept on the device and sent when the connection returns. The API is never cached.

## Live updates

Every page updates by itself, with no refresh needed: the dashboard, event pages (activities, teams, access codes, standings, logs), activity pages (contestants, judges, results, bracket), staff accounts, activity logs, and the judge screens. Each page checks the server every few seconds (`sync.version`) and reloads its data only when something changed. It waits while someone is typing, has a dialog open or is arranging the bracket, and a full-screen bracket updates in place.

## Roles

| Role | Signs in with | Can do |
|---|---|---|
| Administrator | username + password | Everything, including staff accounts and every event |
| Program Head | username + password | Create and run their own events: activities, criteria, teams, access codes, results, special awards, **certifying results**, backups. Cannot see activity logs (recent activity) |
| Facilitator | **access code** (no account) | Their event only: contestants, go live / finalize, **Live Ops**, judge progress, unlock submissions, deductions, advancing finalists, live results, and the **big screen** |
| Judge | **access code** (no account) | Score the activities assigned to them and **submit each contestant** (Submit on every contestant; after the last one the whole sheet counts as submitted) |
| Audience | nothing (public page) | See the **public results page** of events a staff member published |

## Setup

1. Copy `.env.example` to `.env` and set the database, file server and OCR values.
2. Serve the `Scoring` folder with Apache + PHP 8.1+ (extensions: pdo_mysql, zip, dom, mbstring, fileinfo, curl; `ftp` only for the FTP driver).
3. Open `http://your-server/Scoring/install/setup.php`. It creates the database and tables, then asks you to create the first administrator.
4. Sign in at `http://your-server/Scoring/` (`index.html`).

## Criteria must total 100

The criteria of a score-based activity must add up to **exactly 100 points** (for example 40 + 30 + 30, or 33.33 + 33.33 + 33.34).
- The criteria editor shows how many points are missing or too many, and **Save** stays off until the total is 100.
- The scan wizard blocks **Next** and **Create event** while an activity's criteria are off.
- The server refuses any other total, and scoring cannot be opened for an older activity whose criteria do not total 100.

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

Ties share a rank unless a tie-break is set (score-based activities). Every format feeds its placements into the **overall standings** (team points bar chart and table). Bracket placements: champion 1, runner-up 2, 3rd-place match 3 and 4 (or both semifinal losers share 3), quarterfinal losers 5.

Every activity, whatever its format, uses the same statuses: **Not started → Live → Final → Certified**. The buttons say **Go live**, **Finalize** and **Reopen**. A final activity is locked for judges and scorers; a certified one is locked for everyone (see *Certified results*). The event status follows its activities by itself: the first activity that goes live makes the event **Ongoing**, and it becomes **Completed** once every activity is final (it can still be changed by hand, and a cancelled event is never changed).

## Setting up an event

1. **New event**: choose the **Event type**:
   - **One competition**: the event itself is the contest, for example a pageant or a battle of the bands. The system creates its single competition automatically, with the same title, venue and schedule. After saving, you go straight to its criteria, contestants, judges or bracket. Nature of Activity and an optional criteria sheet are entered in the event form. The competition cannot be deleted by itself, and no other activities can be added unless you switch the event to Multiple activities.
   - **Multiple activities**: an event such as Foundation Day, Intramurals or IT Days with several competitions — e.g. Mobile Legends and CODM (brackets), chess (round robin), a programming, crimping or IP subnetting contest (ranking by points or time), and singing or dancing (judged with criteria). Departments, tribes or colleges collect overall points. There is **no event-wide format**: each activity is decided its own way.

   Then enter the event title, venue, starting and ending date/time, Project Head and status. For **One competition** also choose how it is decided. The event status (Draft, Upcoming, Ongoing, Completed, Cancelled) follows its activities and can also be changed from the event page.
2. Right after a multi-activity event is created, **Add activities** opens: list every competition at once, one row each with its name, **how it is decided** (Score-based, Bracket, Round robin or Ranking) and Nature of Activity. Ranking rows also ask whether the **highest points** or the **fastest time** wins. **Quick add** chips fill in common ones (Mobile Legends, CODM, chess, programming contest, crimping, quiz bee, singing, dance…) with the usual format — rename or change any of them. Names already in the event are refused. Use **Add activities** on the Activities tab to add more later, and open an activity to set its venue, schedule, criteria sheet or rounds.
3. **Overview** shows an event setup checklist: activities → teams → criteria / brackets → access codes.
4. **Give access codes → Generate several** creates "Judge 1…N" (or facilitators) at once, assigned to chosen activities, ready to print as slips.

### Overall standings (optional)

Tick **Overall standings** in the event form when groups compete for the event title — departments at Foundation Day, or tribes such as Academia, Jujutsu and Titans at IT Days. Leave it off for an open tournament where nobody plays for a group.

| | With overall standings | Without |
|---|---|---|
| Groups | Typed in the new-event form (one per line) and managed in the **Groups** tab | None — no Groups tab |
| Participants | Every solo player or team **must choose its group** | Just a name (a team also lists its members) |
| Standings tab | **Overall standings**: group points from every activity | **Results**: each activity ranks its own entries |

**Every placing earns points**, the champion the most:
- **Countdown** (default for new events): 1st place gets as many points as there are groups, each place one less, and everyone who places gets at least 1. With 8 tribes: 8, 7, 6, 5, 4, 3, 2, 1.
- **My own points per place**: e.g. `15, 12, 10, 8, 6, 5, 4, 3, 2, 1`, plus the points for every place after those (so even the last place earns something).

Points of all a group's entries add up (a tribe with two chess players collects both placings). Tied ranks share the points of their place. Tied groups are ordered by number of 1st, 2nd, then 3rd places.

**Participants** are a **Solo player** or a **Team**; a team lists its members, one per line. Members appear on the participants list and on the judge's score sheet.

- Standings update automatically as soon as an activity has winners. Tick "Only count final activities" to count finished ones only.
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
## Public results page

`pages/public.html` (linked from the sign-in page as **Live results & standings**) needs no sign-in.
- An event appears there only after a staff member clicks **Publish results** on the event page (and never while it is a draft or archived). **Public: on** hides it again.
- Visitors pick an event, then an activity, and see its standings: podium, ranks, pictures and team colours. The **Overall standings** tab shows team points.
- **Brackets, round robins and rankings** update live, like a scoreboard.
- **Score-based (judged) activities** show only the line-up until the activity is **closed (final)**; then the ranks and final averages appear. They count toward the public overall standings only once final.
- Judges' names, their individual scores and criterion scores are never sent to the public page.
- Team logos and contestant pictures of a published event are visible to anyone.

## Big screen (TV / projector / LED wall)

A technical operator controls what the audience screen shows, like a broadcast switcher.
1. The event's **facilitator** (or staff) opens **Big screen** from the menu or the event page. This is the **control panel** (`pages/control.html`).
2. **Open screen** opens `pages/display.html`: drag it to the TV and press **F** for full screen (or **Copy screen link** and open it on the computer connected to the TV, signed in with a facilitator code of the event).
4. Every button goes on air at once. The monitor on the control panel shows exactly what the audience sees.

| Scene | Shows |
|---|---|
| Title card | Event name, venue and date, between segments |
| On stage | The contestant performing now on their own colour: big picture, number, name, team. Previous / Next (or ← →) moves through the line-up |
| Standings | Leaderboard of the chosen activity. Long lists turn pages by themselves. Marked **Final results**, or **Unofficial · live** while judging is open |
| Bracket | The bracket scaled to fit the screen, or the round robin table |
| Overall | Team points of the event (judged activities count once final) |
| Winners reveal | 3rd place, then 2nd, then the champion, one press at a time (Space). The screen never receives a name before it is revealed |
| Message | A title and a line of text (intermission, next segment…) |
| Blackout | Black screen |

- **Stage backgrounds:** every contestant has their own. On the control panel, the picture button on a contestant sets the background shown behind them in **On stage**, so switching contestants switches the background too. A contestant without one gets their colour. Wide (16:9) JPG, PNG or WEBP pictures up to 8 MB work best; a dark veil keeps the name readable.
- **Effects** play over any scene until switched off: Glitter, Stars (with shooting stars), Confetti, Spotlights (sweeping stage beams), Light orbs and Fireworks. **Confetti burst** (key **C**) fires confetti cannons from both bottom corners once, and fires by itself when the champion is revealed. A blackout hides the effects.
- **Show scores on screen** adds the result line (average, points, time) to standings and the reveal; off shows names and places only.
- Keys on the control panel: **1–8** scenes, **← →** contestants, **Space** reveal next, **C** confetti burst, **B** blackout.
- The screen follows result changes by itself and keeps the last picture if the network drops for a moment (the LIVE badge turns grey).

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
- Ties share a rank (1, 1, 3) unless a tie-break is set.
- Overall: each activity marked "counts toward overall" gives placement points (default 10 / 7 / 5, editable per event) to the team of each placing entry. Other ranked entries earn participation points. Tied teams are ordered by number of 1st, 2nd, then 3rd places.

### Scoring options (score-based activities)

Set in **Edit activity → Scoring & rounds**:

| Option | What it does |
|---|---|
| **Combine by average** (default) | Final score = average of the judges' totals |
| **Combine by rank sum** | Each judge's totals become ranks (ties share the average rank, e.g. 3.5); the lowest sum of ranks wins. One very harsh or generous judge cannot swing the result |
| **Drop highest & lowest** | With 3 or more judges, each contestant's highest and lowest judge are left out (struck through in the table) |
| **0–10 per criterion** | Judges enter 0–10 on every criterion and each counts for its points (8 on a 40-point criterion = 32). The criteria still total 100. Locked once judges have scored |
| **Tie-break** | Share the rank, or break ties by the higher average in one criterion, the lower rank sum, or (for rank sum) the higher average. Broken ties are marked in the table |

- **Deductions:** facilitators and staff take points off a contestant (Results tab → *Add deduction*, with a reason). They are subtracted from every judge total, shown in the table and on printouts, never on the public page.
- **Special awards:** *Best in …* goes to the highest average in a chosen criterion (ties share it); hand-picked awards (People's Choice, Photogenic…) go to the contestant you choose. Winners appear on the results, printouts, and on the public page once final (criterion averages stay private).
- **Judge warnings:** the Judges tab and Live Ops flag a judge who gives nearly the same total to everyone, or whose average is 15+ points below or above the rest of the panel, and show each judge's average and spread.

### Rounds (prelims → finals)

1. Create the finals as another score-based activity and choose **Previous round** (the prelims), how many **advance**, and how much of the previous score to **carry over** (e.g. 30%; 0 = the finals start fresh).
2. The prelims stop counting toward the overall standings; only the last round places teams.
3. Finalize the prelims, then press **Advance top N** on the finals. The top contestants by official (submitted) scores are copied with their number, team, colour and pictures. A tie at the cut lets everyone tied advance; pressing it again only adds who is missing.
4. Final score = carry% × prelim score + (100 − carry)% × finals score. The table shows both.

### Score history and unlocks

Every change to a score that was already entered is recorded (before → after, when, which judge), and every score entered after a facilitator **unlocked** a judge is flagged *after unlock*. See the **Score changes** card on the Judges tab. Clearing all scores keeps the history.

### Certified results

When an activity is final, a program head or administrator can **Certify results**:
- Scores, deductions, contestants, criteria and the status are locked; judges cannot be unlocked.
- A **certificate code** (e.g. `3F9A-12C0-77DE`, a fingerprint of every rank and result) is printed on every result sheet, so a printed tally can be checked against the system.
- The public page and the big screen say **Certified official results**.
- *Remove certification* (More menu) needs a reason, which is kept in the activity log.

### Judges' notes

Each judge has a private **My notes** box per contestant on the score sheet (never shown to anyone else). Before submitting a contestant, the judge sees where that total places among their own scores.

## Project structure

```
index.html            Sign-in (the only page in the root)
pages/                dashboard, event, activity, ops (Live Ops), judge, score, users, account, print
sw.js                 Offline app shell (service worker)
assets/css/app.css    Deep green / black / white responsive theme
assets/js/app.js      Core: API client, session guard, shell, dialogs, tabs
assets/js/modules/    forms, results, criteria (upload + review editor)
assets/js/pages/      One script per page (public.js: public results, control.js + display.js: big screen)
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
`install/setup.php` applies the list through `App\Core\SchemaUpgrader` to new and existing databases. The step is safe to re-run.

**Upgrades apply themselves:** after new code is deployed, the first request brings the database up to date (new tables from `schema.sql`, `columns.php`, `column_changes.php`, `backfills.php`, `indexes.php`) and records it in `storage/tmp/schema.<database>.version`. Bump `SchemaGuard::VERSION` in `app/Core/SchemaGuard.php` whenever one of those files changes. Opening `install/setup.php` still does the same by hand.

## Security notes

- Every POST requires the session CSRF token (sent automatically by `app.js`).
- Sign-in attempts are rate-limited per IP address.
- Disabled accounts and access codes lose access on their next request.
- `app/`, `config/`, `storage/`, `database/` and dotfiles such as `.env` are blocked by `.htaccess`. On Nginx or IIS, add equivalent deny rules.
- Set `APP_DEBUG=false` in production.
