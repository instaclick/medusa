<?php
/**
 * @copyright 2013 Sébastien Armand
 * @license http://opensource.org/licenses/MIT MIT
 */

namespace Khepin\Medusa;

use GuzzleHttp\Client;

/**
 * Finds all the dependencies on which a given package relies
 */
class DependencyResolver
{
    protected $packages;
    protected $package;

    public function __construct($package)
    {
        $this->packages = null;
        $this->package = $package;
    }

    public function resolve()
    {
        $deps = array($this->package);
        $resolved = array();

        $guzzle = new Client(['base_uri' => 'https://packagist.org']);

        while (count($deps) > 0) {
            $package = array_pop($deps);
            $package = $this->rename($package);

            if (!$package || $this->isSystemPackage($package) === true) {
                continue;
            }

            try {
                $response = $guzzle->get('/packages/'.$package.'.json')->getBody()->getContents();
            } catch (\Exception $e) {
                continue;
            }
            $package = json_decode($response);

            if (!is_null($package)) {
                foreach ($package->package->versions as $version) {
                    if (!isset($version->require)) {
                        continue;
                    }

                    foreach ($version->require as $dependency => $version) {
                        if (!in_array($dependency, $resolved) && !in_array($dependency, $deps)) {
                            $deps[] = $dependency;
                            $deps = array_unique($deps);
                        }
                    }
                }

                $resolved[] = $package->package->name;
            }
        }

        return $resolved;
    }

    private function isSystemPackage($package) {
        // If the package name don't contain a "/" we will skip it here.
        // In a composer.json in the require / require-dev part you normally add packages
        // you depend on. A package name follows the format "vendor/package".
        // E.g. symfony/console
        // You can put other dependencies in here as well like `php` or `ext-zip`.
        // Those dependencies will be skipped (because they don`t have a vendor ;)).
        // The reason is simple: If you try to request the package "php" at packagist
        // you won`t get a JSON response with information we expect.
        // You will get valid HTML of the packagist search.
        // To avoid those errors and to save API calls we skip dependencies without a vendor.
        //
        // This follows the documentation as well:
        //
        // 	The package name consists of a vendor name and the project's name.
        // 	Often these will be identical - the vendor name just exists to prevent naming clashes.
        //	Source: https://getcomposer.org/doc/01-basic-usage.md
        return (strstr($package, '/')) ? false: true;
    }

    private function rename($package)
    {
        if ( ! isset($this->packages)) {
            if (file_exists('resolver.json')) {
                $this->packages = json_decode(file_get_contents('resolver.json'), true);
            }

            if ( ! is_array($this->packages)) {
                $this->packages = [];
            }
        }

        if (array_key_exists($package, $this->packages)) {
            return $this->packages[$package];
        }

        return $package;
    }
}
