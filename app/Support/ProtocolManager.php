<?php

namespace App\Support;

use App\Services\Plugin\HookManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ProtocolManager
{
    protected Container $container;

    protected array $protocolClasses = [];

    protected bool $registered = false;

    protected array $pluginScanPaths = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->pluginScanPaths = [
            base_path('plugins-core'),
            base_path('plugins'),
        ];
    }

    public function registerAllProtocols(): self
    {
        if ($this->registered) {
            return $this;
        }

        $this->protocolClasses = [];

        $this->discoverFromPlugins();
        $this->discoverFromHook();

        $this->registered = true;

        return $this;
    }

    protected function discoverFromPlugins(): void
    {
        foreach ($this->pluginScanPaths as $pluginsDir) {
            if (!is_dir($pluginsDir)) {
                continue;
            }
            $directories = glob($pluginsDir . '/*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                $configFile = $dir . '/config.json';
                if (!File::exists($configFile)) {
                    continue;
                }
                $config = json_decode(File::get($configFile), true);
                if (($config['type'] ?? '') !== 'protocol') {
                    continue;
                }
                $dirName = basename($dir);
                $namespace = 'Plugin\\' . Str::studly($dirName);

                $phpFiles = glob($dir . '/*.php');
                foreach ($phpFiles as $phpFile) {
                    $className = basename($phpFile, '.php');
                    if ($className === 'Plugin') {
                        continue;
                    }
                    require_once $phpFile;
                    $fqcn = $namespace . '\\' . $className;
                    if (is_subclass_of($fqcn, AbstractProtocol::class)) {
                        if (!in_array($fqcn, $this->protocolClasses, true)) {
                            $this->protocolClasses[] = $fqcn;
                        }
                    }
                }
            }
        }
    }

    protected function discoverFromHook(): void
    {
        $extraClasses = HookManager::filter('protocols.register', []);
        foreach ($extraClasses as $className) {
            if (!in_array($className, $this->protocolClasses, true)
                && class_exists($className)
                && is_subclass_of($className, AbstractProtocol::class)) {
                $this->protocolClasses[] = $className;
            }
        }
    }

    public function reset(): self
    {
        $this->registered = false;
        $this->protocolClasses = [];
        return $this;
    }

    public function getProtocolClasses(): array
    {
        if (!$this->registered) {
            $this->registerAllProtocols();
        }
        return $this->protocolClasses;
    }

    public function getAllFlags(): array
    {
        return collect($this->getProtocolClasses())
            ->map(function ($class) {
                try {
                    $reflection = new \ReflectionClass($class);
                    if (!$reflection->isInstantiable()) {
                        return [];
                    }
                    $instanceForFlags = $reflection->newInstanceWithoutConstructor();
                    return $instanceForFlags->flags;
                } catch (\ReflectionException $e) {
                    report($e);
                    return [];
                }
            })
            ->flatten()
            ->unique()
            ->values()
            ->all();
    }

    public function matchProtocolClassName(string $flag): ?string
    {
        foreach (array_reverse($this->getProtocolClasses()) as $protocolClassString) {
            try {
                $reflection = new \ReflectionClass($protocolClassString);

                if (!$reflection->isInstantiable() || !$reflection->isSubclassOf(AbstractProtocol::class)) {
                    continue;
                }

                $instanceForFlags = $reflection->newInstanceWithoutConstructor();
                $flags = $instanceForFlags->flags;

                if (collect($flags)->contains(fn($f) => stripos($flag, (string) $f) !== false)) {
                    return $protocolClassString;
                }
            } catch (\ReflectionException $e) {
                report($e);
                continue;
            }
        }
        return null;
    }

    public function matchProtocol($flag, $user, $servers, $clientInfo = [])
    {
        $protocolClassName = $this->matchProtocolClassName($flag);
        if ($protocolClassName) {
            return $this->makeProtocolInstance($protocolClassName, [
                'user' => $user,
                'servers' => $servers,
                'clientName' => $clientInfo['name'] ?? null,
                'clientVersion' => $clientInfo['version'] ?? null,
            ]);
        }
        return null;
    }

    protected function makeProtocolInstance($class, array $parameters)
    {
        return $this->container->make($class, $parameters);
    }
}
