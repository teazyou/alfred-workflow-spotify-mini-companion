# spnuke — Alfred Workflow Spotify Mini Player Companion

A small Alfred workflow that adds one keyword — **`nuke`** — which takes
the currently-playing Spotify track, ensures a playlist named **Nuked**
exists, adds the track to it, and removes the track from every *other*
playlist you own. Your Liked Songs library is untouched.

This is a **companion** to
[vdesabou/alfred-spotify-mini-player](https://github.com/vdesabou/alfred-spotify-mini-player):
it reuses the mini player's cached library and already-granted OAuth
credentials, so you do not have to set up Spotify again.

---

## Prerequisites

1. **macOS** with [Alfred](https://www.alfredapp.com/) and the Powerpack.
2. **Spotify Mini Player** installed in Alfred and **fully authenticated**
   (you can play a track via `sp` and it works). The companion reads its
   credentials and library cache; it does not write to them.
3. **PHP 8.1 or later**. macOS no longer ships PHP. Install via Homebrew
   (`brew install php`) or MacPorts. The workflow probes the common install
   locations (`/opt/homebrew/bin/php`, `/usr/local/bin/php`,
   `/opt/local/bin/php`, `/usr/bin/php`, plus whatever `command -v php`
   returns) so it works without touching your shell `PATH`.

## Install

1. Download the latest `spnuke.alfredworkflow` from the
   [Releases](../../releases) page (or build it yourself, see below).
2. Double-click it. Alfred installs the workflow.
3. Trigger it: open Alfred, type `nuke`, hit Enter while Spotify is
   playing a track.

You'll see a macOS notification with the result — for example
`Added to Nuked, removed from 7 playlists.`

## How it works (briefly)

1. Reads your mini-player OAuth credentials via Alfred's per-workflow
   configuration API (`__oauth_client_id`, `__oauth_client_secret`,
   `__oauth_refresh_token`).
2. Refreshes a fresh access token from `accounts.spotify.com` — it does not
   reuse the mini player's stored access token.
3. Calls `GET /v1/me/player/currently-playing` for the current track.
4. Opens the mini player's `library.db` **read-only** to find your Nuked
   playlist and every other owned playlist.
5. `POST /v1/playlists/{nuked}/tracks` to add, then
   `DELETE /v1/playlists/{id}/tracks` once per other owned playlist.
6. Notifies you and exits.

The companion **never writes** to the mini player's files. See
[`docs/coupling.md`](docs/coupling.md) for the full contract.

## Build from source

```sh
git clone https://github.com/teazyou/alfred-workflow-spotify-mini-companion.git
cd alfred-workflow-spotify-mini-companion
./build.sh
open dist/spnuke.alfredworkflow
```

`build.sh` runs `composer install --no-dev --optimize-autoloader` and zips
the result as `dist/spnuke.alfredworkflow`.

## Troubleshooting

**"PHP not found on PATH."**
Run `brew install php` (or any other PHP 8.1+ install) and try again. If
your PHP lives somewhere other than `/opt/homebrew/bin`, `/usr/local/bin`,
`/opt/local/bin`, or `/usr/bin`, double-click the workflow in Alfred,
double-click the Run Script node, and edit the script to point at your
PHP binary.

**"Spotify Mini Player not installed."**
The companion can't find `library.db`. Make sure the mini player is
installed in Alfred and you've used it at least once so it has built its
cache.

**"Auth failed — re-authorize Spotify Mini Player."**
Your refresh token is no longer valid. Open the mini player (type `sp` in
Alfred), go through its `reset` / re-authorize flow, then try `nuke`
again.

**"No track currently playing."**
Spotify isn't playing anything, or the active session is on a device
Spotify doesn't report (rare). Start playing a track and try again.

**Mini player asks me to re-authorize after I run spnuke.**
This is rare but expected. Spotify occasionally rotates refresh tokens; if
the companion happens to be the call that triggers rotation, the mini
player's stored token becomes stale and it will re-prompt. Re-authorizing
takes ~10 seconds.

**Mini player just got updated and now spnuke fails.**
The mini player may have moved a file or renamed a config key. Check the
"Upgrade checklist for the maintainer" section of
[`docs/coupling.md`](docs/coupling.md) and open an issue if a coupling
point has shifted.

## Privacy

- The companion only talks to your own Spotify app — the OAuth client
  ID/secret are the ones **you** entered into the mini player during its
  setup, not the developer's. No one else's credentials are ever in scope.
- Nothing is logged, telemetered, or transmitted anywhere except your own
  Spotify account.
- The compiled `.alfredworkflow` bundles `vendor/` (the
  `jwilsson/spotify-web-api-php` SDK) but contains zero credentials.

## License

[MIT](LICENSE).

Not affiliated with Spotify AB or with the mini player project.
