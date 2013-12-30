<?php
namespace Midgard\AppServerBundle\AiP;

use Midgard\AppServerBundle\AiP\Application;
use AiP\App\FileServe;
use AiP\Middleware\HTTPParser;
use AiP\Middleware\Session;
use AiP\Middleware\URLMap;
use AiP\Middleware\Logger;

class Runner
{
    /**
     * @var AiP\Middleware\Logger
     */
    private $app;

    private $kernels = array();

    /**
     * Construct prepares the AppServer in PHP URL mappings
     * and is run once. It also loads the Symfony Application kernel
     */
    public function __construct()
    {
        $symfonyRoot = realpath(__DIR__.'/../../../../../..');
        $config = $this->loadConfig($symfonyRoot);

        $urlMap = array();

        $urlMap['/favicon.ico'] = function($ctx) { return array(404, array(), ''); };

        $urlMap = array_merge($urlMap, $this->addKernels($config));
//        $urlMap = array_merge($urlMap, $this->addFileServers("{$symfonyRoot}/web"));

        $map = new URLMap($urlMap);

        $this->app = new Logger($map, STDOUT);
    }

    private function loadConfig($symfonyRoot)
    {
        $aipConfig = "{$symfonyRoot}/" . array_pop($_SERVER['argv']);
        if (!file_exists($aipConfig)) {
            throw new \Exception("No config file '{$aipConfig}' found");
        }
        return \Symfony\Component\Yaml\Yaml::parse($aipConfig);
    }

    private function addKernels(array $config)
    {
        $urlMap = array();
        if (!isset($config['symfony.kernels'])) {
            throw new \Exception("No symfony.kernels configured in {$aipConfig}");
        }

        foreach ($config['symfony.kernels'] as $kernel) {
			$loader = require_once __DIR__.'/../../../../../../app/bootstrap.php.cache';

			$app = new Application($kernel);
            $this->kernels[] = $app->getKernel();
            $urlMap[$kernel['path']] = new HTTPParser(new Session($app));
        }
//print_r($urlMap);
        return $urlMap;
    }

    private function addFileServers($webRoot)
    {
        $urlMap = array();
        $webDirs = scandir($webRoot);
        foreach ($webDirs as $webDir) {
            if (substr($webDir, 0, 1) == '.') {
                continue;
            }
            if ($webDir == 'bundles') {
                $bundleDirs = scandir("{$webRoot}/bundles");
                foreach ($bundleDirs as $bundleDir) {
                    if (substr($bundleDir, 0, 1) == '.') {
                        continue;
                    }
                    $target = "{$webRoot}/bundles/{$bundleDir}";
                    if (is_link($target)) {
                        $target = readlink("{$webRoot}/bundles/{$bundleDir}");
                    }

                    if (!file_exists($target)) {
                        continue;
                    }
                    $urlMap["/bundles/{$bundleDir}"] = new FileServe($target, 4000000);
                }
                continue;
            }
            if (!is_dir("{$webRoot}/{$webDir}")) {
                continue;
            }
            $urlMap["/{$webDir}"] = new FileServe("{$webRoot}/{$webDir}", 4000000);
        }
        
        $urlMap = array_merge($urlMap, $this->addMidcomFileServers());

        return $urlMap;
    }

    private function addMidcomFileServers()
    {
        $urlMap = array();

        // Special handling for MidCOM compatibility bundle web dirs
        foreach ($this->kernels as $kernel) {
            $container = $kernel->getContainer();
            if (!$container->hasParameter('midgard.midcomcompat.root')) {
                continue;
            }

            $midcomRoot = $container->getParameter('midgard.midcomcompat.root');
            
            $themesRoot = realpath("{$midcomRoot}/../themes");
            if (file_exists($themesRoot)) {
                $themeDirs = scandir($themesRoot);
                foreach ($themeDirs as $themeDir) {
                    if (substr($themeDir, 0, 1) == '.') {
                        continue;
                    }
                    if (!file_exists("{$themesRoot}/{$themeDir}/static")) {
                        continue;
                    }
                    $urlMap["/midcom-static/{$themeDir}"] = new FileServe("{$themesRoot}/{$themeDir}/static", 4000000);
                }
            }

            $staticRoot = realpath("{$midcomRoot}/../static");
            if (!file_exists($staticRoot)) {
                continue;
            }

            $webDirs = scandir($staticRoot);
            foreach ($webDirs as $webDir) {
                if (substr($webDir, 0, 1) == '.') {
                    continue;
                }
                $urlMap["/midcom-static/{$webDir}"] = new FileServe("{$staticRoot}/{$webDir}", 4000000);
            }
        }

        return $urlMap;
    }

    /**
     * Invoke is run once per each request. Here we generate a
     * Request object, tell Symfony2 to handle it, and eventually
     * return the Result contents back to AiP
     */
    public function __invoke($context)
    {
        $app = $this->app;
        return $app($context);
    }
}
