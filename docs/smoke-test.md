# spnuke — Manual Smoke Test Checklist

Run these against your **real** Spotify account *only after you have built
the `.alfredworkflow` and installed it*. Each scenario lists pre-conditions,
the action, and the expected outcome.

> WARNING: these tests really *do* modify your playlists. Use a Spotify
> account whose playlists you can recover, or take a quick screenshot of
> the relevant playlists before you start.

---

## Pre-flight

- [ ] `composer install --no-dev --optimize-autoloader` succeeded.
- [ ] `php -l src/nuke.php` is clean.
- [ ] `./build.sh` produced `dist/spnuke.alfredworkflow`.
- [ ] Double-clicking the workflow installed it into Alfred.
- [ ] The mini player is installed and `sp` works in Alfred.
- [ ] Spotify desktop or mobile is running and connected.

## A. Cold start — no "Nuked" playlist exists

- [ ] Verify in Spotify that no playlist named "Nuked" exists.
- [ ] Pick a single track and play it in Spotify.
- [ ] In Alfred, type `nuke` and hit Enter.

Expected:

- macOS notification: `Created Nuked playlist, added to Nuked, removed from N
  playlists.` (where N is the number of your owned playlists, minus 0 since
  the track was probably only in Liked).
- Spotify now shows a private "Nuked" playlist with this one track.

## B. Warm path — "Nuked" already exists

- [ ] Confirm "Nuked" exists from scenario A.
- [ ] Play a different track.
- [ ] In Alfred, `nuke` + Enter.

Expected:

- Notification: `Added to Nuked, removed from N playlists.`
- Track is now in Nuked.

## C. Track in multiple playlists

- [ ] Pick a track, manually add it to (say) 3 of your owned playlists.
- [ ] Play that track.
- [ ] In Alfred, `nuke` + Enter.

Expected:

- Notification reports `removed from N playlists` where N ≥ 3 (other
  playlists are no-ops, but Spotify returns 200 for those — they don't
  count as "removed" in our counter because we only increment on a
  successful API call, not membership; with the idempotent DELETE we
  count *attempts* that returned 200 as removed). Adjust expectations:
  N should be the total number of owned playlists other than Nuked.

Then manually verify in Spotify:

- The track is no longer in the 3 playlists you added it to.
- The track *is* in Nuked.

## D. Liked Songs untouched

- [ ] In Spotify, like the currently-playing track if it isn't liked yet.
- [ ] Note the total count under "Liked Songs" (web/desktop sidebar).
- [ ] Run `nuke`.
- [ ] Re-check the Liked Songs total.

Expected:

- Liked Songs count is **unchanged**. (The companion never calls the
  `/me/tracks` endpoint, so this is the headline invariant.)

## E. Spotify paused / nothing playing

- [ ] Stop playback (close Spotify, or hit pause and wait a few minutes).
- [ ] Run `nuke`.

Expected:

- Notification: `No track currently playing.`
- No errors. No playlist changes.

## F. Spotify playing a podcast episode

- [ ] Play any podcast episode.
- [ ] Run `nuke`.

Expected:

- Notification: `No track currently playing.` (Podcast URIs start with
  `spotify:episode:`, which we deliberately skip — playlist endpoints
  expect tracks.)

## G. Refresh token revoked (auth failure)

- [ ] Open `https://www.spotify.com/account/apps/` and revoke access for
      your mini-player Spotify app, OR temporarily corrupt the
      `__oauth_refresh_token` value in Alfred's mini player config.
- [ ] Run `nuke`.

Expected:

- Notification: `Auth failed — re-authorize Spotify Mini Player.`
- No partial changes — we abort before any playlist mutation.

After verifying, restore access (re-run the mini player's authorization
flow via `sp` → reset).

## H. Mini player not installed

- [ ] Temporarily move
      `~/Library/Application Support/Alfred/Workflow Data/com.vdesabou.spotify.mini.player`
      to a backup location.
- [ ] Run `nuke`.

Expected:

- Notification: `Spotify Mini Player not installed.`
- Move the directory back when you're done.

## I. No PHP on PATH

- [ ] (Hard to test without uninstalling PHP.) Temporarily edit the
      workflow's Run Script node to use an invalid PHP path, e.g.
      `/nonexistent/php`.
- [ ] Run `nuke`.

Expected:

- Notification: `PHP not found on PATH. Install with: brew install php`
- Restore the script when done.

## J. Multiple "Nuked" playlists

- [ ] Manually create a second playlist also named "Nuked" with 0 tracks.
- [ ] Add tracks to the *original* Nuked so it has more tracks than the
      duplicate.
- [ ] Wait for the mini player to refresh its library (or trigger a
      refresh via its own UI), so `library.db` knows about both.
- [ ] Run `nuke`.

Expected:

- Track lands in the *busier* Nuked playlist (we tie-break by
  `nb_tracks DESC`).
- Delete the duplicate when done.

---

If any scenario fails, capture:

1. The notification text.
2. `php src/nuke.php` output if you can re-run it from the workflow dir
   (cd into Alfred's install path; `php` should print nothing on success).
3. The relevant section of `docs/coupling.md` if it looks like a mini
   player coupling break.
