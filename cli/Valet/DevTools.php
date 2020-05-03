<?php


namespace Valet;


use DomainException;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class DevTools
{
    const SUBLIME = 'subl';
    const PHP_STORM = 'phpstorm.sh';
    const ATOM = 'atom';

    public $pm;
    public $sm;
    public $cli;
    public $files;

    /**
     * Create a new Mailhog instance.
     *
     * @param  PackageManager $pm
     * @param  ServiceManager $sm
     * @param  CommandLine $cli
     * @param  Filesystem $files
     * @return void
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, CommandLine $cli, Filesystem $files) {

        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
    }

    public function ensureInstalled($service) {

        return $this->getBin($service);
    }

    public function getBin($service) {

        if (!($bin = $this->getService($service))) {
            $bin = $this->getService($service,true);
        }
        return trim(preg_replace('/\s\s+/', ' ', $bin));
    }

    public function getService($service,$locate = false) {

        try {
            $locator = $locate?"locate":"which";
            $output = $this->cli->run(
                "$locator $service",
                function ($exitCode, $output) {
                    throw new DomainException('Service not available');
                }
            );
            return $output;
        } catch (DomainException $e) {
            return false;
        }
    }

    public function runService($service,$folder = null) {

        $bin = $this->getBin($service);

        try {
            $this->cli->quietly("$bin $folder");
        } catch (DomainException $e) {
            warning("Error while opening [$folder] with $service");
        }
    }

    public function runPhpStorm($folder) {

        if ($this->ensureInstalled(self::PHP_STORM)) {

            $this->runService(self::PHP_STORM,$folder);
        } else warning("PHPStorm not available");
    }

    public function runAtom($folder) {

        if ($this->ensureInstalled(self::ATOM)) {

            $this->runService(self::ATOM,$folder);
        } else warning("Atom not available");

    }

    public function runSublime($folder) {

        if ($this->ensureInstalled(self::SUBLIME)) {

            $this->runService(self::SUBLIME,$folder);
        } else warning("Sublime not available");
    }
}
