<?php
/* ============================================================================
(c) Copyright 2012-2014 Hewlett-Packard Development Company, L.P.

    Licensed under the Apache License, Version 2.0 (the "License");
    you may not use this file except in compliance with the License.
    You may obtain a copy of the License at

            http://www.apache.org/licenses/LICENSE-2.0

    Unless required by applicable law or agreed to in writing, software
    distributed under the License is distributed on an "AS IS" BASIS,
    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
    See the License for the specific language governing permissions and
    limitations under the License.
============================================================================ */
/**
 * Base test case.
 */
/**
 * @defgroup Tests
 *
 * The OpenStack library is tested with PHPUnit tests.
 *
 * This group contains all of the unit testing classes.
 */

namespace OpenStack\Tests;

use OpenStack\Identity\v2\IdentityService;
use OpenStack\ObjectStore\v1\ObjectStorage;

/**
 * @ingroup Tests
 */
class TestCase extends \PHPUnit_Framework_TestCase
{
    public static $settings = array();

    public static $ostore = null;

    /**
     * The IdentityService instance.
     */
    public static $ident;

    public static $httpClient = null;

    protected $containerFixture = null;

    public static function setUpBeforeClass()
    {
        global $bootstrap_settings;

        if (!isset($bootstrap_settings)) {
            $bootstrap_settings = array();
        }
        self::$settings = $bootstrap_settings;

        //$this->setTestNamespace('Tests\Units');
        if (file_exists('tests/settings.ini')) {
            self::$settings += parse_ini_file('tests/settings.ini');
        } else {
            throw new \Exception('Could not access test/settings.ini');
        }

        \OpenStack\Autoloader::useAutoloader();
        \OpenStack\Bootstrap::setConfiguration(self::$settings);

        //parent::__construct($score, $locale, $adapter);
    }

    /**
     * Get a configuration value.
     *
     * Optionally, specify a default value to be used
     * if none was found.
     */
    public static function conf($name, $default = null)
    {
        if (isset(self::$settings[$name])) {
            return self::$settings[$name];
        }

        return $default;
    }

    protected static function createIdentityService()
    {
        $username = self::conf('openstack.identity.username');
        $password = self::conf('openstack.identity.password');
        $url      = self::conf('openstack.identity.url');
        $tenantId = self::conf('openstack.identity.tenantId');

        $service = new IdentityService($url);
        $service->authenticateAsUser($username, $password, $tenantId);

        return $service;
    }

    protected static function createObjectStoreService()
    {
        return ObjectStorage::newFromIdentity(
            self::createIdentityService(),
            self::$settings['openstack.swift.region'],
            self::getTransportClient()
        );
    }

    /**
     * Get a handle to an IdentityService object.
     *
     * Authentication is performed, and the returned
     * service has its tenant ID set already.
     *
     *     <?php
     *     // Get the current token.
     *     $this->identity()->token();
     *     ?>
     */
    protected function identity($reset = false)
    {
        if ($reset || empty(self::$ident)) {
            self::$ident = self::createIdentityService();
        }

        return self::$ident;
    }

    protected function objectStore($reset = false)
    {
        if ($reset || empty(self::$ostore)) {
            self::$ostore = self::createObjectStoreService();
        }

        return self::$ostore;
    }

    /**
     * Get a container from the server.
     */
    protected function containerFixture()
    {
        if (empty($this->containerFixture)) {
            $store = $this->objectStore();
            $cname = self::$settings['openstack.swift.container'];

            try {
                $store->createContainer($cname);
                $this->containerFixture = $store->container($cname);

            }
            // This is why PHP needs 'finally'.
            catch (\Exception $e) {
                // Delete the container.
                $store->deleteContainer($cname);
                throw $e;
            }

        }

        return $this->containerFixture;
    }

    /**
     * Clear and destroy a container.
     *
     * Destroy all of the files in a container, then destroy the
     * container.
     *
     * If the container doesn't exist, this will silently return.
     *
     * @param string $cname The name of the container.
     */
    protected function eradicateContainer($cname)
    {
        $store = $this->objectStore();
        try {
            $container = $store->container($cname);
        }
        // The container was never created.
        catch (\OpenStack\Common\Transport\Exception\FileNotFoundException $e) {
            return;
        }

        foreach ($container as $object) {
            try {
                $container->delete($object->name());
            } catch (\Exception $e) {}
        }

        $store->deleteContainer($cname);

    }

    /**
     * Retrieve the HTTP Transport Client
     *
     * @return \OpenStack\Common\Transport\ClientInterface A transport client.
     */
    public static function getTransportClient()
    {
        if (is_null(self::$httpClient)) {
            $options = [];
            if (isset(self::$settings['transport.proxy'])) {
                $options['proxy'] = self::$settings['transport.proxy'];
            }
            if (isset(self::$settings['transport.debug'])) {
                $options['debug'] = self::$settings['transport.debug'];
            }
            if (isset(self::$settings['transport.ssl.verify'])) {
                $options['ssl_verify'] = self::$settings['transport.ssl.verify'];
            }
            if (isset(self::$settings['transport.timeout'])) {
                $options['timeout'] = self::$settings['transport.timeout'];
            }

            self::$httpClient = new self::$settings['transport']($options);
        }

        return self::$httpClient;
    }

    /**
     * Destroy a container fixture.
     *
     * This should be called in any method that uses containerFixture().
     */
    protected function destroyContainerFixture()
    {
        $store = $this->objectStore();
        $cname = self::$settings['openstack.swift.container'];

        try {
            $container = $store->container($cname);
        }
        // The container was never created.
        catch (\OpenStack\Common\Transport\Exception\FileNotFoundException $e) {
            return;
        }

        foreach ($container as $object) {
            try {
                $container->delete($object->name());
            } catch (\Exception $e) {
                syslog(LOG_WARNING, $e);
            }
        }

        $store->deleteContainer($cname);
    }
}
