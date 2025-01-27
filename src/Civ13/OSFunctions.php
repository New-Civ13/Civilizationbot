<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Civ13\Exceptions\MissingSystemPermissionException;
use React\ChildProcess\Process;
use React\Promise\PromiseInterface;
use Psr\Log\LoggerInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Provides various operating system related functions such as spawning child processes,
 * executing commands in the background, restarting the application, and checking port availability.
 */
class OSFunctions
{
    const array DEFAULT_PIPES = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    private static function setNonblock(array &$pipes)
    {
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
    }
    
    private static function readPipes(array &$pipes)
    {
        try {
            if (is_resource($pipes[0]) && $stdin = fread($pipes[0], 4096)) error_log("StdIn: $stdin");
            if (is_resource($pipes[1]) && $stdout = fread($pipes[1], 4096)) error_log("StdOut: $stdout");
            if (is_resource($pipes[2]) && $stderr = fread($pipes[2], 4096)) error_log("StdErr: $stderr");
        } catch (\Exception $e) {
            error_log('Error reading pipes: ' . $e->getMessage());
        }
    }

    /**
     * Spawns a child process to execute the given command.
     *
     * This function attempts to create a new process to run the specified command.
     * On Windows systems, it returns null as the POSIX compatibility layer is insufficient.
     * On other systems, it uses `sudo` and `nohup` to run the command and sets up event listeners
     * to log the process's stdout data, end, error, and close events, as well as the exit event.
     *
     * @param string $cmd The command to be executed in the child process.
     * @return PromiseInterface<Process> The created Process object, or null if running on Windows.
     */
    public static function spawnChildProcess(string $cmd): PromiseInterface
    {
        if (PHP_OS_FAMILY == 'Windows') return reject(new \Exception('Windows does not support POSIX compatibility layer'));
        $process = new Process("sudo nohup $cmd");
        $process->stdout->on('end',   static fn()              => error_log('ended'                      . PHP_EOL));
        $process->stdout->on('close', static fn()              => error_log('closed'                     . PHP_EOL));
        $process->stdout->on('data',  static fn($chunk)        => error_log($chunk                       . PHP_EOL));
        $process->stdout->on('error', static fn(\Exception $e) => error_log('error: ' . $e->getMessage() . PHP_EOL));
        $process->on('exit', fn($exitCode, $termSignal)        => error_log(($termSignal === null) ? "Process exited with code $exitCode" . PHP_EOL : "Process terminated with signal $termSignal" . PHP_EOL));
        return resolve($process);
    }

    /**
     * Executes a command in the background.
     *
     * This function executes a given command in the background, either on Windows or Unix-based systems.
     * On Windows, it uses `popen` with the `start` command.
     * On Unix-based systems, it uses `proc_open` with `sudo nohup`.
     *
     * @param string $cmd The command to execute.
     * @return PromiseInterface<resource> A promise that resolves with the process resource on success, or rejects with an exception on failure.
     * @throws MissingSystemPermissionException If the necessary system permissions are not available or the command execution fails.
     */
    public static function execInBackground(string $cmd): PromiseInterface
    {
        if (PHP_OS_FAMILY == 'Windows') {
            if (($p = popen("start {$cmd}", "r")) === false) return reject(new MissingSystemPermissionException('popen() failed'));
            return resolve($p);
        }
        if (! $proc = proc_open($cmd, self::DEFAULT_PIPES, $pipes)) return reject(new MissingSystemPermissionException('proc_open() failed')); // old method was "sudo nohup $cmd > /dev/null &"
        self::setNonblock($pipes);
        self::readPipes($pipes);
        if (! $proc_details = proc_get_status($proc)) return reject(new MissingSystemPermissionException('proc_get_status() failed'));
        if (! isset($proc_details['pid']) || ! $pid = $proc_details['pid']) return reject(new MissingSystemPermissionException('proc_get_status() did not return a PID'));
        error_log("Executing external shell command `$cmd` with PID $pid");
        return resolve($proc);
    }

