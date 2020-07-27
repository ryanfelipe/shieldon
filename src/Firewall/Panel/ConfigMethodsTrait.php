<?php
/**
 * This file is part of the Shieldon package.
 *
 * (c) Terry L. <contact@terryl.in>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 * php version 7.1.0
 * 
 * @category  Web-security
 * @package   Shieldon
 * @author    Terry Lin <contact@terryl.in>
 * @copyright 2019 terrylinooo
 * @license   https://github.com/terrylinooo/shieldon/blob/2.x/LICENSE MIT
 * @link      https://github.com/terrylinooo/shieldon
 * @see       https://shieldon.io
 */

declare(strict_types=1);

namespace Shieldon\Firewall\Panel;

use function Shieldon\Firewall\__;

use PDO;
use PDOException;
use Redis;
use RedisException;
use function class_exists;
use function file_exists;
use function is_numeric;
use function is_string;
use function is_writable;
use function mkdir;
use function password_hash;
use function preg_split;
use function rtrim;
use function str_replace;
use function umask;

/*
 * @since 2.0.0
 */
trait ConfigMethodsTrait
{
    /**
     * Parse the POST fields and set them into configuration data structure.
     * Used for saveConfig method only.
     *
     * @param array $postParams The PSR-7 variable of $_POST
     *
     * @return void
     */
    protected function saveConfigPrepareSettings(array $postParams): void
    {
        foreach ($postParams as $postKey => $postData) {

            if (is_string($postData)) {
                if ($postData === 'on') {
                    $this->setConfig(str_replace('__', '.', $postKey), true);

                } elseif ($postData === 'off') {
                    $this->setConfig(str_replace('__', '.', $postKey), false);

                } elseif ($postKey === 'ip_variable_source') {
                    $this->setConfig('ip_variable_source.REMOTE_ADDR', false);
                    $this->setConfig('ip_variable_source.HTTP_CF_CONNECTING_IP', false);
                    $this->setConfig('ip_variable_source.HTTP_X_FORWARDED_FOR', false);
                    $this->setConfig('ip_variable_source.HTTP_X_FORWARDED_HOST', false);
                    $this->setConfig('ip_variable_source.' . $postData, true);

                } elseif ($postKey === 'dialog_ui__shadow_opacity') {
                    $this->setConfig('dialog_ui.shadow_opacity', (string) $postData);

                } elseif ($postKey === 'admin__pass') {
                    if (strlen($postParams['admin__pass']) < 58) {
                        $this->setConfig('admin.pass', password_hash($postData, PASSWORD_BCRYPT));
                    }

                } else if ($postKey === 'messengers__sendgrid__config__recipients') {
                    $this->setConfig(
                        'messengers.sendgrid.config.recipients',
                        preg_split(
                            '/\r\n|[\r\n]/',
                            $postData
                        )
                    );

                } elseif (is_numeric($postData)) {
                    $this->setConfig(str_replace('__', '.', $postKey), (int) $postData);

                } else {
                    $this->setConfig(str_replace('__', '.', $postKey), $postData);
                }
            }
        }
    }

    /**
     * Check the settings of Action Logger.
     * 
     * @param bool $result The result passed from previous check.
     *
     * @return bool
     */
    protected function saveConfigCheckActionLogger(bool $result): bool
    {
        if (!$result) {
            return false;
        }

        // Check Action Logger settings.
        $enableActionLogger = $this->getConfig('loggers.action.enable');
        $actionLogDir = rtrim($this->getConfig('loggers.action.config.directory_path'), '\\/ ');

        if ($enableActionLogger) {
            if (empty($actionLogDir)) {
                $actionLogDir = $this->directory . '/action_logs';
            }

            $this->setConfig('loggers.action.config.directory_path', $actionLogDir);

            if (!is_dir($actionLogDir)) {
                $originalUmask = umask(0);
                mkdir($actionLogDir, 0777, true);
                umask($originalUmask);
            }

            if (!is_writable($actionLogDir)) {
                $result = false;
                $this->pushMessage(
                    'error',
                    __(
                        'panel',
                        'error_logger_directory_not_writable',
                        'Action Logger requires the storage directory writable.'
                    )
                );
            }
        }

        return $result;
    }

    /**
     * Check the settings of Iptables.
     * 
     * @param bool $result The result passed from previous check.
     *
     * @return bool
     */
    protected function saveConfigCheckIptables(bool $result): bool
    {
        if (!$result) {
            return false;
        }

        // System firewall.
        $enableIptables = $this->getConfig('iptables.enable');
        $iptablesWatchingFolder = rtrim($this->getConfig('iptables.config.watching_folder'), '\\/ ');

        if ($enableIptables) {
            if (empty($iptablesWatchingFolder)) {
                $iptablesWatchingFolder = $this->directory . '/iptables';
            }

            $this->setConfig('iptables.config.watching_folder', $iptablesWatchingFolder);

            if (!is_dir($iptablesWatchingFolder)) {
                $originalUmask = umask(0);
                mkdir($iptablesWatchingFolder, 0777, true);
                umask($originalUmask);
            }
    
            // Create default log files.
            if (is_writable($iptablesWatchingFolder)) {
                fopen($iptablesWatchingFolder . '/iptables_queue.log', 'w+');
                fopen($iptablesWatchingFolder . '/ipv4_status.log',    'w+');
                fopen($iptablesWatchingFolder . '/ipv6_status.log',    'w+');
                fopen($iptablesWatchingFolder . '/ipv4_command.log',   'w+');
                fopen($iptablesWatchingFolder . '/ipv6_command.log',   'w+');

                return $result;
            }

            $result = false;

            $this->pushMessage(
                'error',
                __(
                    'panel',
                    'error_ip6tables_directory_not_writable',
                    'iptables watching folder requires the storage directory writable.'
                )
            );
        }

        return $result;
    }

