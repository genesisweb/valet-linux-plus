<?php

namespace Valet\Contracts;

interface ServiceManager
{
    /**
     * Start the given services.
     *
     * @param
     *
     * @return void
     */
    public function start($services);

    /**
     * Stop the given services.
     *
     * @param
     *
     * @return void
     */
    public function stop($services);

    /**
     * Restart the given services.
     *
     * @param
     *
     * @return void
     */
    public function restart($services);

    /**
     * Enable the given services.
     *
     * @param
     *
     * @return bool
     */
    public function enable($services);

    /**
     * Disable the given services.
     *
     * @param
     *
     * @return bool
     */
    public function disable($services);

    /**
     * Status the given services.
     *
     * @param
     *
     * @return void
     */
    public function status($services);

    /**
     * Determine if service manager is available on the system.
     *
     * @return bool
     */
    public function isAvailable();

    /**
     * Determine if service manager is systemctl/service.
     *
     * @return bool
     */
    public function _hasSystemd();
}
