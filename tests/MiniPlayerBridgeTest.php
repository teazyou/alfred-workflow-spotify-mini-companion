<?php

declare(strict_types=1);

namespace Spnuke\Tests;

use PHPUnit\Framework\TestCase;
use Spnuke\MiniPlayerBridge;
use Spnuke\MiniPlayerNotInstalled;

final class MiniPlayerBridgeTest extends TestCase
{
    public function testLibraryDbPathThrowsWhenAbsent(): void
    {
        // Force HOME to a temp dir with no mini player data.
        $tmp = sys_get_temp_dir() . '/spnuke-test-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        $originalHome = getenv('HOME');
        putenv('HOME=' . $tmp);

        try {
            $this->expectException(MiniPlayerNotInstalled::class);
            MiniPlayerBridge::libraryDbPath();
        } finally {
            if ($originalHome !== false) {
                putenv('HOME=' . $originalHome);
            }
            @rmdir($tmp);
        }
    }
}
