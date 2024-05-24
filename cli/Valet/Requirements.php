<?php

namespace Valet;

use RuntimeException;

class Requirements
{
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var bool
     */
    public $ignoreSELinux = false;

    /**
     * Create a new Warning instance.
     */
    public function __construct(CommandLine $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Determine if SELinux check should be skipped.
     */
    public function setIgnoreSELinux(bool $ignore = true): self
    {
        $this->ignoreSELinux = $ignore;

        return $this;
    }

    /**
     * Run all checks and output warnings.
     */
    public function check(): void
    {
        $this->homePathIsInsideRoot();
        $this->seLinuxIsEnabled();
    }

    /**
     * Verify if valet home is inside /root directory.
     *
     * This usually means the HOME parameters has not been
     * kept using sudo.
     */
    private function homePathIsInsideRoot(): void
    {
        if (strpos(VALET_HOME_PATH, '/root/') === 0) {
            throw new RuntimeException('Valet home directory is inside /root');
        }
    }

    /**
     * Verify is SELinux is enabled and in enforcing mode.
     */
    private function seLinuxIsEnabled(): void
    {
        if ($this->ignoreSELinux) {
            return;
        }

        $output = $this->cli->run('sestatus');

        if (preg_match('@SELinux status:(\s+)enabled@', $output)
            && preg_match('@Current mode:(\s+)enforcing@', $output)
        ) {
            throw new RuntimeException('SELinux is in enforcing mode');
        }
    }
}
