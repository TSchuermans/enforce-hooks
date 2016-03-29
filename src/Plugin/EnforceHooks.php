<?php

namespace TSchuermans\Composer\Plugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;

/**
 * Class EnforceHooks
 *
 * @package TSchuermans\Plugin
 */
class EnforceHooks implements PluginInterface, EventSubscriberInterface
{
    /**
     * @type string
     */
    const PACKAGE_NAME = 'tschuermans/enforce-hooks';

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var Filesystem
     */
    private $pluginFilesystem;

    /**
     * @var Filesystem
     */
    private $repositoryFilesystem;

    /**
     * Absolute path of the top-level repository directory
     *
     * @var string
     */
    private $repositoryPath;

    /**
     * Absolute path of the top-level plugin directory
     *
     * @var string
     */
    private $pluginPath;

    /**
     * @var bool
     */
    private $initialiseGitHooks = false;

    /**
     * Apply plugin modifications to Composer
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $ds = DIRECTORY_SEPARATOR;
        $this->composer = $composer;
        $this->io = $io;
        $this->repositoryPath = rtrim(exec('git rev-parse --show-toplevel'), $ds);
        $this->pluginPath = rtrim(
            realpath(
                rtrim(dirname(__FILE__), $ds) . $ds . '..' . $ds . '..'
            ),
            $ds
        );
        $this->pluginFilesystem = new Filesystem(
            new Local(
                $this->pluginPath
            )
        );
        $this->repositoryFilesystem = new Filesystem(
            new Local(
                $this->repositoryPath
            )
        );
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * The array keys are event names and the value can be:
     *
     * * The method name to call (priority defaults to 0)
     * * An array composed of the method name to call and the priority
     * * An array of arrays composed of the method names to call and respective
     *   priorities, or 0 if unset
     *
     * For instance:
     *
     * * array('eventName' => 'methodName')
     * * array('eventName' => array('methodName', $priority))
     * * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageEvent',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPackageEvent',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'onPackageEvent',
            ScriptEvents::POST_INSTALL_CMD => 'onScriptEvent',
            ScriptEvents::POST_UPDATE_CMD => 'onScriptEvent',
        ];
    }

    /**
     * @param PackageEvent $packageEvent
     *
     * @return void
     */
    public function onPackageEvent(PackageEvent $packageEvent)
    {
        $package = $this->getPackageFromEvent($packageEvent);

        if (!($package->getName() === static::PACKAGE_NAME)) {
            return;
        }

        if ($packageEvent->getOperation() instanceof Operation\UninstallOperation) {
            $this->initialiseGitHooks = false;

            $this->deinitialiseGitHooks();

            return;
        }

        $this->initialiseGitHooks = true;
    }

    /**
     * @param Event $scriptEvent
     */
    public function onScriptEvent(Event $scriptEvent)
    {
        if ($this->initialiseGitHooks) {
            $this->initialiseGitHooks();
        }
    }

    /**
     * Copy git hooks from plugin directory to .git/hooks directory of repository and chmod them to be executable
     */
    private function initialiseGitHooks()
    {
        $ds = DIRECTORY_SEPARATOR;
        $hooks = $this->pluginFilesystem->listContents('./hooks', true);

        foreach ($hooks as $hook) {
            if ($this->repositoryFilesystem->has('.' . $ds . '.git' . $ds . 'hooks' . $ds . $hook['filename'])) {
                $this->io->write($hook['filename'] . ' already exists, skipping ...');

                continue;
            }

            $hookContent = $this->pluginFilesystem->read($hook['path']);

            $this->repositoryFilesystem->write(
                '.' . $ds . '.git' . $ds . 'hooks' . $ds . $hook['filename'],
                $hookContent
            );

            chmod($this->repositoryPath . $ds . '.git' . $ds . 'hooks' . $ds . $hook['filename'], 0755);
        }
    }

    /**
     * Remove git hooks from .git/hooks directory
     */
    private function deinitialiseGitHooks()
    {
        $ds = DIRECTORY_SEPARATOR;
        $hooks = $this->pluginFilesystem->listContents('./hooks', true);

        foreach ($hooks as $hook) {
            if ($this->repositoryFilesystem->has('.' . $ds . '.git' . $ds . 'hooks' . $ds . $hook['filename'])) {
                $hookContent = $this->repositoryFilesystem->read(
                    '.' . $ds . '.git' . $ds . 'hooks' . $ds . $hook['filename']
                );

                // Only remove our own custom hooks
                if (preg_match('/\# custom\-hook/', $hookContent)) {
                    $this->repositoryFilesystem->delete(
                        '.' . $ds . '.git' . $ds . 'hooks' . $ds . $hook['filename']
                    );
                }
            }
        }
    }

    /**
     * @param PackageEvent $packageEvent
     *
     * @return PackageInterface
     */
    private function getPackageFromEvent(PackageEvent $packageEvent)
    {
        $operation = $packageEvent->getOperation();

        if ($operation instanceof Operation\UpdateOperation) {
            return $operation->getTargetPackage();
        }

        /** @var Operation\InstallOperation|Operation\UninstallOperation $operation */
        return $operation->getPackage();
    }
}
