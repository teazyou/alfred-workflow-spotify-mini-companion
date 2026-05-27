# alfred-workflow-spotify-mini-companion — Implementation Plan

## 1. Overview

A small Alfred companion workflow that adds a single keyword (recommended: `spnuke`) which, when triggered, takes the user's currently-playing Spotify track, ensures a playlist named "Nuked" exists, adds the track to that playlist, then removes the track from every *other* user-owned playlist. The user's Liked Songs library is untouched (the saved-tracks API endpoint is separate from playlists and is never queried).

This is a **companion** to vdesabou/alfred-spotify-mini-player, not a fork or in-place patch. The mini player auto-updates and would overwrite any local edits. The companion reuses the mini player's cached library (`library.db`) and its already-granted OAuth credentials (refresh token + client_id/secret) via read-only access — the user does not have to do any new OAuth setup. The companion is distributed as its own `.alfredworkflow` package and installs into its own Alfred workflow directory.

## 2. Constraints

- **Public repo — no secrets ever committed.** No client IDs, client secrets, refresh tokens, access tokens, user IDs, playlist URIs, `library.db` dumps, or Alfred workflow UUIDs from any developer machine. Source must reference credentials only via runtime discovery, never as literals.
- **`.gitignore` must exclude** at minimum: `*.alfredworkflow`, `vendor/`, `.env`, `*.db`, `*.sqlite`, `*.log`, `.DS_Store`, `dist/`, anything matching `prefs.plist`.
- **Read-only on mini player files.** The companion MUST NOT write into `~/Library/Application Support/Alfred/Alfred.alfredpreferences/workflows/user.workflow.<MP_UUID>/` nor into `~/Library/Application Support/Alfred/Workflow Data/com.vdesabou.spotify.mini.player/`. Only opens `library.db` with `SQLite3::OPEN_READONLY`; only reads mini player config via Alfred's read API.
- **Must survive mini player updates.** Coupling points (DB path, schema, config key names) are documented in `docs/coupling.md` (created in T1) so a future mini player release can be diffed quickly. The companion does its own access token refresh — it does NOT call any function inside the mini player's PHP code.
- **Refresh-token reuse is safe.** Spotify access tokens are stateless bearer tokens scoped per Spotify *app* (client_id), valid ~1 hour. Multiple processes can independently exchange the same refresh token for short-lived access tokens — Spotify does not invalidate the refresh token on use (it occasionally rotates it, in which case the new value is returned in the response; we discard our copy, the mini player will re-fetch on its next run, and any race is recoverable because the previous refresh token typically keeps working for a grace period). We never write the rotated value back to the mini player's config — that's the mini player's job; we just use what we read.

## 3. Architecture

### Keyword choice
**Recommended: `spnuke`** (or `spnk`). Rationale:
- `nuke` alone is too generic and likely to collide with other workflows.
- `sp nuke` would conflict with the mini player's primary `sp` keyword (a script filter), so it cannot be a sub-keyword without re-implementing the mini player's filter — out of scope.
- `spnuke` is unique, mnemonic, and clearly Spotify-related. The keyword is configurable via Alfred's UI after install if the user wants something else.

### Trigger type
**Direct Run Script action**, not a Script Filter. The user types the keyword and hits Enter — there is no list to filter, no preview state, and the action is irreversible enough that we don't want it firing on every keystroke. The Run Script node executes our PHP entry point synchronously.

### Language: PHP (recommended) vs. Python
**PHP** wins, because:
- The Spotify SDK we need (`jwilsson/spotify-web-api-php`) is the same one the mini player uses — battle-tested against this exact API and the auth flow.
- macOS ships a usable PHP CLI (`/usr/bin/php`); Alfred conventions are PHP-friendly.
- Keeping the same SDK simplifies future code-sharing or reference lookups.

Alternative: **Python 3** with `spotipy`. Equally valid technically — Python is a touch easier to ship without composer — but loses the SDK-parity advantage. Stick with PHP for this plan.

