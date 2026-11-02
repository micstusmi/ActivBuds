# ActivBuds advertiser campaign drafts

This implements the existing blank `campaign_edit.php` route. It uses the
advertiser account and `ad_campaigns` columns already referenced by the dashboard.
It does not enable an ad-serving or billing system.

The connected `advertiser_dashboard.php` now searches campaign names, filters
drafts/archived campaigns and provides edit/view links. Search is a literal substring, so `%` and `_`
do not become wildcards. Lists are advertiser-scoped, newest first, capped at 100
matching rows with a visible limit notice. Empty and filtered-empty states have
clear next actions. Staff retain read-only campaign visibility; current active
owner membership is required for editing. The dashboard rechecks membership on
each request. Legacy aggregate metrics remain all-time and independent of list
filters. Missing metrics do not block campaign planning and display unavailable,
never a fabricated zero.

Owners can duplicate any compatible owned campaign into a NEW draft containing
its current settings and text, with a `(copy)` name. The source's delivery status
is never copied. Drafts can be archived and restored without deleting content.
Archive is refused for active/non-draft campaigns; restore only accepts archived
rows and always returns them to draft. These actions are POST-only, session-CSRF
protected, fresh-owner checked and advertiser scoped. Current status is matched
in the UPDATE predicate. Staff cannot submit these actions. The dashboard uses
post/redirect/get and session flash messages; interrupted duplicate requests
should be reconciled from the library before another copy is requested.

## Working flow

1. Sign in as an active advertiser owner and choose **Create new campaign** from
   the existing dashboard.
2. Enter a name, choose an existing advertiser venue (or choose later), a planned
   whole-number radius of 1–250 km, and image/carousel/video.
3. Optionally write a headline (90 characters), message (500 characters) and full
   HTTPS destination. Inspect the live draft text layout and choose **Save draft**.
4. Reopen the saved campaign from the dashboard's existing **View / Edit** link.
5. Update a draft or save without changes. A stale tab receives a conflict rather
   than overwriting a newer draft. Campaigns outside draft status are read-only.

The POST redirects to the saved draft and a session-bound confirmation. No URL
parameter alone creates a success message. Signed-out visitors go to sign-in;
missing/foreign campaign IDs return the same unavailable message. A fresh
advertiser-user query checks active owner membership on each request. Reads and
writes use the session advertiser ID, never a submitted advertiser ID. CSRF and
input validation protect saves. The compare-and-update predicate includes the
previous fields with binary text comparison and draft status.
Headline, message and destination are included in that revision and comparison,
so a stale content edit is also refused. Text is escaped on server rendering and
updated with textContent in the browser. The destination is plain preview text,
not a fetched/clickable URL. Non-HTTPS URLs and embedded credentials are refused.
The optional saved location ID is included in revision/CAS too. Location options
come only from the session advertiser's existing advertiser_locations rows.
Foreign/unavailable IDs are refused; INSERT/UPDATE also check owned-location
existence in the same SQL statement. A no-change save rechecks location ownership
before confirming. Duplication retains a valid owned selection. A removed or
unavailable venue is shown explicitly rather than replaced with another venue.
No location records are created, geocoded or sent to a map/provider here.

## What is deliberately not activated

There is no publishing, spend, estimated auction price, location lookup, media
upload, provider request or ad placement. Radius and format are draft preferences.
An active campaign cannot be edited or paused with this editor. Authorised staff
editing, real-time pricing, delivery and creative uploads remain later slices of
the original product request. The product's legacy Fitbuds branding is preserved;
this patch does not invent a new logo/favicon or claim shared Suite sign-in.

## Schema and installation

`schema/001_campaign_drafts.sql` is a minimal fresh-install campaign table, not a
discovery or upgrade script. It creates only `ad_campaigns`; existing advertiser
accounts and locations are prerequisites. No migration is applied by browsing
the page. No data is dropped or rewritten by the SQL file.
The still-unapplied baseline now includes headline/message/destination_url and
nullable location_id. An
existing database, including an earlier prototype baseline, needs explicit
column/default reconciliation before this revision; the editor does not perform
implicit ALTERs. This is source preparation, not an installed schema migration.
The baseline's status VARCHAR supports `archived`; an existing status ENUM or
CHECK must be inspected and explicitly reconciled before archive/restore is
enabled on that database. This patch never alters status constraints at runtime.

Before installing into an existing database, inspect its actual `SHOW CREATE
TABLE ad_campaigns`, table engine, required columns without defaults, triggers,
status/media constraints and advertiser ID types. The repository did not contain
that schema. `CREATE TABLE IF NOT EXISTS` does not validate an existing table.
An operator must reconcile differences through a reviewed upgrade rather than
assuming the fresh baseline is compatible. Native MySQL save/update and concurrent
write checks on that actual schema remain required before deployment.

Install the editor, helper and two assets together. Asset loaders are versioned
CSS `drafts-5` and JavaScript `drafts-3`; advance each changed asset's references
for later revisions. Retain the current
shared header/footer. Back up replaced source and database before approved
schema work. Recovery restores the prior source; preserve campaign drafts and
reconcile an interrupted save before creating another. Do not blindly reverse
data or drop the table. Keep one deployment owner and inspect the actual runtime
root/source first; no ActivBuds production location has been verified here.

## Focused evidence and remaining acceptance

Run `PHP_BINARY=/path/to/php python3 tests/campaign_drafts.py` in a source checkout.
The fixture executes the real controller/helper through a temporary PHP HTTP
server with synthetic persisted DB and owner identities. It covers create,
redirect/reopen, escaping, edit/no-change, CSRF/invalid input, stale revision,
active-campaign refusal, foreign advertiser isolation, staff refusal and bad IDs.
The connected dashboard checks include campaign search/draft filter, literal
wildcard search, read-only staff, foreign advertiser empty state and missing
metrics recovery. Creative persistence/escaped rendering, unsafe/credentialed
destination refusal and headline bounds, plus archive/restore/duplicate and
their cross-owner/staff/CSRF/state controls, plus owned venue persistence/change,
foreign venue refusal and choose-later behavior extend the suite to 40 HTTP requests
with its synthetic data collaborator. The directly affected existing 10-case
advertiser HTTP fixture and real PHP session/CSRF/credential fixture also pass;
their test copies now include the dashboard helper and membership response.
It cannot establish native SQL, real identity/session revocation or deployed
schema acceptance. `--preview` keeps that isolated synthetic server running for
local review until interrupted. It is a test router, never a deployable auth mode.

PHP syntax and JavaScript syntax checks apply only to these changed files.
No unrelated auth/deletion, native database or paid build suite is needed for
source review; omitted checks must not be reported passed.

### Page shell and visual acceptance

Routes: `campaign_edit.php` (new and existing draft) and `advertiser_dashboard.php`.

| Element | Source / current status |
| --- | --- |
| Header | Existing `includes/header.php` included unchanged; retains original product branding/account controls |
| Footer | Existing `includes/footer.php` retained; editor adds visible dashboard/help footer |
| Sidebar | Scoped advertiser navigation in `campaign_edit.php`; wraps into a readable menu at narrow widths |
| Favicon | Missing from existing repository/header; approved product asset unavailable, so NOT verified |

Desktop and narrow browser rendering, keyboard/summary interaction, existing
Bootstrap menu behavior and the actual product favicon still need rendered
acceptance. No local browser executable was available for screenshots. Do not
call this restyle visually accepted or production-ready while that required
shell asset and browser evidence are missing. No exception is inferred from the
legacy header. Existing `/fitbuds/` absolute header links also need confirmation
against the actual mount path. Local editor/sidebar links are relative.
