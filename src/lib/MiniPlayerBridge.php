<?php

declare(strict_types=1);

namespace Spnuke;

use RuntimeException;
use SpotifyWebAPI\Session;
use SQLite3;

/**
 * Read-only bridge to the vdesabou/alfred-spotify-mini-player workflow.
 *
 * - Discovers the mini player's workflow data directory.
 * - Reads OAuth credentials via Alfred's per-workflow configuration API
 *   (primary) or PlistBuddy on the workflow's prefs.plist (fallback).
 * - Refreshes a fresh access token from accounts.spotify.com.
 * - Opens the cached library.db read-only.
 *
 * **Read-only contract.** No method in this class writes to any mini player
 * file. The mini player keeps its own state.
 *
 * See docs/coupling.md for the full set of assumptions.
 */
final class MiniPlayerBridge
{
    public const MINI_PLAYER_BUNDLE_ID = 'com.vdesabou.spotify.mini.player';
    public const ALFRED_BUNDLE_ID      = 'com.runningwithcrayons.Alfred';

    private const KEY_CLIENT_ID     = '__oauth_client_id';
    private const KEY_CLIENT_SECRET = '__oauth_client_secret';
    private const KEY_REFRESH_TOKEN = '__oauth_refresh_token';
    private const KEY_USER_ID       = '__userid';

    /**
     * Optional override: when set, used as the mini player install directory.
     * Useful in tests.
     */
    private ?string $installDirOverride = null;

    public function __construct(?string $installDirOverride = null)
    {
        $this->installDirOverride = $installDirOverride;
    }

    /**
     * Absolute path to the mini player's data directory.
     *
     * @throws MiniPlayerNotInstalled when the directory does not exist.
     */
    public static function workflowDataDir(): string
    {
        $home = self::homeDir();
        $path = $home
            . '/Library/Application Support/Alfred/Workflow Data/'
            . self::MINI_PLAYER_BUNDLE_ID;

        if (!is_dir($path)) {
            throw new MiniPlayerNotInstalled(
                'Spotify Mini Player workflow data directory not found.'
            );
        }

        return $path;
    }

    /**
     * Absolute path to the mini player's library.db.
     *
     * @throws MiniPlayerNotInstalled when the file does not exist.
     */
    public static function libraryDbPath(): string
    {
        $path = self::workflowDataDir() . '/library.db';
        if (!is_file($path)) {
            throw new MiniPlayerNotInstalled(
                'Spotify Mini Player library cache not found. Run the mini player at least once.'
            );
        }

        return $path;
    }

    /**
     * Open library.db read-only.
     *
     * @throws MiniPlayerNotInstalled
     */
    public function openLibraryDb(): SQLite3
    {
        $db = new SQLite3(self::libraryDbPath(), SQLITE3_OPEN_READONLY);
        $db->busyTimeout(2000);
        return $db;
    }