### Call sequence
1. Alfred fires `src/nuke.php` via the Run Script action.
2. `MiniPlayerBridge` resolves paths, reads mini player's `prefs.plist` (via AppleScript) for `oauth_client_id`, `oauth_client_secret`, `oauth_refresh_token`.
3. Refresh the access token against `https://accounts.spotify.com/api/token`.
4. Spotify SDK call: `GET /v1/me/player/currently-playing` — get current track URI + display name.
5. Bridge opens mini player's `library.db` read-only; queries for "Nuked" playlist (owned-by-user).
6. If missing: `GET /v1/me` for user_id, then `POST /v1/users/{user_id}/playlists` with `name=Nuked, public=false`. Hold returned URI in memory.
7. `POST /v1/playlists/{nuked_id}/tracks` adds the track.
8. Bridge queries `library.db` for all other owned playlists.
9. Loop: `DELETE /v1/playlists/{id}/tracks` for each. (Skipped if URI matches Nuked.)
10. macOS notification with summary; exit 0.

## 4. Companion ↔ Mini Player coupling

**Confirmed via on-disk investigation:**

### Paths
- Mini player workflow data dir: `$HOME/Library/Application Support/Alfred/Workflow Data/com.vdesabou.spotify.mini.player/`
- Main library DB (authoritative): `<data_dir>/library.db` — contains 11 playlists in this user's case.
- Per-user DB at `<data_dir>/users/<userid>/library.db` exists but was **empty** in this investigation — do NOT rely on it. Use the top-level `library.db`.
- Mini player install dir (UUID-named, varies per install): the companion does NOT read from here. We only read `library.db`.

### Credential storage (CRITICAL — verified)
The mini player's `getSetting()` (functions.php:7931) reads via `getenv('__' . $setting_name)`. Settings are *written* via `osascript` calls that invoke Alfred's `set configuration ... in workflow "com.vdesabou.spotify.mini.player"` API (functions.php:8010). The legacy `settings.db` / `settings.json` paths exist only for one-time migration on first run after upgrade. On this Mac, neither file exists.

