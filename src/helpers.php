<?php

declare(strict_types=1);

if (! function_exists('info_if')) {
    /**
     * Log info message if step-dispatcher.info_if or martingalian.info_if is enabled.
     *
     * @param  string  $message  The message to log
     * @param  callable|null  $condition  Optional condition callback
     */
    function info_if(string $message, ?callable $condition = null): void
    {
        // Support both step-dispatcher and martingalian config keys for backward compatibility
        if (config('step-dispatcher.info_if', config('martingalian.info_if', false)) === true) {
            info($message);
        }

        if (! is_null($condition) && $condition()) {
            info($message);
        }
    }
}

if (! function_exists('log_step')) {
    /**
     * Log a message to a step-specific log file for debugging.
     * Creates one file per step ID in storage/logs/steps/<step-id>/step.log
     *
     * @param  int|string  $stepId  The step ID to log for
     * @param  string  $message  The message to log
     */
    function log_step(int|string $stepId, string $message): void
    {
        // Support both step-dispatcher and martingalian config keys for backward compatibility
        if (! config('step-dispatcher.logging.enabled', config('martingalian.logging.step_related_logging', false))) {
            return;
        }

        $logsPath = storage_path("logs/steps/{$stepId}");

        if (! is_dir($logsPath)) {
            mkdir($logsPath, 0o755, true);
        }

        $timestamp = now()->format('Y-m-d H:i:s.u');
        $logFile = "{$logsPath}/step.log";
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;

        file_put_contents($logFile, $logMessage, flags: FILE_APPEND | LOCK_EX);
    }
}

if (! function_exists('throttle_log')) {
    /**
     * Log a throttling decision to a dedicated throttler log file.
     * Writes to: storage/logs/steps/<step-id>/throttler.log
     *
     * @param  int|string|null  $stepId  The step ID to log for
     * @param  string  $message  The message to log
     */
    function throttle_log(int|string|null $stepId, string $message): void
    {
        // Support both step-dispatcher and martingalian config keys for backward compatibility
        if (! config('step-dispatcher.logging.enabled', config('martingalian.logging.step_related_logging', false))) {
            return;
        }

        if ($stepId === null) {
            return;
        }

        $logsPath = storage_path("logs/steps/{$stepId}");

        if (! is_dir($logsPath)) {
            mkdir($logsPath, 0o755, true);
        }

        $timestamp = now()->format('Y-m-d H:i:s.u');
        $logFile = "{$logsPath}/throttler.log";
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;

        file_put_contents($logFile, $logMessage, flags: FILE_APPEND | LOCK_EX);
    }
}