    /**
     * Check the settings of Data drivers.
     *
     * @param bool $result The result passed from previous check.
     *
     * @return bool
     */
    protected function saveConfigCheckDataDriver(bool $result): bool
    {
        if (!$result) {
            return false;
        }

        $type = $this->configuration['driver_type'];

        $methods = [
            'mysql' => 'saveCofigCheckDataDriverMySql',
            'sqlite' => 'saveCofigCheckDataDriverSqlLite',
            'redis' => 'saveCofigCheckDataDriverRedis',
            'file' => 'saveCofigCheckDataDriverFile',
        ];

        $method = $methods[$type];
        $result = $result = $this->{$method}($result);

        return $result;
    }

    /**
     * Check the settings of Data drivers.
     *
     * @param bool $result The result passed from previous check.
     *
     * @return bool
     */
    protected function saveCofigCheckDataDriverMySql(bool $result): bool
    {
        if (class_exists('PDO')) {

            $db = [
                'host'    => $this->getConfig('drivers.mysql.host'),
                'dbname'  => $this->getConfig('drivers.mysql.dbname'),
                'user'    => $this->getConfig('drivers.mysql.user'),
                'pass'    => $this->getConfig('drivers.mysql.pass'),
                'charset' => $this->getConfig('drivers.mysql.charset'),
            ];

            try {
                new PDO(
                    'mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'] . ';charset=' . $db['charset'],
                    (string) $db['user'],
                    (string) $db['pass']
                );
            } catch (PDOException $e) {

                $result = false;

                $this->pushMessage(
                    'error', 
                    __(
                        'panel',
                        'error_mysql_connection',
                        'Cannot access to your MySQL database, please check your settings.'
                    )
                );
            }
            return $result;
        } 

        $result = false;

        $this->pushMessage(
            'error',
            __(
                'panel',
                'error_mysql_driver_not_supported',
                'Your system doesn’t support MySQL driver.'
            )
        );

        return $result;
    }

    /**
     * Check the settings of Data drivers.
     *
     * @param bool $result The result passed from previous check.
     *
     * @return bool
     */
    protected function saveCofigCheckDataDriverSqlLite(bool $result): bool
    {
        $sqliteDir = rtrim($this->getConfig('drivers.sqlite.directory_path'), '\\/ ');

        if (empty($sqliteDir)) {
            $sqliteDir = $this->directory . '/data_driver_sqlite';
        }

        $sqliteFilePath = $sqliteDir . '/shieldon.sqlite3';
        $this->setConfig('drivers.sqlite.directory_path', $sqliteDir);
        
        if (!file_exists($sqliteFilePath)) {
            if (!is_dir($sqliteDir)) {
                $originalUmask = umask(0);
                mkdir($sqliteDir, 0777, true);
                umask($originalUmask);
            }
        }

        if (class_exists('PDO')) {

            try {
                new PDO('sqlite:' . $sqliteFilePath);

            } catch (PDOException $e) { 
                $this->pushMessage('error', $e->getMessage());
                $result = false;
            }

            if (!is_writable($sqliteFilePath)) {
                $this->pushMessage(
                    'error',
                    __(
                        'panel',
                        'error_sqlite_directory_not_writable',
                        'SQLite data driver requires the storage directory writable.'
                    )
                );
                $result = false;
            }

            return $result;
        } 

        $result = false;

        $this->pushMessage(
            'error',
            __(
                'panel',
                'error_sqlite_driver_not_supported',
                'Your system doesn’t support SQLite driver.'
            )
        );

        return $result;
    }

    /**
     * Check the settings of Data drivers.
     *
     * @param bool $result The result passed from previous check.
     *
     * @return bool
     */
    protected function saveCofigCheckDataDriverRedis(bool $result): bool
    {
        if (class_exists('Redis')) {

            try {
                $redis = new Redis();
                $redis->connect(
                    (string) $this->getConfig('drivers.redis.host'), 
                    (int)    $this->getConfig('drivers.redis.port')
                );
                unset($redis);

            } catch (RedisException $e) {
                $this->pushMessage('error', $e->getMessage());
                $result = false;
            }

            return $result;
        }

        $result = false;

        $this->pushMessage(
            'error',
            __(
                'panel',
                'error_redis_driver_not_supported',
                'Your system doesn’t support Redis driver.'
            )
        );

        return $result;
    }

    /**
     * Check the settings of Data drivers.
     *
     * @param bool $result The result passed from previous check.
     *
     * @return bool
     */
    protected function saveCofigCheckDataDriverFile(bool $result): bool
    {
        $fileDir = rtrim($this->getConfig('drivers.file.directory_path'), '\\/ ');

        if (empty($fileDir)) {
            $fileDir = $this->directory . '/data_driver_file';
            $this->setConfig('drivers.file.directory_path', $fileDir);
        }

        $this->setConfig('drivers.file.directory_path', $fileDir);

        if (!is_dir($fileDir)) {
            $originalUmask = umask(0);
            mkdir($fileDir, 0777, true);
            umask($originalUmask);
        }

        if (!is_writable($fileDir)) {
            $result = false;
            $this->pushMessage(
                'error',
                __(
                    'panel',
                    'error_file_directory_not_writable',
                    'File data driver requires the storage directory writable.'
                )
            );
        }

        return $result;
    }
}