    /**
     * Restarts the application by executing an external shell command.
     *
     * On Windows, it runs a batch file (`run.bat`) located in the current working directory.
     * On other operating systems, it runs a PHP script (`bot.php`) in the background using `nohup`.
     *
     * @return PromiseInterface<resource> A promise that resolves with the process resource on success, or rejects with a MissingSystemPermissionException on failure.
     *
     * @throws MissingSystemPermissionException If `popen()` or `proc_open()` fails, or if the process ID cannot be retrieved.
     */
    public static function restart(string $file = 'bot.php'): PromiseInterface
    {
        if (PHP_OS_FAMILY == 'Windows') {
            if (($p = popen('cmd /c "' . getcwd() . '\run.bat"', "r")) === false) return reject(new MissingSystemPermissionException('popen() failed'));
            return resolve($p);
        }
        if (($proc = proc_open($output = "sudo nohup php \"$file\" > botlog.txt &", self::DEFAULT_PIPES, $pipes)) === false) return reject(new MissingSystemPermissionException('proc_open() failed'));
        self::setNonblock($pipes);
        self::readPipes($pipes);
        if (! $pid = proc_get_status($proc)['pid']) return reject(new MissingSystemPermissionException('proc_get_status() failed'));
        error_log("Executing external shell command `$output` with PID $pid");
        return resolve($proc);
    }

    /**
     * Checks if a given port is available on the local machine.
     *
     * @param int $port The port number to check. Default is 1714.
     * @return PromiseInterface<resource|\Socket> A promise that resolves if the port is available, or rejects with an exception if it is not.
     * @throws \Exception If there is an error creating or binding the socket.
     */
    public static function portIsAvailable(int $port = 1714): PromiseInterface
    {
        if (($s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) return reject(new \Exception(socket_last_error()));
        if (socket_bind($s, '127.0.0.1', $port) === false) return reject(new \Exception(socket_last_error($s)));
        socket_close($s);
        return resolve($s);
    }

    /**
     * Saves an associative array to a file in JSON format.
     *
     * @param string $filename The name of the file to save to.
     * @param array $assoc_array The associative array to be saved.
     * @param LoggerInterface|null $logger An optional logger object to log messages to.
     * @return bool Returns true if the data was successfully saved, false otherwise.
     */
    public static function VarSave(string $filecache_path, string $filename, array $assoc_array = [], ?LoggerInterface $logger = null): bool
    {
        if ($filename === '') {
            $logger
                ? $logger->warning('Unable to load data from file: Filename is empty')
                : error_log('Unable to save data to file: Filename is empty');
            return false;
        }
        if (file_put_contents($filePath = $filecache_path . $filename, json_encode($assoc_array)) === false) {
            $logger
                ? $logger->warning("Unable to save data to file: $filePath")
                : error_log("Unable to save data to file: $filePath");
            return false;
        }
        return true;
    }
    /**
     * Loads an associative array from a file that was saved in JSON format.
     *
     * @param string $filecache_path The path to the directory where the file is located.
     * @param string $filename The name of the file to load from.
     * @param LoggerInterface|null $logger An optional logger object to log messages to.
     * @return array|null Returns the associative array that was loaded, or null if the file does not exist or could not be loaded.
     */
    public static function VarLoad(string $filecache_path, string $filename = '', ?LoggerInterface $logger = null): ?array
    {
        if (! is_dir($filecache_path)) {
            $logger
                ? $logger->error("Directory does not exist: $filecache_path")
                : error_log("Directory does not exist: $filecache_path");
            throw new \Exception("Directory does not exist: $filecache_path");
            return null;
        }
        if ($filename === '') {
            $logger
                ? $logger->warning('Unable to load data from file: Filename is empty')
                : error_log('Unable to load data from file: Filename is empty');
            return null;
        }
        if (! file_exists($filePath = $filecache_path . $filename)) {
            $logger
                ? $logger->warning("File does not exist: $filePath")
                : error_log("File does not exist: $filePath");
            return null;
        }
        if (($jsonData = @file_get_contents($filePath)) === false) {
            $logger
                ? $logger->warning("Unable to load data from file: $filePath")
                : error_log("Unable to load data from file: $filePath");
            return null;
        }
        if (($assoc_array = @json_decode($jsonData, true)) === null) {
            $logger
                ? $logger->warning("Unable to decode JSON data from file: $filePath")
                : error_log("Unable to decode JSON data from file: $filePath");
            return null;
        }
        return $assoc_array;
    }
}