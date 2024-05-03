<?php

namespace Valet\Tests;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NullWriter implements OutputInterface
{
    public function writeLn(string|iterable $messages, int $options = 0): void
    {
        // do nothing
    }

    public function write(iterable|string $messages, bool $newline = false, int $options = 0): void
    {
        // TODO: Implement write() method.
    }

    public function setVerbosity(int $level): void
    {
        // TODO: Implement setVerbosity() method.
    }

    public function getVerbosity(): int
    {
        return OutputInterface::VERBOSITY_NORMAL;
        // TODO: Implement getVerbosity() method.
    }

    public function isQuiet(): bool
    {
        return true;
        // TODO: Implement isQuiet() method.
    }

    public function isVerbose(): bool
    {
        return true;
        // TODO: Implement isVerbose() method.
    }

    public function isVeryVerbose(): bool
    {
        return true;
        // TODO: Implement isVeryVerbose() method.
    }

    public function isDebug(): bool
    {
        return true;
    }

    public function setDecorated(bool $decorated): void
    {
        // TODO: Implement setDecorated() method.
    }

    public function isDecorated(): bool
    {
        // TODO: Implement isDecorated() method.
    }

    public function setFormatter(OutputFormatterInterface $formatter): void
    {
        // TODO: Implement setFormatter() method.
    }

    public function getFormatter(): OutputFormatterInterface
    {
        return new OutputFormatter();
        // TODO: Implement getFormatter() method.
    }
}
