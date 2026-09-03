# DAVCal (Cartoonists United fork)

Fork of the [DAVCal DokuWiki plugin](https://www.dokuwiki.org/plugin:davcal) running on hub.cartoonist.coop. Upstream gives one calendar per wiki page, with CalDAV sync. This fork adds the things CU actually needed: auto-generated event pages, a public feed for the WordPress site, single-event ICS downloads, recurring-event editing, and our stamp rally sheet generator that we use for conventions.

This page has two parts: **Usage** for day-to-day calendar administration, and **Code Documentation** for maintenance and extension.

Built on `dokufreaks/dokuwiki-plugin-davcal` (upstream) by way of `scottleechua/dokuwiki-plugin-davcal`, which fixed the SQLite deprecation errors and aggregate calendar CalDAV URLs.

## Usage

### Calendars example

Four calendar pages. The plugin knows these by page ID, hardcoded in `helper.php`:

| Calendar page | Event pages go in | Notes |
|---|---|---|
| `calendars:meeting_calendar` | `meetings:` | |
| `calendars:opportunities_calendar` | `opportunities:` | |
| `calendars:events_workshops_calendar` | `events_workshops:` | |
| `calendars:appearances_calendar` | `appearances_cons:` | Own dialog, own page template, stamp rally |

You can add additional calendars without a problem.

### Event pages

Saving a new event creates a wiki page for it automatically, in the namespace from the table above. Page ID is `namespace:YYYY-MM-DD_event_title`, lowercased, punctuation turned into underscores.

* Existing pages are never overwritten. Editing the event does not touch the page.
* Deleting an event deletes its page. Deleting a single occurrence of a recurring event does not, the rest of the series still needs it.
* The Appearances calendar uses a different page template with a member table (name, table number, panels, stamp rally Y/N). **Regenerate** rebuilds that page from the current event data and keeps the Notes and Recording Links sections.

### Public events

Every event dialog has a **Public Event** checkbox. Checked events get `X-DAVCAL-PUBLIC:1` in their ICS data and show up on the two public feeds. Appearances are always public, the checkbox is forced on.

Two feed URLs, both open to anyone, no login:

* `lib/plugins/davcal/publicfeed.php` is ICS. Subscribe to it from a calendar app or The Events Calendar in WordPress. Optional `?name=` sets the calendar name inside the file.
* `lib/plugins/davcal/publicfeed_json.php` is JSON for the FullCalendar embed on the WordPress site. FullCalendar adds `?start=...&end=...` for whatever range is on screen. Recurrences are expanded on the server, times come out in UTC, and FullCalendar shifts them to the viewer's time zone.

**For a FullCalendar embed, use the JSON feed, not the ICS one.** FullCalendar's ICS connector ignores its own `timeZone` setting (their bug #7364), so ICS times come out wrong no matter what the file says. The JSON feed does not have that problem.

### Single event download

Every event popup has an "Add to calendar" link that hits `singleevent.php?uid=...` and downloads one event as an ICS file. For a recurring event, `&occurrence_date=YYYY-MM-DD` gives you just that one occurrence with the repeat rule stripped out.

### Recurring events

Upstream only had "repeat forever". This fork adds:

* **Ends:** never, on a date, or after a number of times.
* **Delete just this occurrence** vs. delete the whole series. Single-occurrence delete writes an `EXDATE` to the master event, it does not create a new record.
* Editing one occurrence writes a `RECURRENCE-ID` override. Re-editing the same occurrence replaces the override instead of stacking a new one on top.

### Stamp rally sheet

`lib/plugins/davcal/stamprally.php?page=appearances_cons:2026-06-06_my_event` renders a printable stamp rally sheet from an Appearances event page. One box per member who has stamp rally set to `Y` in the page's member table. 3 columns up to 9 members, 4 columns after that.

Add `&paper=a4` for A4, default is US Letter. The page has a link to flip between the two. Anyone who can read the wiki page can print the sheet.

Members opt in from the Appearances dialog, "Participate in stamp rally?" checkbox. That writes the Y/N column on the page.

### Troubleshooting

* **Public feed empty?** Check the event actually has the Public Event box checked. The feed only reads `X-DAVCAL-PUBLIC:1`, calendar-level settings do not matter.
* **Event page did not get created?** The calendar page ID has to be in `$namespaceMap`. Calendars not in that list save the event fine but don't get a page.
* **Embed times are off?** Confirm the embed is pointed at `publicfeed_json.php`, not `publicfeed.php`. See Public events above.
* **JSON errors in the browser console after saving an event?** `action/ajax.php` buffers output so PHP warnings cannot break the JSON response. If you see garbage before the `{`, something is echoing outside that buffer.

## Code Documentation

### What the CU fork changes

Diff against upstream is small on the sync/CalDAV side and large in `helper.php` and `script.js`.

* `helper.php`: event page creation, public feeds, single-occurrence delete, override repair. About 12 new methods also listed under Helper methods below.
* `action/ajax.php`: output buffering, event page hooks on new/delete, and five new AJAX actions (`checkEventPage`, `createEventPage`, `regenAppearancePage`, `appendMember`, plus Appearances handling inside `newEvent`).
* `script.js`: Public Event checkbox, Ends row on the recurrence UI, single-vs-series delete prompt, Appearances dialog, "Add to calendar" link.
* `lang/en/lang.php`: strings for the above.
* `syntax/calendar.php`: one-line fix, `$id` not `$ID` when looking up the calendar for a page.
* Vendor `sabre/*` libraries are unchanged from upstream (sabre/vobject 3.4.7).

### File structure

Only the files this fork added or matters for. Everything else is stock upstream.

```
plugin.info.txt              version/author info, bump the date here after changes
helper.php                   all the real logic: DB access, event pages, feeds, recurrence
action/ajax.php              AJAX endpoint for the calendar UI (add/edit/delete/settings + event page actions)
script.js                    FullCalendar 3.10.5 wiring, all dialogs, recurrence UI
syntax/calendar.php          {{davcal>}} syntax, renders the calendar into a page
publicfeed.php               public ICS feed, no login
publicfeed_json.php          public JSON feed for FullCalendar embeds, no login
singleevent.php              one event as a downloadable ICS
stamprally.php               printable stamp rally sheet from an Appearances event page
repair_overrides.php         one-time admin tool, cleans bad RECURRENCE-ID overrides, delete after use
test_subscription.php        dev script, checks the calendarsubscriptions table and helper DB access
db/                          SQLite schema migrations, update0001 through 0005
vendor/                      sabre/dav, sabre/vobject and friends, untouched
```

### Helper methods added

| Method | What it does |
|---|---|
| `getEventPageId($calendarPage, $title, $date)` | Builds the wiki page ID for an event or `false` if the calendar is not in `$namespaceMap` |
| `createEventPage($calendarPage, $eventData)` | Creates the page if it does not exist. Hands off to `createAppearanceEventPage()` for Appearances |
| `createAppearanceEventPage(...)` | The Appearances page template with the member table |
| `regenAppearanceEventPage($pageId, $params)` / `regenAppearanceEventPageContent(...)` | Rebuilds an Appearances page, preserves Notes and Recording Links |
| `appendMemberToEventPage(...)` | Adds one row to an Appearances member table |
| `setMembersFlag(...)` | Marks an event as having members, drives the badge in the calendar view |
| `getMostRecentEventUid($calendarPage)` | Looks up the UID of the event just saved, because `newEvent` does not return it |
| `getPublicEventsAsICS($name)` | Everything with `X-DAVCAL-PUBLIC:1`, serialized as one ICS |
| `getPublicEventsAsJSON($start, $end, $tz)` | Same events, recurrences expanded via sabre `EventIterator`, times in `$tz` |
| `finalizePublicEntry(...)` | Shapes one expanded occurrence into a FullCalendar event object |
| `deleteSingleOccurrenceForPage(...)` | Adds an `EXDATE` to the master event instead of deleting anything |

### How event page creation hooks in

`newEvent` in `action/ajax.php` saves the event through the normal upstream path first, then calls `createEventPage()`. For Appearances it first calls `getMostRecentEventUid()` to get the real UID, because upstream's save path does not hand it back and the Appearances page needs it for the "Add to calendar" link.

`deleteEvent` does the mirror: delete the event, then if it is an Appearances event and not a single-occurrence delete, blank the page with `saveWikiText()`.

### Recurrence and overrides

Single-occurrence edits write an override VEVENT with `RECURRENCE-ID` into the same calendar object as the master.

1. Re-editing an occurrence must **replace** the override with the matching `RECURRENCE-ID`, not append another one. 
2. The `RECURRENCE-ID` must be the **original** slot the RRULE generated, not the occurrence's current (possibly moved) start. An override whose ID matches no RRULE date is drawn as an extra event.

### Public feed pipeline

`publicfeed_json.php` → `getPublicEventsAsJSON()` → loop every calendar object with the public flag → sabre `EventIterator` expands RRULE/EXDATE/overrides for the requested range → `finalizePublicEntry()` turns each occurrence into `{title, start, end, allDay, ...}` with ISO8601 times in UTC.

This mirrors what the plugin's own in-wiki calendar already does through `getEvents` in `action/ajax.php`. If the in-wiki calendar shows an event correctly and the public feed does not, the difference is in the public flag check, not the date math.

`publicfeed.php` (ICS) is simpler, it concatenates the raw VEVENTs. Fine for subscribing from a calendar app, wrong for a FullCalendar embed, see Usage.

### Deployment

No build step. Upload changed files to `lib/plugins/davcal/` on the server. `db/` migrations run automatically on next load if `db/latest.version` is higher than what the SQLite database has.

Server-side debugging: no log access on this host, so `file_put_contents('/tmp/davcal.log', ...)` in the code path, then read it over SSH.

### Extending

* **New calendar with event pages:** add to `$namespaceMap` in `getEventPageId()` and `$calendarNames` in `createEventPage()`.
* **New field on event pages:** edit the template string in `createEventPage()` (or `createAppearanceEventPage()` for Appearances) and the matching regex in `regenAppearanceEventPageContent()` if it needs to survive a regenerate.
* **New public feed format:** call `getPublicEventsAsJSON()` and reshape the output. Do not re-implement the expansion.
* **New stamp rally layout:** `stamprally.php` is self-contained HTML/CSS, the member parsing regex at the top expects the exact table format the Appearances page template writes.

---

*Need help? Reach out to the Web Dev / IT committee at tech@cartoonist.coop.*