    /**
     * Read a single configuration key from the mini player.
     *
     * Tries Alfred's AppleScript configuration API first, then falls back to
     * PlistBuddy. Returns null if the key is absent from both sources.
     *
     * Caller MUST NOT log the returned value (it's a credential).
     */
    public function readMiniPlayerSetting(string $key): ?string
    {
        $value = $this->readViaAppleScript($key);
        if ($value !== null && $value !== '') {
            return $value;
        }

        $value = $this->readViaPlistBuddy($key);
        if ($value !== null && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * Exchange the refresh token for a fresh access token.
     *
     * @throws AuthFailed when any credential is missing or Spotify rejects
     *                    the refresh token.
     */
    public function getAccessToken(): string
    {
        $clientId     = $this->readMiniPlayerSetting(self::KEY_CLIENT_ID);
        $clientSecret = $this->readMiniPlayerSetting(self::KEY_CLIENT_SECRET);
        $refreshToken = $this->readMiniPlayerSetting(self::KEY_REFRESH_TOKEN);

        if ($clientId === null || $clientSecret === null || $refreshToken === null) {
            throw new AuthFailed(
                'Could not read Spotify Mini Player credentials. Re-authorize the mini player.'
            );
        }

        $session = new Session($clientId, $clientSecret);
        $session->setRefreshToken($refreshToken);

        try {
            $session->refreshAccessToken();
        } catch (\Throwable $e) {
            // Do NOT include the original message — it may echo back token
            // fragments depending on the SDK version.
            throw new AuthFailed(
                'Spotify rejected the stored refresh token. Re-authorize the mini player.'
            );
        }

        $token = $session->getAccessToken();
        if ($token === '') {
            throw new AuthFailed('Spotify returned an empty access token.');
        }

        return $token;
    }

    /**
     * Optional helper: cached Spotify user id from the mini player prefs.
     * Returns null if not set; in that case the caller should call /v1/me.
     */
    public function readUserId(): ?string
    {
        return $this->readMiniPlayerSetting(self::KEY_USER_ID);
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * Primary read path: Alfred's AppleScript configuration API.
     */
    private function readViaAppleScript(string $key): ?string
    {
        // Reject characters that could break out of the AppleScript string
        // literal. Mini player keys are all `__lower_snake_case`.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $key)) {
            return null;
        }

        $script = sprintf(
            'tell application id "%s" to return value of configuration "%s" in workflow "%s"',
            self::ALFRED_BUNDLE_ID,
            $key,
            self::MINI_PLAYER_BUNDLE_ID
        );

        $cmd = '/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>/dev/null';

        $output = @shell_exec($cmd);
        if ($output === null || $output === false) {
            return null;
        }

        $value = trim((string) $output);
        if ($value === '' || $value === 'missing value') {
            return null;
        }

        return $value;
    }

    /**
     * Fallback read path: PlistBuddy on the workflow's prefs.plist.
     *
     * Used when Alfred is not running, or AppleScript is unavailable.
     * Locates the mini player's install dir by globbing for an info.plist
     * whose bundleid matches.
     */
    private function readViaPlistBuddy(string $key): ?string
    {
        $prefsPath = $this->locatePrefsPlist();
        if ($prefsPath === null) {
            return null;
        }

        // PlistBuddy uses single-colon paths.
        $cmd = '/usr/libexec/PlistBuddy -c '
            . escapeshellarg('Print :' . $key)
            . ' '
            . escapeshellarg($prefsPath)
            . ' 2>/dev/null';

        $output = @shell_exec($cmd);
        if ($output === null || $output === false) {
            return null;
        }

        $value = trim((string) $output);
        return $value === '' ? null : $value;
    }

    /**
     * Find the mini player's prefs.plist by scanning Alfred's workflows
     * directory for an info.plist with the matching bundleid.
     */
    private function locatePrefsPlist(): ?string
    {
        if ($this->installDirOverride !== null) {
            $candidate = $this->installDirOverride . '/prefs.plist';
            return is_file($candidate) ? $candidate : null;
        }

        $home = self::homeDir();
        $workflowsRoot = $home
            . '/Library/Application Support/Alfred/Alfred.alfredpreferences/workflows';
        if (!is_dir($workflowsRoot)) {
            return null;
        }

        $iter = @scandir($workflowsRoot);
        if ($iter === false) {
            return null;
        }

        foreach ($iter as $entry) {
            if (!str_starts_with($entry, 'user.workflow.')) {
                continue;
            }
            $infoPlist = $workflowsRoot . '/' . $entry . '/info.plist';
            if (!is_file($infoPlist)) {
                continue;
            }

            $bundleId = $this->readPlistKey($infoPlist, 'bundleid');
            if ($bundleId === self::MINI_PLAYER_BUNDLE_ID) {
                $prefs = $workflowsRoot . '/' . $entry . '/prefs.plist';
                return is_file($prefs) ? $prefs : null;
            }
        }

        return null;
    }

    private function readPlistKey(string $plistPath, string $key): ?string
    {
        $cmd = '/usr/libexec/PlistBuddy -c '
            . escapeshellarg('Print :' . $key)
            . ' '
            . escapeshellarg($plistPath)
            . ' 2>/dev/null';
        $output = @shell_exec($cmd);
        if ($output === null || $output === false) {
            return null;
        }
        $value = trim((string) $output);
        return $value === '' ? null : $value;
    }

    private static function homeDir(): string
    {
        $home = getenv('HOME');
        if ($home !== false && $home !== '') {
            return $home;
        }

        $info = posix_getpwuid(posix_geteuid());
        if (is_array($info) && isset($info['dir'])) {
            return (string) $info['dir'];
        }

        throw new RuntimeException('Cannot determine user home directory.');
    }
}
