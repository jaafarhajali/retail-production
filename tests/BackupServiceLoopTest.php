<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Settings;
use App\Models\Setting;
use App\Services\BackupService;

/** The backup service (2026-10-23): one decision a minute, the same as bin/backup.php --auto; a log line only when it did something. */
$clean = static function (): void {
    foreach (glob(BackupService::dir() . '/*.zip') ?: [] as $f) {
        @unlink($f);
    }
    (new Setting())->setMany(['backup_copy_dir' => '', 'backup_last_at' => '', 'backup_last_file' => '', 'backup_last_kind' => '', 'backup_last_copy' => 'off',
                              'backup_last_error' => '', 'backup_daily_time' => '21:30', 'backup_runner' => '', 'gdrive_last_error' => '']);
    Settings::flush();
};

return [
    '__before' => function () use ($clean): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
        $clean();
    },

    'before the set time the tick does nothing; after it, it makes the daily backup once' => function (): void {
        $svc = new BackupService();
        $day = (int) strtotime('2026-10-15 00:00:00');
        $line = $svc->serviceTick($day + 20 * 3600);
        assert_contains('nothing due (daily backup at 21:30)', $line);
        assert_same(0, count(glob(BackupService::dir() . '/*.zip') ?: []));
        $line = $svc->serviceTick($day + 21 * 3600 + 40 * 60);
        assert_contains('daily backup written: ', $line);
        assert_contains('USB copy: off', $line);
        assert_same(1, count(glob(BackupService::dir() . '/*-daily.zip') ?: []));
        // the zip is named with today's real date, so a tick on the real day finds it done (or not yet due): nothing is written twice
        assert_contains('nothing due', $svc->serviceTick(time()), 'nothing more today once a daily zip exists for the real date');
    },

    'the script runs one tick with --once and logs it; the Backups page says "service" when the installer set it' => function (): void {
        $log = STORAGE_PATH . '/logs/backup-service.log';
        @unlink($log);
        exec('"' . PHP_BINARY . '" "' . BASE_PATH . '/bin/backup-service.php" --once 2>&1', $out, $rc);
        assert_same(0, $rc, implode("\n", $out));
        assert_contains('service started (daily backup at 21:30', implode("\n", $out));
        assert_true(is_file($log), 'the log exists');
        assert_contains('daily backup at 21:30', (string) file_get_contents($log));

        // after 21:30 that one tick made the day's backup; the page below is about the wording for a service that has not run yet
        (new Setting())->setMany(['backup_runner' => 'service', 'backup_last_at' => '']);
        Settings::flush();
        assert_same('service', BackupService::runner());
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('backup')->body;
        assert_contains('backup service', $page);
        assert_not_contains('install-backup-tasks.php', $page, 'no task command when a service runs it');
        assert_contains('Is the backup service running?', $page);
    },
];
