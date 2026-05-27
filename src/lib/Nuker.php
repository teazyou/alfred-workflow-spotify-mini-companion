<?php

declare(strict_types=1);

namespace Spnuke;

use SpotifyWebAPI\SpotifyWebAPI;
use SQLite3;

/**
 * Core "nuke" logic: resolve/create the Nuked playlist, add the current track,
 * and remove it from every other owned playlist.
 *
 * Pure functions where possible; takes the SDK and the DB as parameters so
 * tests can pass mocks/fixtures.
 */
final class Nuker
{
    public const NUKED_PLAYLIST_NAME = 'Nuked';

    /**
     * Find an existing "Nuked" playlist (owned by the user) or create one.
     *
     * Strategy:
     *   1. Look in the mini player's local library.db (fastest, free).
     *   2. If not there, hit Spotify's /v1/me/playlists?limit=50 as a
     *      race-protection check before creating. library.db could be stale
     *      (it refreshes hourly) and we don't want to mint duplicates.
     *   3. Otherwise POST /v1/users/{me}/playlists.
     *
     * @return array{uri:string,id:string,wasCreated:bool}
     */
    public function findOrCreateNukedPlaylist(
        SpotifyWebAPI $api,
        SQLite3 $db,
        ?string $cachedUserId = null
    ): array {
        $row = $this->queryNukedFromDb($db);
        if ($row !== null) {
            return [
                'uri'        => $row['uri'],
                'id'         => self::extractId($row['uri']),
                'wasCreated' => false,
            ];
        }

        // Race-protection: ask Spotify directly. library.db may be hours stale.
        $remote = $this->findNukedOnSpotify($api);
        if ($remote !== null) {
            return [
                'uri'        => $remote['uri'],
                'id'         => self::extractId($remote['uri']),
                'wasCreated' => false,
            ];
        }

        // Truly absent — create it.
        $userId = $cachedUserId;
        if ($userId === null || $userId === '') {
            $me = $api->me();
            $userId = is_object($me) ? (string) $me->id : (string) $me['id'];
        }

        $created = $api->createPlaylist($userId, [
            'name'        => self::NUKED_PLAYLIST_NAME,
            'public'      => false,
            'description' => 'Tracks nuked by spnuke companion.',
        ]);

        $uri = is_object($created) ? (string) $created->uri : (string) $created['uri'];

        return [
            'uri'        => $uri,
            'id'         => self::extractId($uri),
            'wasCreated' => true,
        ];
    }

    /**
     * All user-owned playlists except the Nuked one.
     *
     * @return list<array{uri:string,id:string,name:string}>
     */
    public function getOtherOwnedPlaylists(SQLite3 $db, string $nukedUri): array
    {
        $stmt = $db->prepare(
            'SELECT uri, name FROM playlists '
            . 'WHERE ownedbyuser = 1 AND uri <> :nuked '
            . 'ORDER BY name COLLATE NOCASE'
        );
        $stmt->bindValue(':nuked', $nukedUri, SQLITE3_TEXT);

        $result = $stmt->execute();
        $out = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $uri = (string) $row['uri'];
            $out[] = [
                'uri'  => $uri,
                'id'   => self::extractId($uri),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }
        $result->finalize();
        $stmt->close();

        return $out;
    }

    /**
     * Add a track to the Nuked playlist. The Spotify endpoint returns 201 with
     * a snapshot_id; we don't dedupe (the playlist may contain dupes; user can
     * clean up manually if they care).
     */
    public function addTrackToNuked(
        SpotifyWebAPI $api,
        string $nukedId,
        string $trackUri
    ): void {
        $api->addPlaylistTracks($nukedId, [$trackUri]);
    }

    /**
     * Loop-delete the track from every playlist in $playlists.
     *
     * Spotify's DELETE /v1/playlists/{id}/tracks is idempotent: it returns
     * 200 even if the track is not in the playlist. So we don't pre-check
     * membership; that would double our API budget for no gain.
     *
     * @param list<array{uri:string,id:string,name:string}> $playlists
     * @return array{removed:int,attempted:int,errors:list<array{name:string,error:string}>}
     */
    public function removeFromAll(
        SpotifyWebAPI $api,
        array $playlists,
        string $trackUri
    ): array {
        $removed = 0;
        $errors = [];

        foreach ($playlists as $pl) {
            try {
                $api->deletePlaylistTracks(
                    $pl['id'],
                    ['tracks' => [['uri' => $trackUri]]]
                );
                $removed++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'name'  => $pl['name'],
                    'error' => $this->sanitizeMessage($e->getMessage()),
                ];
                // Don't break — keep removing from the rest. Spotify rate
                // limits manifest as one slow call, not a global failure.
            }
        }

        return [
            'removed'   => $removed,
            'attempted' => count($playlists),
            'errors'    => $errors,
        ];
    }

    /**
     * Extract the bare id from a Spotify URI.
     *
     *   spotify:playlist:1ABC   -> 1ABC
     *   spotify:track:5XYZ      -> 5XYZ
     *   1ABC                    -> 1ABC (passthrough)
     */
    public static function extractId(string $uri): string
    {
        $pos = strrpos($uri, ':');
        if ($pos === false) {
            return $uri;
        }
        return substr($uri, $pos + 1);
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * @return array{uri:string,name:string}|null
     */
    private function queryNukedFromDb(SQLite3 $db): ?array
    {
        $stmt = $db->prepare(
            'SELECT uri, name FROM playlists '
            . "WHERE lower(name) = :name AND ownedbyuser = 1 "
            . 'ORDER BY nb_tracks DESC LIMIT 1'
        );
        $stmt->bindValue(':name', strtolower(self::NUKED_PLAYLIST_NAME), SQLITE3_TEXT);

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        $stmt->close();

        if ($row === false) {
            return null;
        }

        return [
            'uri'  => (string) $row['uri'],
            'name' => (string) ($row['name'] ?? self::NUKED_PLAYLIST_NAME),
        ];
    }

    /**
     * @return array{uri:string}|null
     */
    private function findNukedOnSpotify(SpotifyWebAPI $api): ?array
    {
        $resp = $api->getMyPlaylists(['limit' => 50]);

        $items = is_object($resp) ? ($resp->items ?? []) : ($resp['items'] ?? []);
        $meId = null;

        foreach ($items as $item) {
            $name  = is_object($item) ? ($item->name ?? '') : ($item['name'] ?? '');
            $uri   = is_object($item) ? ($item->uri  ?? '') : ($item['uri']  ?? '');
            $owner = is_object($item) ? ($item->owner ?? null) : ($item['owner'] ?? null);

            if (strcasecmp((string) $name, self::NUKED_PLAYLIST_NAME) !== 0) {
                continue;
            }

            $ownerId = is_object($owner) ? ($owner->id ?? '') : ($owner['id'] ?? '');

            if ($meId === null) {
                $me = $api->me();
                $meId = is_object($me) ? (string) $me->id : (string) $me['id'];
            }

            if ((string) $ownerId === $meId) {
                return ['uri' => (string) $uri];
            }
        }

        return null;
    }

    private function sanitizeMessage(string $message): string
    {
        // Strip anything that looks like a bearer token or a long opaque blob.
        $clean = preg_replace('/[A-Za-z0-9_\-]{40,}/', '<redacted>', $message);
        return mb_substr($clean ?? $message, 0, 200);
    }
}
