<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * deploy/schedule-run.sh is the Hostinger cron entry point. It must give
 * proc_open back to every process the scheduler starts (backup:run runs
 * mysqldump), keep the rest of Hostinger's disable_functions list, and
 * touch nothing outside the cron process tree. Bash, so Linux CI only.
 */

beforeEach(function (): void {
    $this->tmp = sys_get_temp_dir().'/schedule-run-'.bin2hex(random_bytes(6));
    $this->iniDir = $this->tmp.'/php-cli';
    File::ensureDirectoryExists($this->tmp);

    // Stands in for Hostinger's PHP: answers the script's disable_functions
    // query as a server that disables exec, proc_open and shell_exec, and
    // reports how it was started instead of running the scheduler.
    $this->stub = $this->tmp.'/php';
    File::put($this->stub, <<<'BASH'
        #!/usr/bin/env bash
        if [ "$1" = "-r" ]; then
          exec "$REAL_PHP" -n -d disable_functions=exec,proc_open,shell_exec "$@"
        fi
        printf 'ARGS=%s\nSCAN=%s\n' "$*" "${PHP_INI_SCAN_DIR-}"
        BASH);
    chmod($this->stub, 0755);
});

afterEach(function (): void {
    File::deleteDirectory($this->tmp);
});

function runScheduleScript(string $stub, string $iniDir, string|false $scanDir = false): Process
{
    $process = new Process(['bash', base_path('deploy/schedule-run.sh')], null, [
        'PHP_BIN' => $stub,
        'REAL_PHP' => PHP_BINARY,
        'PHP_CLI_INI_DIR' => $iniDir,
        'PHP_INI_SCAN_DIR' => $scanDir,
    ]);
    $process->mustRun();

    return $process;
}

it('starts the scheduler with an ini that drops only proc_open from the list', function (): void {
    $output = runScheduleScript($this->stub, $this->iniDir)->getOutput();

    expect($output)->toContain('ARGS=artisan schedule:run'."\n")
        ->and($output)->toContain('SCAN=:'.$this->iniDir."\n")
        ->and(File::get($this->iniDir.'/zz-proc-open.ini'))->toContain('disable_functions = "exec,shell_exec"')
        ->and(fileperms($this->iniDir) & 0777)->toBe(0700)
        ->and(File::glob($this->iniDir.'/.zz-proc-open.*'))->toBe([]);
})->skip(PHP_OS_FAMILY === 'Windows', 'bash script; runs on Linux CI');

it('keeps a scan directory that is already set', function (): void {
    $output = runScheduleScript($this->stub, $this->iniDir, '/etc/php/extra')->getOutput();

    expect($output)->toContain('SCAN=/etc/php/extra:'.$this->iniDir."\n");
})->skip(PHP_OS_FAMILY === 'Windows', 'bash script; runs on Linux CI');

it('rewrites the ini on every run, so a changed server list is picked up', function (): void {
    File::ensureDirectoryExists($this->iniDir);
    File::put($this->iniDir.'/zz-proc-open.ini', 'disable_functions = "stale"');

    runScheduleScript($this->stub, $this->iniDir);

    expect(File::get($this->iniDir.'/zz-proc-open.ini'))->not->toContain('stale');
})->skip(PHP_OS_FAMILY === 'Windows', 'bash script; runs on Linux CI');

// The reason for the ini file: a `php -d` flag reached only the scheduler
// process, never the `php artisan backup:run` it started.
it('reaches every PHP process started with the environment', function (): void {
    runScheduleScript($this->stub, $this->iniDir);

    $child = new Process([PHP_BINARY, '-r', 'echo ini_get("disable_functions");'], null, [
        'PHP_INI_SCAN_DIR' => ':'.$this->iniDir,
    ]);
    $child->mustRun();

    expect($child->getOutput())->toBe('exec,shell_exec');
})->skip(PHP_OS_FAMILY === 'Windows', 'bash script; runs on Linux CI');
