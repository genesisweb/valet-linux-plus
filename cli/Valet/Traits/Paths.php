<?php

namespace Valet\Traits;

trait Paths
{
    /**
     * Get the path to the linked Valet sites.
     */
    private function sitesPath(string $file = null): string
    {
        return VALET_HOME_PATH . '/Sites' . ($file ? '/' . $file : '');
    }

    /**
     * Get the path to the Valet TLS certificates.
     */
    private function certificatesPath(string $file = null): string
    {
        return VALET_HOME_PATH . '/Certificates' . ($file ? '/' . $file : '');
    }

    /**
     * Get the path to the Valet CA certificates.
     */
    private function caPath(string $file = null): string
    {
        return VALET_HOME_PATH . '/CA' . ($file ? '/' . $file : '');
    }

    /**
     * Get the path to Nginx site configuration files.
     */
    private function nginxPath(string $file = null): string
    {
        return VALET_HOME_PATH . '/Nginx' . ($file ? '/' . $file : '');
    }
}