Persisted location: `<mini_player_install_dir>/prefs.plist` (Alfred's per-workflow config plist), keys:
- `__oauth_client_id`
- `__oauth_client_secret`
- `__oauth_refresh_token`
- `__oauth_access_token` (optional — we'll re-fetch our own)
- `__userid` (optional — we can also call `GET /v1/me`)

All four are **plaintext strings**, no encryption.

### Read strategy (companion side)
`MiniPlayerBridge` reads each key via:

```
osascript -e 'tell application id "com.runningwithcrayons.Alfred" to return value of configuration "__oauth_refresh_token" in workflow "com.vdesabou.spotify.mini.player"'
```

This is Alfred's documented cross-workflow read API. It survives any prefs.plist format changes. Falls back to direct `PlistBuddy` parse if the AppleScript path fails (e.g. Alfred not running). Implemented as `MiniPlayerBridge::readMiniPlayerSetting($key)`.

### Tables we depend on
Only `playlists` in the top-level `library.db`. Schema (verified):
```
playlists(uri TEXT PK, name TEXT, nb_tracks INT, author TEXT, username TEXT,
          playlist_artwork_path TEXT, ownedbyuser BOOLEAN, nb_playable_tracks INT,
          duration_playlist TEXT, nb_times_played INT, collaborative BOOLEAN,
          public BOOLEAN, name_deburr TEXT)
```
We read `uri`, `name`, `ownedbyuser`. Nothing else. We do NOT touch `tracks`, `episodes`, `shows`, `followed_artists`, `counters`.

### Coupling points to watch on mini player updates
Documented in `docs/coupling.md`:
1. The four `__oauth_*` config key names (functions.php `resetSettings()` ~line 8019).
2. The path `<workflow_data>/library.db` and that it remains the authoritative copy.
3. The `playlists` table columns `uri`, `name`, `ownedbyuser`.
4. Alfred's bundle id `com.runningwithcrayons.Alfred` and the mini player's bundle id `com.vdesabou.spotify.mini.player`.

## 5. Spotify API calls used

- `POST https://accounts.spotify.com/api/token` (grant_type=refresh_token, refresh_token=<from prefs>, with Basic auth = client_id:client_secret base64) — exchanges refresh token for ~1h access token at every invocation. No persistence on our side.
- `GET /v1/me/player/currently-playing` — primary source of the current track URI. Falls back to `GET /v1/me/player` if the first returns 204 (no content) or `currently_playing_type=ad`.
- `POST /v1/playlists/{id}/tracks` — add to Nuked. Body `{"uris": ["<track_uri>"]}`.
- `DELETE /v1/playlists/{id}/tracks` — remove from each other owned playlist. Body `{"tracks": [{"uri": "<track_uri>"}]}`.
- `GET /v1/me` — only when Nuked doesn't exist yet, to get user_id for the create-playlist call. (Skippable if `__userid` config var is populated.)
- `POST /v1/users/{user_id}/playlists` — create "Nuked" the first time. Body `{"name": "Nuked", "public": false, "description": "Tracks nuked by spnuke companion."}`.

We deliberately do NOT call `GET /v1/me/playlists` — `library.db` is our cheap source of truth and the mini player keeps it fresh hourly. If it's stale (e.g. user created a playlist seconds ago in the Spotify app), at worst we miss removing the track from that new playlist on this invocation; the next invocation catches it.

### Rate limits
Worst-case ~13 calls per invocation (1 token + 1 currently-playing + 1 add + 10 deletes). Far under Spotify's app-tier limit. Implementation: serial, no concurrency, no backoff needed. If 429 ever appears, log and abort — do NOT retry silently (better to fail fast than partially nuke).

### URI vs ID
`library.db` stores full `spotify:playlist:<id>` URIs and `spotify:track:<id>` for tracks. The REST endpoints want bare `<id>` in path params but full `<uri>` in JSON bodies for the DELETE call. `Nuker::extractId($uri)` returns the segment after the last colon.

## 6. "Nuked" playlist resolution

```php
// Step 1
SELECT uri FROM playlists
 WHERE lower(name) = 'nuked' AND ownedbyuser = 1
 ORDER BY nb_tracks DESC
 LIMIT 1;
```
`ORDER BY nb_tracks DESC` breaks the tie deterministically if the user has multiple — we pick the one that's been used most. Documented behavior, edge case.

If 0 rows:

```php
// Step 2 — auto-create (with race-protection check first)
// Sanity check: maybe library.db is stale and Spotify already has "Nuked"
$existing = $api->getMyPlaylists(['limit' => 50]);  // GET /v1/me/playlists?limit=50
// Search response for name="Nuked" + owner.id == me.id. If found, use that URI.

// Otherwise create:
$me = $api->me();                  // GET /v1/me
$pl = $api->createPlaylist($me->id, [
    'name'        => 'Nuked',
    'public'      => false,
    'description' => 'Tracks nuked by spnuke companion.',
]);                                // POST /v1/users/{user_id}/playlists
$nukedUri = $pl->uri;              // spotify:playlist:XXXX
```
The newly-created (or rediscovered) playlist URI is held in memory only. We do NOT update `library.db` (read-only contract). Next mini player refresh picks it up automatically.

## 7. Which playlists to remove from

```sql
SELECT uri, name FROM playlists
 WHERE ownedbyuser = 1
   AND uri <> :nuked_uri;
```

Loop:
```php
foreach ($rows as $row) {
    $api->deletePlaylistTracks(
        Nuker::extractId($row['uri']),
        ['tracks' => [['uri' => $trackUri]]]
    );
    $removed++;
}
```

**Optimization** (important): Spotify's `DELETE /v1/playlists/{id}/tracks` returns 200 even if the track is not in the playlist — it's idempotent. We therefore do NOT call `GET /v1/playlists/{id}/tracks` first to check membership. That saves ~N read calls. Documented in code comments and in `docs/coupling.md` because if Spotify ever changes this behavior, we'd want to add pre-checks.

## 8. User feedback

- Use macOS notifications via `osascript -e 'display notification "<body>" with title "spnuke" subtitle "<track>"'` directly from PHP via `exec()`.
- Success: `"Added to Nuked, removed from 7 playlists."` (counts vary).
- Already-in-Nuked (no-op add): `"Already in Nuked, removed from 3 playlists."`
- No track playing: `"No track currently playing."` (exit 0; not an error).
- Auth failure (refresh token rejected): `"Auth failed — re-authorize Spotify Mini Player."`
- Mini player not installed (no prefs.plist, no library.db): `"Spotify Mini Player not installed."`
- API call failure mid-loop: notify partial state honestly, e.g. `"Added to Nuked; removed from 4 of 7 playlists before error: <message>."`

Optional: also emit Alfred-flavored JSON output to stdout so Alfred's debugger shows the result.

## 9. Repo layout

```
/Users/teazyou/dev/alfred-workflow-spotify-mini-companion/
├── PLAN.md                            # this file
├── README.md                          # user-facing install & usage
├── LICENSE                            # MIT, presumably
├── .gitignore                         # excludes *.alfredworkflow, vendor/, *.db, *.env, prefs.plist, dist/
├── composer.json                      # require jwilsson/spotify-web-api-php
├── composer.lock                      # COMMITTED (reproducible installs)
├── info.plist                         # Alfred workflow definition: keyword, script object, icon ref
├── icon.png                           # 256x256 placeholder (mushroom-cloud or similar)
├── build.sh                           # `composer install --no-dev` then zip into dist/spnuke.alfredworkflow
├── docs/
│   └── coupling.md                    # explicit list of mini-player coupling points (see §4)
├── src/
│   ├── nuke.php                       # Alfred entry point — orchestrator only, ~80 lines
│   └── lib/
│       ├── MiniPlayerBridge.php       # path discovery, prefs read, library.db read, token exchange
│       └── Nuker.php                  # add-to-nuked + loop-remove core logic
└── tests/
    ├── MiniPlayerBridgeTest.php       # mocked osascript + sqlite fixture
    └── NukerTest.php                  # mocks the SpotifyWebAPI class, asserts call sequence

(generated, not committed)
├── vendor/
└── dist/spnuke.alfredworkflow
```

Justifications:
- **`composer.lock` committed**: this is an application, not a library — reproducibility outweighs the "library lock" argument.
- **`info.plist` committed**: it's the workflow definition (keyword, script, icon ref). It does NOT contain user-specific config (no `prefs.plist` here, no UUID-named install dir state) — Alfred generates the UUID at install time.
- **`docs/coupling.md` committed**: documents what we read from the mini player, so future updates can be diffed against this contract.
- **`tests/` light**: real Spotify calls require a real account; unit tests mock the SDK and the SQLite layer.
- **`build.sh`**: simple shell — `composer install --no-dev --optimize-autoloader && zip -r dist/spnuke.alfredworkflow info.plist icon.png src vendor`.
- **No CI**: public repo, no secrets, can't do live integration in CI without exposing tokens.

## 10. Step-by-step implementation tasks

Each task is self-contained with explicit inputs and outputs. Order matters — later tasks depend on earlier ones.

**T1 — Verify and document mini player coupling.**
- Inputs: this PLAN.md (especially §4).
- Outputs: `docs/coupling.md` containing exact paths, the four config keys, the SQL schema, the AppleScript read command, and a "what to check on mini player updates" checklist.
- Acceptance: contents match §4 and reference the file paths verbatim.

**T2 — Scaffold repo.**
- Inputs: layout in §9.
- Outputs: `composer.json` (require `jwilsson/spotify-web-api-php`, php >= 8.1, autoload PSR-4 `Spnuke\\` -> `src/lib/`), `.gitignore`, empty `info.plist` template, `README.md` stub, `LICENSE`, `build.sh`, `src/nuke.php` + `src/lib/*.php` skeletons with class headers only.
- Acceptance: `composer install` works locally; `php -l src/nuke.php` passes; nothing user-specific in any file; `git status` shows no `.alfredworkflow`, `vendor/`, or `*.db`.

**T3 — Implement MiniPlayerBridge.**
- Inputs: T2 skeleton.
- Outputs: a class with:
  - `static workflowDataDir(): string` — returns `$HOME/Library/.../com.vdesabou.spotify.mini.player`, throws `MiniPlayerNotInstalled` if absent.
  - `static libraryDbPath(): string` — `<dataDir>/library.db`, throws if absent.
  - `readMiniPlayerSetting(string $key): ?string` — AppleScript primary, PlistBuddy fallback, redacts in any error message.
  - `getAccessToken(): string` — full refresh flow via SDK's `Session::refreshAccessToken()`, returns the bearer token.
  - `openLibraryDb(): \SQLite3` — opens read-only.
- Acceptance: unit tests with a fixture sqlite + a mocked `exec()` for osascript pass.

**T4 — Implement Nuker.**
- Inputs: T3 bridge, SDK.
- Outputs: a class with:
  - `findOrCreateNukedPlaylist(SpotifyWebAPI $api, \SQLite3 $db): array` — returns `[uri, id, wasCreated:bool]`.
  - `getOtherOwnedPlaylists(\SQLite3 $db, string $nukedUri): array` — returns array of `[uri, id, name]`.
  - `addTrackToNuked(SpotifyWebAPI $api, string $nukedId, string $trackUri): void`.
  - `removeFromAll(SpotifyWebAPI $api, array $playlists, string $trackUri): array` — returns `[removedCount, errors]`.
  - `static extractId(string $uri): string`.
- Acceptance: unit tests with a mocked SDK assert the exact sequence of calls.

**T5 — Implement nuke.php entry + notifications.**
- Inputs: T3 + T4.
- Outputs: ~80-line orchestrator that wires bridge + nuker, gets current track, runs the flow, emits one macOS notification with the result. All exit codes 0; failures show via notification, not stack traces.
- Acceptance: invoked manually with `php src/nuke.php` (Spotify playing a real track) it nukes correctly. Invoked with Spotify stopped, it notifies "No track currently playing."

**T6 — Build script + info.plist + README install steps.**
- Inputs: T5.
- Outputs: `build.sh` produces `dist/spnuke.alfredworkflow`; `info.plist` declares the workflow with keyword `spnuke` and a Run Script action `/usr/bin/php src/nuke.php`; `README.md` documents prerequisites (mini player already installed and authenticated), install (`open dist/spnuke.alfredworkflow`), usage, troubleshooting.
- Acceptance: double-clicking the built file installs into Alfred and the `spnuke` keyword works end-to-end.

**T7 — Smoke test plan (manual).**
- Inputs: built workflow.
- Outputs: a manual checklist in `docs/smoke-test.md`: (a) no Nuked exists → creates it; (b) Nuked exists → uses it; (c) track in 3 playlists → removed from all 3; (d) track only in Liked Songs → Liked Songs untouched (verified by counting saved tracks before/after); (e) Spotify paused → "no track playing" notification; (f) refresh token revoked → graceful auth-failure notification.

## 11. Testing strategy

- **Unit**: PHPUnit. Mock the `SpotifyWebAPI` class (it's an interface-friendly class with public methods we can stub via reflection or a thin wrapper). Mock `exec()` for the AppleScript path. Use an in-memory SQLite (`:memory:`) seeded with a tiny fixture to exercise `MiniPlayerBridge::openLibraryDb()` and the queries.
- **Integration**: manual only, against the developer's own real Spotify account, per T7. No CI integration — public repo, no secrets.
- **Static checks** (optional, recommended): `php -l`, `phpstan level 6` on `src/`.

## 12. Risks & open questions

- **Refresh token rotation race.** If Spotify rotates the refresh token on our call, the mini player will get a 400 on its *next* call. Acceptable: the mini player handles 400 by re-prompting the user. Document in README ("if mini player asks to re-authorize after using spnuke, that's expected and rare"). Long-term: we could write the rotated value back via Alfred's `set configuration` AppleScript, but that violates our read-only contract — defer unless it becomes a real complaint.
- **Multiple "Nuked" playlists.** `ORDER BY nb_tracks DESC LIMIT 1` picks deterministically; documented.
- **Stale library.db.** Mini player refreshes hourly. New playlists may not appear immediately. Worst case: this invocation misses removing from the new playlist; next invocation catches it. Acceptable.
- **Auto-create race.** If `library.db` doesn't have "Nuked" but Spotify already does, we'd risk creating a duplicate. Mitigation: before creating, `GET /v1/me/playlists?limit=50` and search by name. Adds one API call — worth it for correctness.
- **Rate limiting.** Unlikely under 100 calls; if 429 hits, abort with partial-state notification. Backoff implementation deferred until observed.
- **Mini player major update breakage.** Coupling points listed explicitly in `docs/coupling.md`. If the next release renames a config key or moves `library.db`, the bridge fails fast with a clear error and the maintainer checks the doc.
- **Encryption of credentials.** Investigated: NOT encrypted on disk. If a future mini player release adds encryption, the bridge will return garbled refresh tokens; the SDK call will 400 with `invalid_grant`. Detect that and surface `"Mini player may have changed credential storage — check docs/coupling.md."` — better than crashing.
- **`__oauth_client_id` / `__oauth_client_secret` are the user's own Spotify app credentials**, which they entered during mini player setup. The companion never sees the developer's secrets — only the user's. Document this in README so users understand the privacy story.

## 13. Out of scope

- No GUI configuration panel.
- No undo / unnuke command.
- No batch nuking (multiple tracks at once).
- No scheduled / cron nukes.
- No analytics, telemetry, opt-in reporting.
- No support for collaborative playlists we don't own (already filtered by `ownedbyuser=1`).
- No support for Mopidy / SpotifyConnect output modes — we always use the Web API to determine the current track.
- No iOS / linux / windows — macOS + Alfred only.
- No standalone OAuth fallback in v1 (would add user friction; only re-evaluate if mini player ever encrypts credentials).
