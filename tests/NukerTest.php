<?php

declare(strict_types=1);

namespace Spnuke\Tests;

use PHPUnit\Framework\TestCase;
use Spnuke\Nuker;
use SQLite3;

final class NukerTest extends TestCase
{
    public function testExtractIdFromFullUri(): void
    {
        self::assertSame('1ABC', Nuker::extractId('spotify:playlist:1ABC'));
        self::assertSame('5XYZ', Nuker::extractId('spotify:track:5XYZ'));
    }

    public function testExtractIdPassthroughForBareId(): void
    {
        self::assertSame('plain', Nuker::extractId('plain'));
    }

    public function testGetOtherOwnedPlaylistsExcludesNukedAndUnowned(): void
    {
        $db = $this->seedDb([
            ['spotify:playlist:A', 'Nuked',    1],
            ['spotify:playlist:B', 'Rock',     1],
            ['spotify:playlist:C', 'Jazz',     1],
            ['spotify:playlist:D', 'Friend\'s mix', 0],
        ]);

        $rows = (new Nuker())->getOtherOwnedPlaylists($db, 'spotify:playlist:A');

        $names = array_column($rows, 'name');
        self::assertSame(['Jazz', 'Rock'], $names);

        $ids = array_column($rows, 'id');
        self::assertContains('B', $ids);
        self::assertContains('C', $ids);
        self::assertNotContains('A', $ids);
        self::assertNotContains('D', $ids);
    }

    public function testGetOtherOwnedPlaylistsHandlesEmptyDb(): void
    {
        $db = $this->seedDb([]);
        $rows = (new Nuker())->getOtherOwnedPlaylists($db, 'spotify:playlist:NONE');
        self::assertSame([], $rows);
    }

    /**
     * @param list<array{0:string,1:string,2:int}> $playlists [uri, name, ownedbyuser]
     */
    private function seedDb(array $playlists): SQLite3
    {
        $db = new SQLite3(':memory:');
        $db->exec(
            'CREATE TABLE playlists (
                uri TEXT PRIMARY KEY NOT NULL,
                name TEXT,
                nb_tracks INT,
                author TEXT,
                username TEXT,
                playlist_artwork_path TEXT,
                ownedbyuser BOOLEAN,
                nb_playable_tracks INT,
                duration_playlist TEXT,
                nb_times_played INT,
                collaborative BOOLEAN,
                public BOOLEAN,
                name_deburr TEXT
            )'
        );

        $stmt = $db->prepare(
            'INSERT INTO playlists (uri, name, ownedbyuser, nb_tracks) VALUES (:uri, :name, :owned, :nb)'
        );
        foreach ($playlists as $i => [$uri, $name, $owned]) {
            $stmt->bindValue(':uri',   $uri,         SQLITE3_TEXT);
            $stmt->bindValue(':name',  $name,        SQLITE3_TEXT);
            $stmt->bindValue(':owned', $owned,       SQLITE3_INTEGER);
            $stmt->bindValue(':nb',    ($i + 1) * 3, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->reset();
        }
        $stmt->close();

        return $db;
    }
}
