<?php

namespace Valet;

use Symfony\Component\Process\Process;

class CommandLine
{
    /**
     * Simple global function to run commands.
     */
    public function quietly(string $command): void
    {
        $this->runCommand($command.' > /dev/null 2>&1');
    }

    /**
     * Simple global function to run commands.
     */
    public function quietlyAsUser(string $command): void
    {
        $this->quietly('sudo -u '.user().' '.$command.' > /dev/null 2>&1');
    }

    /**
     * Pass the command to the command line and display the output.
     */
    public function passthru(string $command): void
    {
        passthru($command);
    }

    /**
     * Run the given command as the non-root user.
     */
    public function run(string $command, callable $onError = null): string
    {
        return $this->runCommand($command, $onError);
    }

    /**
     * Run the given command.
     */
    public function runAsUser(string $command, callable $onError = null): string
    {
        return $this->runCommand('sudo -u '.user().' '.$command, $onError);
    }

    /**
     * Run the given command.
     */
    private function runCommand(string $command, callable $onError = null): string
    {
        $onError = $onError ?: function () {
        };

        $process = Process::fromShellCommandline($command);

        $processOutput = '';
        $process->setTimeout(null)->run(function ($type, $line) use (&$processOutput) {
            $processOutput .= $line;
        });

        if ($process->getExitCode() > 0) {
            $onError($process->getExitCode(), $processOutput);
        }

        return $processOutput;
    }
}
