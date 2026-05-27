<?php

declare(strict_types=1);

/**
 * spnuke — Alfred entry point.
 *
 * Wires MiniPlayerBridge + Nuker together, fetches the currently-playing
 * track from Spotify, runs the nuke flow, and emits a single macOS
 * notification with the result. Always exits 0; failures surface as
 * notifications, not stack traces.
 */

require __DIR__ . '/../vendor/autoload.php';

use Spnuke\AuthFailed;
use Spnuke\MiniPlayerBridge;
use Spnuke\MiniPlayerNotInstalled;
use Spnuke\NoTrackPlaying;
use Spnuke\Nuker;
use SpotifyWebAPI\SpotifyWebAPI;

const APP_TITLE = 'spnuke';

try {
    $bridge = new MiniPlayerBridge();

    // 1. Auth: read mini player creds, refresh access token.
    $accessToken = $bridge->getAccessToken();

    $api = new SpotifyWebAPI();
    $api->setAccessToken($accessToken);

    // 2. What's playing right now?
    [$trackUri, $trackName] = currentlyPlaying($api);

    // 3. Open mini player's library.db read-only.
    $db = $bridge->openLibraryDb();

    // 4. Find/create Nuked, then enumerate other owned playlists.
    $nuker = new Nuker();
    $nuked = $nuker->findOrCreateNukedPlaylist($api, $db, $bridge->readUserId());
    $others = $nuker->getOtherOwnedPlaylists($db, $nuked['uri']);

    // 5. Add to Nuked. We tolerate "already in Nuked" — Spotify just appends
    //    (or no-ops, depending on dedup). We still report success.
    $addedFresh = true;
    try {
        $nuker->addTrackToNuked($api, $nuked['id'], $trackUri);
    } catch (\Throwable $e) {
        // If add fails we still attempt the removals. Surface in subtitle.
        $addedFresh = false;
    }

    // 6. Loop-remove from all other owned playlists.
    $result = $nuker->removeFromAll($api, $others, $trackUri);

    // 7. Notify.
    $body = buildResultMessage($nuked['wasCreated'], $addedFresh, $result);
    notify($body, $trackName);
} catch (NoTrackPlaying $e) {
    notify('No track currently playing.');
} catch (MiniPlayerNotInstalled $e) {
    notify('Spotify Mini Player not installed.');
} catch (AuthFailed $e) {
    notify('Auth failed — re-authorize Spotify Mini Player.');
} catch (\Throwable $e) {
    notify('spnuke failed: ' . sanitize($e->getMessage()));
}

exit(0);

// ---------------------------------------------------------------------
// Helpers (kept inline; nuke.php is the orchestrator, not a library).
// ---------------------------------------------------------------------

/**
 * @return array{0:string,1:string} [trackUri, trackName]
 */
function currentlyPlaying(SpotifyWebAPI $api): array
{
    $playing = $api->getMyCurrentTrack();

    // SDK returns null/empty when there is no current track (204 from API).
    $type = is_object($playing) ? ($playing->currently_playing_type ?? null)
                                : (is_array($playing) ? ($playing['currently_playing_type'] ?? null) : null);

    if ($type === 'ad' || $playing === null || $playing === '' || $playing === []) {
        $playing = $api->getMyCurrentPlaybackInfo();
        $type = is_object($playing) ? ($playing->currently_playing_type ?? null)
                                    : (is_array($playing) ? ($playing['currently_playing_type'] ?? null) : null);
    }

    if ($playing === null || $playing === '' || $playing === [] || $type === 'ad') {
        throw new NoTrackPlaying();
    }

    $item = is_object($playing) ? ($playing->item ?? null) : ($playing['item'] ?? null);
    if ($item === null) {
        throw new NoTrackPlaying();
    }

    $uri  = is_object($item) ? (string) ($item->uri  ?? '') : (string) ($item['uri']  ?? '');
    $name = is_object($item) ? (string) ($item->name ?? '') : (string) ($item['name'] ?? '');

    if ($uri === '' || !str_starts_with($uri, 'spotify:track:')) {
        // Could be a podcast episode (spotify:episode:...). Treat as
        // "nothing nukeable playing" — the playlist endpoints expect tracks.
        throw new NoTrackPlaying();
    }

    return [$uri, $name === '' ? 'current track' : $name];
}

/**
 * @param array{removed:int,attempted:int,errors:list<array{name:string,error:string}>} $result
 */
function buildResultMessage(bool $created, bool $addedFresh, array $result): string
{
    $parts = [];

    if ($created) {
        $parts[] = 'Created Nuked playlist';
    }

    if ($addedFresh) {
        $parts[] = 'added to Nuked';
    } else {
        $parts[] = 'Nuked add failed';
    }

    if ($result['attempted'] === 0) {
        $parts[] = 'no other owned playlists';
    } elseif (count($result['errors']) === 0) {
        $parts[] = sprintf(
            'removed from %d %s',
            $result['removed'],
            $result['removed'] === 1 ? 'playlist' : 'playlists'
        );
    } else {
        $parts[] = sprintf(
            'removed from %d of %d (errors: %d)',
            $result['removed'],
            $result['attempted'],
            count($result['errors'])
        );
    }

    $msg = ucfirst(implode(', ', $parts)) . '.';
    return $msg;
}

/**
 * Emit a macOS notification via osascript. Sanitizes the strings so a track
 * name containing double-quotes or backslashes cannot break out of the
 * AppleScript string literal.
 */
function notify(string $body, string $subtitle = ''): void
{
    $escape = static fn (string $s): string =>
        str_replace(['\\', '"'], ['\\\\', '\\"'], $s);

    $script = sprintf(
        'display notification "%s" with title "%s"%s',
        $escape($body),
        $escape(APP_TITLE),
        $subtitle !== ''
            ? ' subtitle "' . $escape($subtitle) . '"'
            : ''
    );

    // Use exec (not shell_exec) — we don't need stdout, and we want a real
    // process exit so we don't accidentally inject through the shell.
    @exec('/usr/bin/osascript -e ' . escapeshellarg($script));
}

function sanitize(string $message): string
{
    $clean = preg_replace('/[A-Za-z0-9_\-]{40,}/', '<redacted>', $message);
    $clean = $clean ?? $message;
    return mb_substr($clean, 0, 240);
}
