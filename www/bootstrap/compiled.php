<?php
namespace Royalcms\Component\Support;

class ClassLoader
{
    protected static $directories = array();
    protected static $registered = false;
    public static function load($class)
    {
        $class = static::normalizeClass($class);
        foreach (static::$directories as $directory) {
            if (file_exists($path = $directory . DIRECTORY_SEPARATOR . $class)) {
                require_once $path;
                return true;
            }
        }
        return false;
    }
    public static function normalizeClass($class)
    {
        if ($class[0] == '\\') {
            $class = substr($class, 1);
        }
        return str_replace(array('\\', '_'), DIRECTORY_SEPARATOR, $class) . '.php';
    }
    public static function register()
    {
        if (!static::$registered) {
            static::$registered = spl_autoload_register(array('\\Royalcms\\Component\\Support\\ClassLoader', 'load'));
        }
    }
    public static function addDirectories($directories)
    {
        static::$directories = array_merge(static::$directories, (array) $directories);
        static::$directories = array_unique(static::$directories);
    }
    public static function removeDirectories($directories = null)
    {
        if (is_null($directories)) {
            static::$directories = array();
        } else {
            $directories = (array) $directories;
            static::$directories = array_filter(static::$directories, function ($directory) use($directories) {
                return !in_array($directory, $directories);
            });
        }
    }
    public static function getDirectories()
    {
        return static::$directories;
    }
}
namespace Royalcms\Component\Container;

use Closure;
use ArrayAccess;
use ReflectionClass;
use ReflectionParameter;
class Container implements ArrayAccess
{
    protected $resolved = array();
    protected $bindings = array();
    protected $instances = array();
    protected $aliases = array();
    protected $reboundCallbacks = array();
    protected $resolvingCallbacks = array();
    protected $globalResolvingCallbacks = array();
    protected function resolvable($abstract)
    {
        return $this->bound($abstract) || $this->isAlias($abstract);
    }
    public function bound($abstract)
    {
        return isset($this[$abstract]) || isset($this->instances[$abstract]);
    }
    public function isAlias($name)
    {
        return isset($this->aliases[$name]);
    }
    public function bind($abstract, $concrete = null, $shared = false)
    {
        if (is_array($abstract)) {
            list($abstract, $alias) = $this->extractAlias($abstract);
            $this->alias($abstract, $alias);
        }
        $this->dropStaleInstances($abstract);
        if (is_null($concrete)) {
            $concrete = $abstract;
        }
        if (!$concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }
        $bound = $this->bound($abstract);
        $this->bindings[$abstract] = compact('concrete', 'shared');
        if ($bound) {
            $this->rebound($abstract);
        }
    }
    protected function getClosure($abstract, $concrete)
    {
        return function ($c, $parameters = array()) use($abstract, $concrete) {
            $method = $abstract == $concrete ? 'build' : 'make';
            return $c->{$method}($concrete, $parameters);
        };
    }
    public function bindIf($abstract, $concrete = null, $shared = false)
    {
        if (!$this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }
    public function singleton($abstract, $concrete = null)
    {
        return $this->bind($abstract, $concrete, true);
    }
    public function share(Closure $closure)
    {
        return function ($container) use($closure) {
            static $object;
            if (is_null($object)) {
                $object = $closure($container);
            }
            return $object;
        };
    }
    public function bindShared($abstract, Closure $closure)
    {
        return $this->bind($abstract, $this->share($closure), true);
    }
    public function extend($abstract, Closure $closure)
    {
        if (!isset($this->bindings[$abstract])) {
            throw new \InvalidArgumentException("Type {$abstract} is not bound.");
        }
        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);
            $this->rebound($abstract);
        } else {
            $extender = $this->getExtender($abstract, $closure);
            $this->bind($abstract, $extender, $this->isShared($abstract));
        }
    }
    protected function getExtender($abstract, Closure $closure)
    {
        $resolver = $this->bindings[$abstract]['concrete'];
        return function ($container) use($resolver, $closure) {
            return $closure($resolver($container), $container);
        };
    }
    public function instance($abstract, $instance)
    {
        if (is_array($abstract)) {
            list($abstract, $alias) = $this->extractAlias($abstract);
            $this->alias($abstract, $alias);
        }
        unset($this->aliases[$abstract]);
        $bound = $this->bound($abstract);
        $this->instances[$abstract] = $instance;
        if ($bound) {
            $this->rebound($abstract);
        }
    }
    public function alias($abstract, $alias)
    {
        $this->aliases[$alias] = $abstract;
    }
    protected function extractAlias(array $definition)
    {
        return array(key($definition), current($definition));
    }
    public function rebinding($abstract, Closure $callback)
    {
        $this->reboundCallbacks[$abstract][] = $callback;
        if ($this->bound($abstract)) {
            return $this->make($abstract);
        }
    }
    public function refresh($abstract, $target, $method)
    {
        return $this->rebinding($abstract, function ($app, $instance) use($target, $method) {
            $target->{$method}($instance);
        });
    }
    protected function rebound($abstract)
    {
        $instance = $this->make($abstract);
        foreach ($this->getReboundCallbacks($abstract) as $callback) {
            call_user_func($callback, $this, $instance);
        }
    }
    protected function getReboundCallbacks($abstract)
    {
        if (isset($this->reboundCallbacks[$abstract])) {
            return $this->reboundCallbacks[$abstract];
        } else {
            return array();
        }
    }
    public function make($abstract, $parameters = array())
    {
        $abstract = $this->getAlias($abstract);
        $this->resolved[$abstract] = true;
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        $concrete = $this->getConcrete($abstract);
        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete, $parameters);
        } else {
            $object = $this->make($concrete, $parameters);
        }
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }
        $this->fireResolvingCallbacks($abstract, $object);
        return $object;
    }
    protected function getConcrete($abstract)
    {
        if (!isset($this->bindings[$abstract])) {
            if ($this->missingLeadingSlash($abstract) && isset($this->bindings['\\' . $abstract])) {
                $abstract = '\\' . $abstract;
            }
            return $abstract;
        } else {
            return $this->bindings[$abstract]['concrete'];
        }
    }
    protected function missingLeadingSlash($abstract)
    {
        return is_string($abstract) && strpos($abstract, '\\') !== 0;
    }
    public function build($concrete, $parameters = array())
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }
        $reflector = new ReflectionClass($concrete);
        if (!$reflector->isInstantiable()) {
            $message = "Target [{$concrete}] is not instantiable.";
            throw new BindingResolutionException($message);
        }
        $constructor = $reflector->getConstructor();
        if (is_null($constructor)) {
            return new $concrete();
        }
        $dependencies = $constructor->getParameters();
        $parameters = $this->keyParametersByArgument($dependencies, $parameters);
        $instances = $this->getDependencies($dependencies, $parameters);
        return $reflector->newInstanceArgs($instances);
    }
    protected function getDependencies($parameters, array $primitives = array())
    {
        $dependencies = array();
        foreach ($parameters as $parameter) {
            $dependency = $parameter->getClass();
            if (array_key_exists($parameter->name, $primitives)) {
                $dependencies[] = $primitives[$parameter->name];
            } elseif (is_null($dependency)) {
                $dependencies[] = $this->resolveNonClass($parameter);
            } else {
                $dependencies[] = $this->resolveClass($parameter);
            }
        }
        return (array) $dependencies;
    }
    protected function resolveNonClass(ReflectionParameter $parameter)
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        } else {
            $message = "Unresolvable dependency resolving [{$parameter}].";
            throw new BindingResolutionException($message);
        }
    }
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $this->make($parameter->getClass()->name);
        } catch (BindingResolutionException $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            } else {
                throw $e;
            }
        }
    }
    protected function keyParametersByArgument(array $dependencies, array $parameters)
    {
        foreach ($parameters as $key => $value) {
            if (is_numeric($key)) {
                unset($parameters[$key]);
                $parameters[$dependencies[$key]->name] = $value;
            }
        }
        return $parameters;
    }
    public function resolving($abstract, Closure $callback)
    {
        $this->resolvingCallbacks[$abstract][] = $callback;
    }
    public function resolvingAny(Closure $callback)
    {
        $this->globalResolvingCallbacks[] = $callback;
    }
    protected function fireResolvingCallbacks($abstract, $object)
    {
        if (isset($this->resolvingCallbacks[$abstract])) {
            $this->fireCallbackArray($object, $this->resolvingCallbacks[$abstract]);
        }
        $this->fireCallbackArray($object, $this->globalResolvingCallbacks);
    }
    protected function fireCallbackArray($object, array $callbacks)
    {
        foreach ($callbacks as $callback) {
            call_user_func($callback, $object, $this);
        }
    }
    public function isShared($abstract)
    {
        if (isset($this->bindings[$abstract]['shared'])) {
            $shared = $this->bindings[$abstract]['shared'];
        } else {
            $shared = false;
        }
        return isset($this->instances[$abstract]) || $shared === true;
    }
    protected function isBuildable($concrete, $abstract)
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }
    protected function getAlias($abstract)
    {
        return isset($this->aliases[$abstract]) ? $this->aliases[$abstract] : $abstract;
    }
    public function getBindings()
    {
        return $this->bindings;
    }
    protected function dropStaleInstances($abstract)
    {
        unset($this->instances[$abstract]);
        unset($this->aliases[$abstract]);
    }
    public function forgetInstance($abstract)
    {
        unset($this->instances[$abstract]);
    }
    public function forgetInstances()
    {
        $this->instances = array();
    }
    public function offsetExists($key)
    {
        return isset($this->bindings[$key]);
    }
    public function offsetGet($key)
    {
        return $this->make($key);
    }
    public function offsetSet($key, $value)
    {
        if (!$value instanceof Closure) {
            $value = function () use($value) {
                return $value;
            };
        }
        $this->bind($key, $value);
    }
    public function offsetUnset($key)
    {
        unset($this->bindings[$key]);
        unset($this->instances[$key]);
    }
}
namespace Symfony\Component\HttpKernel;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
interface HttpKernelInterface
{
    const MASTER_REQUEST = 1;
    const SUB_REQUEST = 2;
    public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true);
}
namespace Symfony\Component\HttpKernel;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
interface TerminableInterface
{
    public function terminate(Request $request, Response $response);
}
namespace Royalcms\Component\Support\Contracts;

interface ResponsePreparerInterface
{
    public function prepareResponse($value);
    public function readyForResponses();
}
namespace Royalcms\Component\Foundation;

use Closure;
use Royalcms\Component\HttpKernel\Request;
use Royalcms\Component\HttpKernel\Response;
use Royalcms\Component\Config\FileLoader;
use Royalcms\Component\Container\Container;
use Royalcms\Component\Filesystem\Filesystem;
use Royalcms\Component\Support\Facades\Facade;
use Royalcms\Component\Event\EventServiceProvider;
use Royalcms\Component\Routing\RoutingServiceProvider;
use Royalcms\Component\Exception\ExceptionServiceProvider;
use Royalcms\Component\Config\FileEnvironmentVariablesLoader;
use Royalcms\Component\Stack\Builder;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Debug\Exception\FatalErrorException;
use Royalcms\Component\Support\Contracts\ResponsePreparerInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
class Royalcms extends Container implements HttpKernelInterface, TerminableInterface, ResponsePreparerInterface
{
    const VERSION = '4.3.0';
    const RELEASE = '2017-08-31';
    const PHP_REQUIRED = '5.4.0';
    protected $booted = false;
    protected $bootingCallbacks = array();
    protected $bootedCallbacks = array();
    protected $finishCallbacks = array();
    protected $shutdownCallbacks = array();
    protected $middlewares = array();
    protected $serviceProviders = array();
    protected $loadedProviders = array();
    protected $deferredServices = array();
    protected static $requestClass = 'Royalcms\\Component\\HttpKernel\\Request';
    protected $environmentFile = '.env';
    public function __construct(Request $request = null)
    {
        $this->registerBaseBindings($request ?: $this->createNewRequest());
        $this->registerBaseServiceProviders();
        $this->registerBaseMiddlewares();
    }
    protected function createNewRequest()
    {
        return forward_static_call(array(static::$requestClass, 'createFromGlobals'));
    }
    protected function registerBaseBindings($request)
    {
        $this->instance('request', $request);
        $this->instance('Royalcms\\Component\\Container\\Container', $this);
    }
    protected function registerBaseServiceProviders()
    {
        foreach (array('Event', 'Exception', 'Routing') as $name) {
            $this->{"register{$name}Provider"}();
        }
    }
    protected function registerExceptionProvider()
    {
        $this->register(new ExceptionServiceProvider($this));
    }
    protected function registerRoutingProvider()
    {
        $this->register(new RoutingServiceProvider($this));
    }
    protected function registerEventProvider()
    {
        $this->register(new EventServiceProvider($this));
    }
    public function bindInstallPaths(array $paths)
    {
        $this->instance('path', realpath($paths['sitecontent']));
        foreach (array_except($paths, array('sitecontent')) as $key => $value) {
            $this->instance("path.{$key}", realpath($value));
        }
    }
    public static function getBootstrapFile()
    {
        return SITE_ROOT . 'vendor/royalcms/framework/Royalcms/Component/Foundation' . '/Start.php';
    }
    public function startExceptionHandling()
    {
        $this['exception']->register($this->environment());
        $this['exception']->setDebug($this['config']['system.debug']);
    }
    public function loadEnvironmentFrom($file)
    {
        $this->environmentFile = $file;
        return $this;
    }
    public function environmentFile()
    {
        return $this->environmentFile ?: '.env';
    }
    public function environment()
    {
        if (func_num_args() > 0) {
            $patterns = is_array(func_get_arg(0)) ? func_get_arg(0) : func_get_args();
            foreach ($patterns as $pattern) {
                if (str_is($pattern, $this['env'])) {
                    return true;
                }
            }
            return false;
        }
        return $this['env'];
    }
    public function isLocal()
    {
        return $this['env'] == 'local';
    }
    public function detectEnvironment($envs)
    {
        $args = isset($_SERVER['argv']) ? $_SERVER['argv'] : null;
        return $this['env'] = with(new EnvironmentDetector())->detect($envs, $args);
    }
    public function runningInConsole()
    {
        return php_sapi_name() == 'cli';
    }
    public function runningUnitTests()
    {
        return $this['env'] == 'testing';
    }
    public function forgeRegister($provider, $options = array())
    {
        return $this->register($provider, $options, true);
    }
    public function register($provider, $options = array(), $force = false)
    {
        $registered = $this->getRegistered($provider);
        if ($registered && !$force) {
            return $registered;
        }
        if (is_string($provider)) {
            $provider = $this->resolveProviderClass($provider);
        }
        $provider->register();
        foreach ($options as $key => $value) {
            $this[$key] = $value;
        }
        $this->markAsRegistered($provider);
        if ($this->booted) {
            $provider->boot();
        }
        return $provider;
    }
    public function getRegistered($provider)
    {
        $name = is_string($provider) ? $provider : get_class($provider);
        if (array_key_exists($name, $this->loadedProviders)) {
            return array_first($this->serviceProviders, function ($key, $value) use($name) {
                return get_class($value) == $name;
            });
        }
    }
    public function resolveProviderClass($provider)
    {
        return new $provider($this);
    }
    protected function markAsRegistered($provider)
    {
        $this['events']->fire($class = get_class($provider), array($provider));
        $this->serviceProviders[] = $provider;
        $this->loadedProviders[$class] = true;
    }
    public function loadDeferredProviders()
    {
        foreach ($this->deferredServices as $service => $provider) {
            $this->loadDeferredProvider($service);
        }
        $this->deferredServices = array();
    }
    protected function loadDeferredProvider($service)
    {
        $provider = $this->deferredServices[$service];
        if (!isset($this->loadedProviders[$provider])) {
            $this->registerDeferredProvider($provider, $service);
        }
    }
    public function registerDeferredProvider($provider, $service = null)
    {
        if ($service) {
            unset($this->deferredServices[$service]);
        }
        $this->register($instance = new $provider($this));
        if (!$this->booted) {
            $this->booting(function () use($instance) {
                $instance->boot();
            });
        }
    }
    public function make($abstract, $parameters = array())
    {
        $abstract = $this->getAlias($abstract);
        if (isset($this->deferredServices[$abstract])) {
            $this->loadDeferredProvider($abstract);
        }
        return parent::make($abstract, $parameters);
    }
    public function before($callback)
    {
        return $this['router']->before($callback);
    }
    public function after($callback)
    {
        return $this['router']->after($callback);
    }
    public function finish($callback)
    {
        $this->finishCallbacks[] = $callback;
    }
    public function shutdown($callback = null)
    {
        if (is_null($callback)) {
            $this->fireAppCallbacks($this->shutdownCallbacks);
        } else {
            $this->shutdownCallbacks[] = $callback;
        }
    }
    public function useArraySessions(Closure $callback)
    {
        $this->bind('session.reject', function () use($callback) {
            return $callback;
        });
    }
    public function isBooted()
    {
        return $this->booted;
    }
    public function boot()
    {
        if ($this->booted) {
            return;
        }
        array_walk($this->serviceProviders, function ($p) {
            $p->boot();
        });
        $this->bootApplication();
    }
    protected function bootApplication()
    {
        $this->fireAppCallbacks($this->bootingCallbacks);
        $this->booted = true;
        $this->fireAppCallbacks($this->bootedCallbacks);
    }
    public function booting($callback)
    {
        $this->bootingCallbacks[] = $callback;
    }
    public function booted($callback)
    {
        $this->bootedCallbacks[] = $callback;
        if ($this->isBooted()) {
            $this->fireAppCallbacks(array($callback));
        }
    }
    public function run(SymfonyRequest $request = null)
    {
        $request = $request ?: $this['request'];
        $response = with($stack = $this->getStackedClient())->handle($request);
        $response->send();
        $stack->terminate($request, $response);
    }
    protected function getStackedClient()
    {
        $sessionReject = $this->bound('session.reject') ? $this['session.reject'] : null;
        $client = with(new Builder())->push('Royalcms\\Component\\Cookie\\Guard', $this['encrypter'])->push('Royalcms\\Component\\Cookie\\Queue', $this['cookie']);
        $this->mergeCustomMiddlewares($client);
        return $client->resolve($this);
    }
    protected function mergeCustomMiddlewares(Builder $stack)
    {
        foreach ($this->middlewares as $middleware) {
            list($class, $parameters) = array_values($middleware);
            array_unshift($parameters, $class);
            call_user_func_array(array($stack, 'push'), $parameters);
        }
    }
    protected function registerBaseMiddlewares()
    {
        $this->middleware('Royalcms\\Component\\HttpKernel\\FrameGuard');
    }
    public function middleware($class, array $parameters = array())
    {
        $this->middlewares[] = compact('class', 'parameters');
        return $this;
    }
    public function forgetMiddleware($class)
    {
        $this->middlewares = array_filter($this->middlewares, function ($m) use($class) {
            return $m['class'] != $class;
        });
    }
    public function handle(SymfonyRequest $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        try {
            $this->refreshRequest($request = Request::createFromBase($request));
            $this->boot();
            return $this->dispatch($request);
        } catch (\Exception $e) {
            if ($this->runningUnitTests()) {
                throw $e;
            }
            return $this['exception']->handleException($e);
        }
    }
    public function dispatch(Request $request)
    {
        if ($this->isDownForMaintenance()) {
            $response = $this['events']->until('royalcms.app.down');
            if (!is_null($response)) {
                return $this->prepareResponse($response, $request);
            }
        }
        if ($this->runningUnitTests() && !$this['session']->isStarted()) {
            $this['session']->start();
        }
        return $this['router']->dispatch($this->prepareRequest($request));
    }
    public function terminate(SymfonyRequest $request, SymfonyResponse $response)
    {
        $this->callFinishCallbacks($request, $response);
        $this->shutdown();
    }
    protected function refreshRequest(Request $request)
    {
        $this->instance('request', $request);
        Facade::clearResolvedInstance('request');
    }
    public function callFinishCallbacks(SymfonyRequest $request, SymfonyResponse $response)
    {
        foreach ($this->finishCallbacks as $callback) {
            call_user_func($callback, $request, $response);
        }
    }
    protected function fireAppCallbacks(array $callbacks)
    {
        foreach ($callbacks as $callback) {
            call_user_func($callback, $this);
        }
    }
    public function prepareRequest(Request $request)
    {
        return $request;
    }
    public function prepareResponse($value)
    {
        if (!$value instanceof SymfonyResponse) {
            $value = new Response($value);
        }
        return $value->prepare($this['request']);
    }
    public function readyForResponses()
    {
        return $this->booted;
    }
    public function isDownForMaintenance()
    {
        return file_exists($this['path.storage'] . '/meta/down');
    }
    public function down(Closure $callback)
    {
        $this['events']->listen('royalcms.app.down', $callback);
    }
    public function abort($code, $message = '', array $headers = array())
    {
        if ($code == 404) {
            throw new NotFoundHttpException($message);
        } else {
            throw new HttpException($code, $message, null, $headers);
        }
    }
    public function missing(Closure $callback)
    {
        $this->error(function (NotFoundHttpException $e) use($callback) {
            return call_user_func($callback, $e);
        });
    }
    public function error(Closure $callback)
    {
        $this['exception']->error($callback);
    }
    public function pushError(Closure $callback)
    {
        $this['exception']->pushError($callback);
    }
    public function fatal(Closure $callback)
    {
        $this->error(function (FatalErrorException $e) use($callback) {
            return call_user_func($callback, $e);
        });
    }
    public function getConfigLoader()
    {
        return new FileLoader(new Filesystem(), $this['path'] . '/configs', $this['path.content'] . '/configs');
    }
    public function getEnvironmentVariablesLoader()
    {
        return new FileEnvironmentVariablesLoader(new Filesystem(), $this['path.base']);
    }
    public function getProviderRepository()
    {
        $manifest = $this['config']['system.manifest'];
        return new ProviderRepository(new Filesystem(), $manifest);
    }
    public function getLoadedProviders()
    {
        return $this->loadedProviders;
    }
    public function setDeferredServices(array $services)
    {
        $this->deferredServices = $services;
    }
    public function isDeferredService($service)
    {
        return isset($this->deferredServices[$service]);
    }
    public static function requestClass($class = null)
    {
        if (!is_null($class)) {
            static::$requestClass = $class;
        }
        return static::$requestClass;
    }
    public function setRequestForConsoleEnvironment()
    {
        $url = $this['config']->get('system.url', 'http://localhost');
        $parameters = array($url, 'GET', array(), array(), array(), $_SERVER);
        $this->refreshRequest(static::onRequest('create', $parameters));
    }
    public static function onRequest($method, $parameters = array())
    {
        return forward_static_call_array(array(static::requestClass(), $method), $parameters);
    }
    public function getLocale()
    {
        return $this['config']->get('system.locale');
    }
    public function setLocale($locale)
    {
        $this['config']->set('system.locale', $locale);
        $this['translator']->setLocale($locale);
        $this['events']->fire('locale.changed', array($locale));
    }
    public function registerCoreContainerAliases()
    {
        $aliases = array('royalcms' => 'Royalcms\\Component\\Foundation\\Royalcms', 'royalcmd' => 'Royalcms\\Component\\Console\\Royalcmd', 'config' => 'Royalcms\\Component\\Config\\Repository', 'files' => 'Royalcms\\Component\\Filesystem\\Filesystem', 'filesystem' => 'Royalcms\\Component\\Filesystem\\FilesystemManager', 'filesystem.disk' => 'Royalcms\\Component\\Support\\Contracts\\Filesystem\\Filesystem', 'filesystem.cloud' => 'Royalcms\\Component\\Support\\Contracts\\Filesystem\\Cloud', 'filesystemkernel' => 'Royalcms\\Component\\FilesystemKernel\\FilesystemManager', 'filesystemkernel.disk' => 'Royalcms\\Component\\FilesystemKernel\\FilesystemBase', 'filesystemkernel.cloud' => 'Royalcms\\Component\\FilesystemKernel\\FilesystemBase', 'cache' => 'Royalcms\\Component\\Cache\\CacheManager', 'cache.store' => 'Royalcms\\Component\\Cache\\Repository', 'log' => 'Royalcms\\Component\\Log\\Writer', 'log.store' => 'Royalcms\\Component\\Log\\FileStore', 'error' => 'Royalcms\\Component\\Error\\Error', 'cookie' => 'Royalcms\\Component\\Cookie\\CookieJar', 'session' => 'Royalcms\\Component\\Session\\SessionManager', 'session.store' => 'Royalcms\\Component\\Session\\Store', 'request' => 'Royalcms\\Component\\HttpKernel\\Request', 'response' => 'Royalcms\\Component\\HttpKernel\\Response', 'view' => 'Royalcms\\Component\\View\\Environment', 'events' => 'Royalcms\\Component\\Events\\Dispatcher', 'hook' => 'Royalcms\\Component\\Hook\\Hooks', 'phpinfo' => 'Royalcms\\Component\\Foundation\\Phpinfo', 'router' => 'Royalcms\\Component\\Routing\\Router', 'redirect' => 'Royalcms\\Component\\Routing\\Redirector', 'url' => 'Royalcms\\Component\\Routing\\UrlGenerator', 'translator' => 'Royalcms\\Component\\Translation\\Translator', 'package' => 'Royalcms\\Component\\Package\\PackageManager', 'encrypter' => 'Royalcms\\Component\\Encryption\\Encrypter', 'db' => 'Royalcms\\Component\\Database\\DatabaseManager', 'hash' => 'Royalcms\\Component\\Hashing\\HasherInterface', 'mailer' => 'Royalcms\\Component\\Mail\\Mailer', 'paginator' => 'Royalcms\\Component\\Pagination\\Environment', 'queue' => 'Royalcms\\Component\\Queue\\QueueManager', 'redis' => 'Royalcms\\Component\\Redis\\Database', 'remote' => 'Royalcms\\Component\\Remote\\RemoteManager', 'validator' => 'Royalcms\\Component\\Validation\\Factory');
        foreach ($aliases as $key => $alias) {
            $this->alias($key, $alias);
        }
    }
    public function __get($key)
    {
        return $this[$key];
    }
    public function __set($key, $value)
    {
        $this[$key] = $value;
    }
    public function start()
    {
        $this->run();
    }
}
namespace Royalcms\Component\Foundation;

use Closure;
class EnvironmentDetector
{
    public function detect($environments, $consoleArgs = null)
    {
        if ($consoleArgs) {
            return $this->detectConsoleEnvironment($environments, $consoleArgs);
        } else {
            return $this->detectWebEnvironment($environments);
        }
    }
    protected function detectWebEnvironment($environments)
    {
        if ($environments instanceof Closure) {
            return call_user_func($environments);
        }
        foreach ($environments as $environment => $hosts) {
            foreach ((array) $hosts as $host) {
                if ($this->isMachine($host)) {
                    return $environment;
                }
            }
        }
        return 'production';
    }
    protected function detectConsoleEnvironment($environments, array $args)
    {
        if (!is_null($value = $this->getEnvironmentArgument($args))) {
            return head(array_slice(explode('=', $value), 1));
        } else {
            return $this->detectWebEnvironment($environments);
        }
    }
    protected function getEnvironmentArgument(array $args)
    {
        return array_first($args, function ($k, $v) {
            return starts_with($v, '--env');
        });
    }
    public function isMachine($name)
    {
        return str_is($name, gethostname());
    }
}
namespace Royalcms\Component\HttpKernel;

use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
class Request extends SymfonyRequest
{
    protected $json;
    protected $sessionStore;
    public function instance()
    {
        return $this;
    }
    public function method()
    {
        return $this->getMethod();
    }
    public function root()
    {
        return rtrim($this->getSchemeAndHttpHost() . $this->getBaseUrl(), '/');
    }
    public function url()
    {
        return rtrim(preg_replace('/\\?.*/', '', $this->getUri()), '/');
    }
    public function fullUrl()
    {
        $query = $this->getQueryString();
        return $query ? $this->url() . '?' . $query : $this->url();
    }
    public function path()
    {
        $pattern = trim($this->getPathInfo(), '/');
        return $pattern == '' ? '/' : $pattern;
    }
    public function decodedPath()
    {
        return rawurldecode($this->path());
    }
    public function segment($index, $default = null)
    {
        return array_get($this->segments(), $index - 1, $default);
    }
    public function segments()
    {
        $segments = explode('/', $this->path());
        return array_values(array_filter($segments, function ($v) {
            return $v != '';
        }));
    }
    public function is()
    {
        foreach (func_get_args() as $pattern) {
            if (str_is($pattern, urldecode($this->path()))) {
                return true;
            }
        }
        return false;
    }
    public function ajax()
    {
        return $this->isXmlHttpRequest();
    }
    public function secure()
    {
        return $this->isSecure();
    }
    public function exists($key)
    {
        $keys = is_array($key) ? $key : func_get_args();
        $input = $this->all();
        foreach ($keys as $value) {
            if (!array_key_exists($value, $input)) {
                return false;
            }
        }
        return true;
    }
    public function has($key)
    {
        $keys = is_array($key) ? $key : func_get_args();
        foreach ($keys as $value) {
            if ($this->isEmptyString($value)) {
                return false;
            }
        }
        return true;
    }
    protected function isEmptyString($key)
    {
        $boolOrArray = is_bool($this->input($key)) || is_array($this->input($key));
        return !$boolOrArray && trim((string) $this->input($key)) === '';
    }
    public function all()
    {
        return array_merge_recursive($this->input(), $this->files->all());
    }
    public function input($key = null, $default = null)
    {
        $input = $this->getInputSource()->all() + $this->query->all();
        return array_get($input, $key, $default);
    }
    public function only($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        return array_only($this->input(), $keys) + array_fill_keys($keys, null);
    }
    public function except($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $results = $this->input();
        foreach ($keys as $key) {
            array_forget($results, $key);
        }
        return $results;
    }
    public function query($key = null, $default = null)
    {
        return $this->retrieveItem('query', $key, $default);
    }
    public function hasCookie($key)
    {
        return !is_null($this->cookie($key));
    }
    public function cookie($key = null, $default = null)
    {
        return $this->retrieveItem('cookies', $key, $default);
    }
    public function file($key = null, $default = null)
    {
        return array_get($this->files->all(), $key, $default);
    }
    public function hasFile($key)
    {
        if (is_array($file = $this->file($key))) {
            $file = head($file);
        }
        return $file instanceof \SplFileInfo && $file->getPath() != '';
    }
    public function header($key = null, $default = null)
    {
        return $this->retrieveItem('headers', $key, $default);
    }
    public function server($key = null, $default = null)
    {
        return $this->retrieveItem('server', $key, $default);
    }
    public function old($key = null, $default = null)
    {
        return $this->session()->getOldInput($key, $default);
    }
    public function flash($filter = null, $keys = array())
    {
        $flash = !is_null($filter) ? $this->{$filter}($keys) : $this->input();
        $this->session()->flashInput($flash);
    }
    public function flashOnly($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        return $this->flash('only', $keys);
    }
    public function flashExcept($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        return $this->flash('except', $keys);
    }
    public function flush()
    {
        $this->session()->flashInput(array());
    }
    protected function retrieveItem($source, $key, $default)
    {
        if (is_null($key)) {
            return $this->{$source}->all();
        } else {
            return $this->{$source}->get($key, $default, true);
        }
    }
    public function merge(array $input)
    {
        $this->getInputSource()->add($input);
    }
    public function replace(array $input)
    {
        $this->getInputSource()->replace($input);
    }
    public function json($key = null, $default = null)
    {
        if (!isset($this->json)) {
            $this->json = new ParameterBag((array) json_decode($this->getContent(), true));
        }
        if (is_null($key)) {
            return $this->json;
        }
        return array_get($this->json->all(), $key, $default);
    }
    protected function getInputSource()
    {
        if ($this->isJson()) {
            return $this->json();
        }
        return $this->getMethod() == 'GET' ? $this->query : $this->request;
    }
    public function isJson()
    {
        return str_contains($this->header('CONTENT_TYPE'), '/json');
    }
    public function wantsJson()
    {
        $acceptable = $this->getAcceptableContentTypes();
        return isset($acceptable[0]) && $acceptable[0] == 'application/json';
    }
    public function format($default = 'html')
    {
        foreach ($this->getAcceptableContentTypes() as $type) {
            if (false !== ($format = $this->getFormat($type))) {
                return $format;
            }
        }
        return $default;
    }
    public static function createFromBase(SymfonyRequest $request)
    {
        if ($request instanceof static) {
            return $request;
        }
        return with(new static())->duplicate($request->query->all(), $request->request->all(), $request->attributes->all(), $request->cookies->all(), $request->files->all(), $request->server->all());
    }
    public function session()
    {
        if (!$this->hasSession()) {
            throw new \RuntimeException('Session store not set on request.');
        }
        return $this->getSession();
    }
}
namespace Royalcms\Component\HttpKernel;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
class FrameGuard implements HttpKernelInterface
{
    protected $royalcms;
    public function __construct(HttpKernelInterface $royalcms)
    {
        $this->royalcms = $royalcms;
    }
    public function handle(SymfonyRequest $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        $response = $this->royalcms->handle($request, $type, $catch);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        return $response;
    }
}
namespace Symfony\Component\HttpFoundation;

use Symfony\Component\HttpFoundation\Session\SessionInterface;
class Request
{
    const HEADER_CLIENT_IP = 'client_ip';
    const HEADER_CLIENT_HOST = 'client_host';
    const HEADER_CLIENT_PROTO = 'client_proto';
    const HEADER_CLIENT_PORT = 'client_port';
    protected static $trustedProxies = array();
    protected static $trustedHostPatterns = array();
    protected static $trustedHosts = array();
    protected static $trustedHeaders = array(self::HEADER_CLIENT_IP => 'X_FORWARDED_FOR', self::HEADER_CLIENT_HOST => 'X_FORWARDED_HOST', self::HEADER_CLIENT_PROTO => 'X_FORWARDED_PROTO', self::HEADER_CLIENT_PORT => 'X_FORWARDED_PORT');
    protected static $httpMethodParameterOverride = false;
    public $attributes;
    public $request;
    public $query;
    public $server;
    public $files;
    public $cookies;
    public $headers;
    protected $content;
    protected $languages;
    protected $charsets;
    protected $encodings;
    protected $acceptableContentTypes;
    protected $pathInfo;
    protected $requestUri;
    protected $baseUrl;
    protected $basePath;
    protected $method;
    protected $format;
    protected $session;
    protected $locale;
    protected $defaultLocale = 'en';
    protected static $formats;
    protected static $requestFactory;
    public function __construct(array $query = array(), array $request = array(), array $attributes = array(), array $cookies = array(), array $files = array(), array $server = array(), $content = null)
    {
        $this->initialize($query, $request, $attributes, $cookies, $files, $server, $content);
    }
    public function initialize(array $query = array(), array $request = array(), array $attributes = array(), array $cookies = array(), array $files = array(), array $server = array(), $content = null)
    {
        $this->request = new ParameterBag($request);
        $this->query = new ParameterBag($query);
        $this->attributes = new ParameterBag($attributes);
        $this->cookies = new ParameterBag($cookies);
        $this->files = new FileBag($files);
        $this->server = new ServerBag($server);
        $this->headers = new HeaderBag($this->server->getHeaders());
        $this->content = $content;
        $this->languages = null;
        $this->charsets = null;
        $this->encodings = null;
        $this->acceptableContentTypes = null;
        $this->pathInfo = null;
        $this->requestUri = null;
        $this->baseUrl = null;
        $this->basePath = null;
        $this->method = null;
        $this->format = null;
    }
    public static function createFromGlobals()
    {
        $request = self::createRequestFromFactory($_GET, $_POST, array(), $_COOKIE, $_FILES, $_SERVER);
        if (0 === strpos($request->headers->get('CONTENT_TYPE'), 'application/x-www-form-urlencoded') && in_array(strtoupper($request->server->get('REQUEST_METHOD', 'GET')), array('PUT', 'DELETE', 'PATCH'))) {
            parse_str($request->getContent(), $data);
            $request->request = new ParameterBag($data);
        }
        return $request;
    }
    public static function create($uri, $method = 'GET', $parameters = array(), $cookies = array(), $files = array(), $server = array(), $content = null)
    {
        $server = array_replace(array('SERVER_NAME' => 'localhost', 'SERVER_PORT' => 80, 'HTTP_HOST' => 'localhost', 'HTTP_USER_AGENT' => 'Symfony/2.X', 'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'HTTP_ACCEPT_LANGUAGE' => 'en-us,en;q=0.5', 'HTTP_ACCEPT_CHARSET' => 'ISO-8859-1,utf-8;q=0.7,*;q=0.7', 'REMOTE_ADDR' => '127.0.0.1', 'SCRIPT_NAME' => '', 'SCRIPT_FILENAME' => '', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REQUEST_TIME' => time()), $server);
        $server['PATH_INFO'] = '';
        $server['REQUEST_METHOD'] = strtoupper($method);
        $components = parse_url($uri);
        if (isset($components['host'])) {
            $server['SERVER_NAME'] = $components['host'];
            $server['HTTP_HOST'] = $components['host'];
        }
        if (isset($components['scheme'])) {
            if ('https' === $components['scheme']) {
                $server['HTTPS'] = 'on';
                $server['SERVER_PORT'] = 443;
            } else {
                unset($server['HTTPS']);
                $server['SERVER_PORT'] = 80;
            }
        }
        if (isset($components['port'])) {
            $server['SERVER_PORT'] = $components['port'];
            $server['HTTP_HOST'] = $server['HTTP_HOST'] . ':' . $components['port'];
        }
        if (isset($components['user'])) {
            $server['PHP_AUTH_USER'] = $components['user'];
        }
        if (isset($components['pass'])) {
            $server['PHP_AUTH_PW'] = $components['pass'];
        }
        if (!isset($components['path'])) {
            $components['path'] = '/';
        }
        switch (strtoupper($method)) {
            case 'POST':
            case 'PUT':
            case 'DELETE':
                if (!isset($server['CONTENT_TYPE'])) {
                    $server['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
                }
            case 'PATCH':
                $request = $parameters;
                $query = array();
                break;
            default:
                $request = array();
                $query = $parameters;
                break;
        }
        $queryString = '';
        if (isset($components['query'])) {
            parse_str(html_entity_decode($components['query']), $qs);
            if ($query) {
                $query = array_replace($qs, $query);
                $queryString = http_build_query($query, '', '&');
            } else {
                $query = $qs;
                $queryString = $components['query'];
            }
        } elseif ($query) {
            $queryString = http_build_query($query, '', '&');
        }
        $server['REQUEST_URI'] = $components['path'] . ('' !== $queryString ? '?' . $queryString : '');
        $server['QUERY_STRING'] = $queryString;
        return self::createRequestFromFactory($query, $request, array(), $cookies, $files, $server, $content);
    }
    public static function setFactory($callable)
    {
        self::$requestFactory = $callable;
    }
    public function duplicate(array $query = null, array $request = null, array $attributes = null, array $cookies = null, array $files = null, array $server = null)
    {
        $dup = clone $this;
        if ($query !== null) {
            $dup->query = new ParameterBag($query);
        }
        if ($request !== null) {
            $dup->request = new ParameterBag($request);
        }
        if ($attributes !== null) {
            $dup->attributes = new ParameterBag($attributes);
        }
        if ($cookies !== null) {
            $dup->cookies = new ParameterBag($cookies);
        }
        if ($files !== null) {
            $dup->files = new FileBag($files);
        }
        if ($server !== null) {
            $dup->server = new ServerBag($server);
            $dup->headers = new HeaderBag($dup->server->getHeaders());
        }
        $dup->languages = null;
        $dup->charsets = null;
        $dup->encodings = null;
        $dup->acceptableContentTypes = null;
        $dup->pathInfo = null;
        $dup->requestUri = null;
        $dup->baseUrl = null;
        $dup->basePath = null;
        $dup->method = null;
        $dup->format = null;
        if (!$dup->get('_format') && $this->get('_format')) {
            $dup->attributes->set('_format', $this->get('_format'));
        }
        if (!$dup->getRequestFormat(null)) {
            $dup->setRequestFormat($format = $this->getRequestFormat(null));
        }
        return $dup;
    }
    public function __clone()
    {
        $this->query = clone $this->query;
        $this->request = clone $this->request;
        $this->attributes = clone $this->attributes;
        $this->cookies = clone $this->cookies;
        $this->files = clone $this->files;
        $this->server = clone $this->server;
        $this->headers = clone $this->headers;
    }
    public function __toString()
    {
        return sprintf('%s %s %s', $this->getMethod(), $this->getRequestUri(), $this->server->get('SERVER_PROTOCOL')) . '
' . $this->headers . '
' . $this->getContent();
    }
    public function overrideGlobals()
    {
        $this->server->set('QUERY_STRING', static::normalizeQueryString(http_build_query($this->query->all(), null, '&')));
        $_GET = $this->query->all();
        $_POST = $this->request->all();
        $_SERVER = $this->server->all();
        $_COOKIE = $this->cookies->all();
        foreach ($this->headers->all() as $key => $value) {
            $key = strtoupper(str_replace('-', '_', $key));
            if (in_array($key, array('CONTENT_TYPE', 'CONTENT_LENGTH'))) {
                $_SERVER[$key] = implode(', ', $value);
            } else {
                $_SERVER['HTTP_' . $key] = implode(', ', $value);
            }
        }
        $request = array('g' => $_GET, 'p' => $_POST, 'c' => $_COOKIE);
        $requestOrder = ini_get('request_order') ?: ini_get('variables_order');
        $requestOrder = preg_replace('#[^cgp]#', '', strtolower($requestOrder)) ?: 'gp';
        $_REQUEST = array();
        foreach (str_split($requestOrder) as $order) {
            $_REQUEST = array_merge($_REQUEST, $request[$order]);
        }
    }
    public static function setTrustedProxies(array $proxies)
    {
        self::$trustedProxies = $proxies;
    }
    public static function getTrustedProxies()
    {
        return self::$trustedProxies;
    }
    public static function setTrustedHosts(array $hostPatterns)
    {
        self::$trustedHostPatterns = array_map(function ($hostPattern) {
            return sprintf('{%s}i', str_replace('}', '\\}', $hostPattern));
        }, $hostPatterns);
        self::$trustedHosts = array();
    }
    public static function getTrustedHosts()
    {
        return self::$trustedHostPatterns;
    }
    public static function setTrustedHeaderName($key, $value)
    {
        if (!array_key_exists($key, self::$trustedHeaders)) {
            throw new \InvalidArgumentException(sprintf('Unable to set the trusted header name for key "%s".', $key));
        }
        self::$trustedHeaders[$key] = $value;
    }
    public static function getTrustedHeaderName($key)
    {
        if (!array_key_exists($key, self::$trustedHeaders)) {
            throw new \InvalidArgumentException(sprintf('Unable to get the trusted header name for key "%s".', $key));
        }
        return self::$trustedHeaders[$key];
    }
    public static function normalizeQueryString($qs)
    {
        if ('' == $qs) {
            return '';
        }
        $parts = array();
        $order = array();
        foreach (explode('&', $qs) as $param) {
            if ('' === $param || '=' === $param[0]) {
                continue;
            }
            $keyValuePair = explode('=', $param, 2);
            $parts[] = isset($keyValuePair[1]) ? rawurlencode(urldecode($keyValuePair[0])) . '=' . rawurlencode(urldecode($keyValuePair[1])) : rawurlencode(urldecode($keyValuePair[0]));
            $order[] = urldecode($keyValuePair[0]);
        }
        array_multisort($order, SORT_ASC, $parts);
        return implode('&', $parts);
    }
    public static function enableHttpMethodParameterOverride()
    {
        self::$httpMethodParameterOverride = true;
    }
    public static function getHttpMethodParameterOverride()
    {
        return self::$httpMethodParameterOverride;
    }
    public function get($key, $default = null, $deep = false)
    {
        return $this->query->get($key, $this->attributes->get($key, $this->request->get($key, $default, $deep), $deep), $deep);
    }
    public function getSession()
    {
        return $this->session;
    }
    public function hasPreviousSession()
    {
        return $this->hasSession() && $this->cookies->has($this->session->getName());
    }
    public function hasSession()
    {
        return null !== $this->session;
    }
    public function setSession(SessionInterface $session)
    {
        $this->session = $session;
    }
    public function getClientIps()
    {
        $ip = $this->server->get('REMOTE_ADDR');
        if (!self::$trustedProxies) {
            return array($ip);
        }
        if (!self::$trustedHeaders[self::HEADER_CLIENT_IP] || !$this->headers->has(self::$trustedHeaders[self::HEADER_CLIENT_IP])) {
            return array($ip);
        }
        $clientIps = array_map('trim', explode(',', $this->headers->get(self::$trustedHeaders[self::HEADER_CLIENT_IP])));
        $clientIps[] = $ip;
        $ip = $clientIps[0];
        foreach ($clientIps as $key => $clientIp) {
            if (IpUtils::checkIp($clientIp, self::$trustedProxies)) {
                unset($clientIps[$key]);
            }
        }
        return $clientIps ? array_reverse($clientIps) : array($ip);
    }
    public function getClientIp()
    {
        $ipAddresses = $this->getClientIps();
        return $ipAddresses[0];
    }
    public function getScriptName()
    {
        return $this->server->get('SCRIPT_NAME', $this->server->get('ORIG_SCRIPT_NAME', ''));
    }
    public function getPathInfo()
    {
        if (null === $this->pathInfo) {
            $this->pathInfo = $this->preparePathInfo();
        }
        return $this->pathInfo;
    }
    public function getBasePath()
    {
        if (null === $this->basePath) {
            $this->basePath = $this->prepareBasePath();
        }
        return $this->basePath;
    }
    public function getBaseUrl()
    {
        if (null === $this->baseUrl) {
            $this->baseUrl = $this->prepareBaseUrl();
        }
        return $this->baseUrl;
    }
    public function getScheme()
    {
        return $this->isSecure() ? 'https' : 'http';
    }
    public function getPort()
    {
        if (self::$trustedProxies) {
            if (self::$trustedHeaders[self::HEADER_CLIENT_PORT] && ($port = $this->headers->get(self::$trustedHeaders[self::HEADER_CLIENT_PORT]))) {
                return $port;
            }
            if (self::$trustedHeaders[self::HEADER_CLIENT_PROTO] && 'https' === $this->headers->get(self::$trustedHeaders[self::HEADER_CLIENT_PROTO], 'http')) {
                return 443;
            }
        }
        if ($host = $this->headers->get('HOST')) {
            if ($host[0] === '[') {
                $pos = strpos($host, ':', strrpos($host, ']'));
            } else {
                $pos = strrpos($host, ':');
            }
            if (false !== $pos) {
                return intval(substr($host, $pos + 1));
            }
            return 'https' === $this->getScheme() ? 443 : 80;
        }
        return $this->server->get('SERVER_PORT');
    }
    public function getUser()
    {
        return $this->headers->get('PHP_AUTH_USER');
    }
    public function getPassword()
    {
        return $this->headers->get('PHP_AUTH_PW');
    }
    public function getUserInfo()
    {
        $userinfo = $this->getUser();
        $pass = $this->getPassword();
        if ('' != $pass) {
            $userinfo .= ":{$pass}";
        }
        return $userinfo;
    }
    public function getHttpHost()
    {
        $scheme = $this->getScheme();
        $port = $this->getPort();
        if ('http' == $scheme && $port == 80 || 'https' == $scheme && $port == 443) {
            return $this->getHost();
        }
        return $this->getHost() . ':' . $port;
    }
    public function getRequestUri()
    {
        if (null === $this->requestUri) {
            $this->requestUri = $this->prepareRequestUri();
        }
        return $this->requestUri;
    }
    public function getSchemeAndHttpHost()
    {
        return $this->getScheme() . '://' . $this->getHttpHost();
    }
    public function getUri()
    {
        if (null !== ($qs = $this->getQueryString())) {
            $qs = '?' . $qs;
        }
        return $this->getSchemeAndHttpHost() . $this->getBaseUrl() . $this->getPathInfo() . $qs;
    }
    public function getUriForPath($path)
    {
        return $this->getSchemeAndHttpHost() . $this->getBaseUrl() . $path;
    }
    public function getQueryString()
    {
        $qs = static::normalizeQueryString($this->server->get('QUERY_STRING'));
        return '' === $qs ? null : $qs;
    }
    public function isSecure()
    {
        if (self::$trustedProxies && self::$trustedHeaders[self::HEADER_CLIENT_PROTO] && ($proto = $this->headers->get(self::$trustedHeaders[self::HEADER_CLIENT_PROTO]))) {
            return in_array(strtolower(current(explode(',', $proto))), array('https', 'on', 'ssl', '1'));
        }
        $https = $this->server->get('HTTPS');
        return !empty($https) && 'off' !== strtolower($https);
    }
    public function getHost()
    {
        if (self::$trustedProxies && self::$trustedHeaders[self::HEADER_CLIENT_HOST] && ($host = $this->headers->get(self::$trustedHeaders[self::HEADER_CLIENT_HOST]))) {
            $elements = explode(',', $host);
            $host = $elements[count($elements) - 1];
        } elseif (!($host = $this->headers->get('HOST'))) {
            if (!($host = $this->server->get('SERVER_NAME'))) {
                $host = $this->server->get('SERVER_ADDR', '');
            }
        }
        $host = strtolower(preg_replace('/:\\d+$/', '', trim($host)));
        if ($host && '' !== preg_replace('/(?:^\\[)?[a-zA-Z0-9-:\\]_]+\\.?/', '', $host)) {
            throw new \UnexpectedValueException(sprintf('Invalid Host "%s"', $host));
        }
        if (count(self::$trustedHostPatterns) > 0) {
            if (in_array($host, self::$trustedHosts)) {
                return $host;
            }
            foreach (self::$trustedHostPatterns as $pattern) {
                if (preg_match($pattern, $host)) {
                    self::$trustedHosts[] = $host;
                    return $host;
                }
            }
            throw new \UnexpectedValueException(sprintf('Untrusted Host "%s"', $host));
        }
        return $host;
    }
    public function setMethod($method)
    {
        $this->method = null;
        $this->server->set('REQUEST_METHOD', $method);
    }
    public function getMethod()
    {
        if (null === $this->method) {
            $this->method = strtoupper($this->server->get('REQUEST_METHOD', 'GET'));
            if ('POST' === $this->method) {
                if ($method = $this->headers->get('X-HTTP-METHOD-OVERRIDE')) {
                    $this->method = strtoupper($method);
                } elseif (self::$httpMethodParameterOverride) {
                    $this->method = strtoupper($this->request->get('_method', $this->query->get('_method', 'POST')));
                }
            }
        }
        return $this->method;
    }
    public function getRealMethod()
    {
        return strtoupper($this->server->get('REQUEST_METHOD', 'GET'));
    }
    public function getMimeType($format)
    {
        if (null === static::$formats) {
            static::initializeFormats();
        }
        return isset(static::$formats[$format]) ? static::$formats[$format][0] : null;
    }
    public function getFormat($mimeType)
    {
        if (false !== ($pos = strpos($mimeType, ';'))) {
            $mimeType = substr($mimeType, 0, $pos);
        }
        if (null === static::$formats) {
            static::initializeFormats();
        }
        foreach (static::$formats as $format => $mimeTypes) {
            if (in_array($mimeType, (array) $mimeTypes)) {
                return $format;
            }
        }
    }
    public function setFormat($format, $mimeTypes)
    {
        if (null === static::$formats) {
            static::initializeFormats();
        }
        static::$formats[$format] = is_array($mimeTypes) ? $mimeTypes : array($mimeTypes);
    }
    public function getRequestFormat($default = 'html')
    {
        if (null === $this->format) {
            $this->format = $this->get('_format', $default);
        }
        return $this->format;
    }
    public function setRequestFormat($format)
    {
        $this->format = $format;
    }
    public function getContentType()
    {
        return $this->getFormat($this->headers->get('CONTENT_TYPE'));
    }
    public function setDefaultLocale($locale)
    {
        $this->defaultLocale = $locale;
        if (null === $this->locale) {
            $this->setPhpDefaultLocale($locale);
        }
    }
    public function getDefaultLocale()
    {
        return $this->defaultLocale;
    }
    public function setLocale($locale)
    {
        $this->setPhpDefaultLocale($this->locale = $locale);
    }
    public function getLocale()
    {
        return null === $this->locale ? $this->defaultLocale : $this->locale;
    }
    public function isMethod($method)
    {
        return $this->getMethod() === strtoupper($method);
    }
    public function isMethodSafe()
    {
        return in_array($this->getMethod(), array('GET', 'HEAD'));
    }
    public function getContent($asResource = false)
    {
        if (false === $this->content || true === $asResource && null !== $this->content) {
            throw new \LogicException('getContent() can only be called once when using the resource return type.');
        }
        if (true === $asResource) {
            $this->content = false;
            return fopen('php://input', 'rb');
        }
        if (null === $this->content) {
            $this->content = file_get_contents('php://input');
        }
        return $this->content;
    }
    public function getETags()
    {
        return preg_split('/\\s*,\\s*/', $this->headers->get('if_none_match'), null, PREG_SPLIT_NO_EMPTY);
    }
    public function isNoCache()
    {
        return $this->headers->hasCacheControlDirective('no-cache') || 'no-cache' == $this->headers->get('Pragma');
    }
    public function getPreferredLanguage(array $locales = null)
    {
        $preferredLanguages = $this->getLanguages();
        if (empty($locales)) {
            return isset($preferredLanguages[0]) ? $preferredLanguages[0] : null;
        }
        if (!$preferredLanguages) {
            return $locales[0];
        }
        $extendedPreferredLanguages = array();
        foreach ($preferredLanguages as $language) {
            $extendedPreferredLanguages[] = $language;
            if (false !== ($position = strpos($language, '_'))) {
                $superLanguage = substr($language, 0, $position);
                if (!in_array($superLanguage, $preferredLanguages)) {
                    $extendedPreferredLanguages[] = $superLanguage;
                }
            }
        }
        $preferredLanguages = array_values(array_intersect($extendedPreferredLanguages, $locales));
        return isset($preferredLanguages[0]) ? $preferredLanguages[0] : $locales[0];
    }
    public function getLanguages()
    {
        if (null !== $this->languages) {
            return $this->languages;
        }
        $languages = AcceptHeader::fromString($this->headers->get('Accept-Language'))->all();
        $this->languages = array();
        foreach (array_keys($languages) as $lang) {
            if (strstr($lang, '-')) {
                $codes = explode('-', $lang);
                if ($codes[0] == 'i') {
                    if (count($codes) > 1) {
                        $lang = $codes[1];
                    }
                } else {
                    for ($i = 0, $max = count($codes); $i < $max; $i++) {
                        if ($i == 0) {
                            $lang = strtolower($codes[0]);
                        } else {
                            $lang .= '_' . strtoupper($codes[$i]);
                        }
                    }
                }
            }
            $this->languages[] = $lang;
        }
        return $this->languages;
    }
    public function getCharsets()
    {
        if (null !== $this->charsets) {
            return $this->charsets;
        }
        return $this->charsets = array_keys(AcceptHeader::fromString($this->headers->get('Accept-Charset'))->all());
    }
    public function getEncodings()
    {
        if (null !== $this->encodings) {
            return $this->encodings;
        }
        return $this->encodings = array_keys(AcceptHeader::fromString($this->headers->get('Accept-Encoding'))->all());
    }
    public function getAcceptableContentTypes()
    {
        if (null !== $this->acceptableContentTypes) {
            return $this->acceptableContentTypes;
        }
        return $this->acceptableContentTypes = array_keys(AcceptHeader::fromString($this->headers->get('Accept'))->all());
    }
    public function isXmlHttpRequest()
    {
        return 'XMLHttpRequest' == $this->headers->get('X-Requested-With');
    }
    protected function prepareRequestUri()
    {
        $requestUri = '';
        if ($this->headers->has('X_ORIGINAL_URL')) {
            $requestUri = $this->headers->get('X_ORIGINAL_URL');
            $this->headers->remove('X_ORIGINAL_URL');
            $this->server->remove('HTTP_X_ORIGINAL_URL');
            $this->server->remove('UNENCODED_URL');
            $this->server->remove('IIS_WasUrlRewritten');
        } elseif ($this->headers->has('X_REWRITE_URL')) {
            $requestUri = $this->headers->get('X_REWRITE_URL');
            $this->headers->remove('X_REWRITE_URL');
        } elseif ($this->server->get('IIS_WasUrlRewritten') == '1' && $this->server->get('UNENCODED_URL') != '') {
            $requestUri = $this->server->get('UNENCODED_URL');
            $this->server->remove('UNENCODED_URL');
            $this->server->remove('IIS_WasUrlRewritten');
        } elseif ($this->server->has('REQUEST_URI')) {
            $requestUri = $this->server->get('REQUEST_URI');
            $schemeAndHttpHost = $this->getSchemeAndHttpHost();
            if (strpos($requestUri, $schemeAndHttpHost) === 0) {
                $requestUri = substr($requestUri, strlen($schemeAndHttpHost));
            }
        } elseif ($this->server->has('ORIG_PATH_INFO')) {
            $requestUri = $this->server->get('ORIG_PATH_INFO');
            if ('' != $this->server->get('QUERY_STRING')) {
                $requestUri .= '?' . $this->server->get('QUERY_STRING');
            }
            $this->server->remove('ORIG_PATH_INFO');
        }
        $this->server->set('REQUEST_URI', $requestUri);
        return $requestUri;
    }
    protected function prepareBaseUrl()
    {
        $filename = basename($this->server->get('SCRIPT_FILENAME'));
        if (basename($this->server->get('SCRIPT_NAME')) === $filename) {
            $baseUrl = $this->server->get('SCRIPT_NAME');
        } elseif (basename($this->server->get('PHP_SELF')) === $filename) {
            $baseUrl = $this->server->get('PHP_SELF');
        } elseif (basename($this->server->get('ORIG_SCRIPT_NAME')) === $filename) {
            $baseUrl = $this->server->get('ORIG_SCRIPT_NAME');
        } else {
            $path = $this->server->get('PHP_SELF', '');
            $file = $this->server->get('SCRIPT_FILENAME', '');
            $segs = explode('/', trim($file, '/'));
            $segs = array_reverse($segs);
            $index = 0;
            $last = count($segs);
            $baseUrl = '';
            do {
                $seg = $segs[$index];
                $baseUrl = '/' . $seg . $baseUrl;
                ++$index;
            } while ($last > $index && false !== ($pos = strpos($path, $baseUrl)) && 0 != $pos);
        }
        $requestUri = $this->getRequestUri();
        if ($baseUrl && false !== ($prefix = $this->getUrlencodedPrefix($requestUri, $baseUrl))) {
            return $prefix;
        }
        if ($baseUrl && false !== ($prefix = $this->getUrlencodedPrefix($requestUri, dirname($baseUrl)))) {
            return rtrim($prefix, '/');
        }
        $truncatedRequestUri = $requestUri;
        if (false !== ($pos = strpos($requestUri, '?'))) {
            $truncatedRequestUri = substr($requestUri, 0, $pos);
        }
        $basename = basename($baseUrl);
        if (empty($basename) || !strpos(rawurldecode($truncatedRequestUri), $basename)) {
            return '';
        }
        if (strlen($requestUri) >= strlen($baseUrl) && false !== ($pos = strpos($requestUri, $baseUrl)) && $pos !== 0) {
            $baseUrl = substr($requestUri, 0, $pos + strlen($baseUrl));
        }
        return rtrim($baseUrl, '/');
    }
    protected function prepareBasePath()
    {
        $filename = basename($this->server->get('SCRIPT_FILENAME'));
        $baseUrl = $this->getBaseUrl();
        if (empty($baseUrl)) {
            return '';
        }
        if (basename($baseUrl) === $filename) {
            $basePath = dirname($baseUrl);
        } else {
            $basePath = $baseUrl;
        }
        if ('\\' === DIRECTORY_SEPARATOR) {
            $basePath = str_replace('\\', '/', $basePath);
        }
        return rtrim($basePath, '/');
    }
    protected function preparePathInfo()
    {
        $baseUrl = $this->getBaseUrl();
        if (null === ($requestUri = $this->getRequestUri())) {
            return '/';
        }
        $pathInfo = '/';
        if ($pos = strpos($requestUri, '?')) {
            $requestUri = substr($requestUri, 0, $pos);
        }
        if (null !== $baseUrl && false === ($pathInfo = substr($requestUri, strlen($baseUrl)))) {
            return '/';
        } elseif (null === $baseUrl) {
            return $requestUri;
        }
        return (string) $pathInfo;
    }
    protected static function initializeFormats()
    {
        static::$formats = array('html' => array('text/html', 'application/xhtml+xml'), 'txt' => array('text/plain'), 'js' => array('application/javascript', 'application/x-javascript', 'text/javascript'), 'css' => array('text/css'), 'json' => array('application/json', 'application/x-json'), 'xml' => array('text/xml', 'application/xml', 'application/x-xml'), 'rdf' => array('application/rdf+xml'), 'atom' => array('application/atom+xml'), 'rss' => array('application/rss+xml'));
    }
    private function setPhpDefaultLocale($locale)
    {
        try {
            if (class_exists('Locale', false)) {
                \Locale::setDefault($locale);
            }
        } catch (\Exception $e) {
            
        }
    }
    private function getUrlencodedPrefix($string, $prefix)
    {
        if (0 !== strpos(rawurldecode($string), $prefix)) {
            return false;
        }
        $len = strlen($prefix);
        if (preg_match("#^(%[[:xdigit:]]{2}|.){{$len}}#", $string, $match)) {
            return $match[0];
        }
        return false;
    }
    private static function createRequestFromFactory(array $query = array(), array $request = array(), array $attributes = array(), array $cookies = array(), array $files = array(), array $server = array(), $content = null)
    {
        if (self::$requestFactory) {
            $request = call_user_func(self::$requestFactory, $query, $request, $attributes, $cookies, $files, $server, $content);
            if (!$request instanceof Request) {
                throw new \LogicException('The Request factory must return an instance of Symfony\\Component\\HttpFoundation\\Request.');
            }
            return $request;
        }
        return new static($query, $request, $attributes, $cookies, $files, $server, $content);
    }
}
namespace Symfony\Component\HttpFoundation;

class ParameterBag implements \IteratorAggregate, \Countable
{
    protected $parameters;
    public function __construct(array $parameters = array())
    {
        $this->parameters = $parameters;
    }
    public function all()
    {
        return $this->parameters;
    }
    public function keys()
    {
        return array_keys($this->parameters);
    }
    public function replace(array $parameters = array())
    {
        $this->parameters = $parameters;
    }
    public function add(array $parameters = array())
    {
        $this->parameters = array_replace($this->parameters, $parameters);
    }
    public function get($path, $default = null, $deep = false)
    {
        if (!$deep || false === ($pos = strpos($path, '['))) {
            return array_key_exists($path, $this->parameters) ? $this->parameters[$path] : $default;
        }
        $root = substr($path, 0, $pos);
        if (!array_key_exists($root, $this->parameters)) {
            return $default;
        }
        $value = $this->parameters[$root];
        $currentKey = null;
        for ($i = $pos, $c = strlen($path); $i < $c; $i++) {
            $char = $path[$i];
            if ('[' === $char) {
                if (null !== $currentKey) {
                    throw new \InvalidArgumentException(sprintf('Malformed path. Unexpected "[" at position %d.', $i));
                }
                $currentKey = '';
            } elseif (']' === $char) {
                if (null === $currentKey) {
                    throw new \InvalidArgumentException(sprintf('Malformed path. Unexpected "]" at position %d.', $i));
                }
                if (!is_array($value) || !array_key_exists($currentKey, $value)) {
                    return $default;
                }
                $value = $value[$currentKey];
                $currentKey = null;
            } else {
                if (null === $currentKey) {
                    throw new \InvalidArgumentException(sprintf('Malformed path. Unexpected "%s" at position %d.', $char, $i));
                }
                $currentKey .= $char;
            }
        }
        if (null !== $currentKey) {
            throw new \InvalidArgumentException(sprintf('Malformed path. Path must end with "]".'));
        }
        return $value;
    }
    public function set($key, $value)
    {
        $this->parameters[$key] = $value;
    }
    public function has($key)
    {
        return array_key_exists($key, $this->parameters);
    }
    public function remove($key)
    {
        unset($this->parameters[$key]);
    }
    public function getAlpha($key, $default = '', $deep = false)
    {
        return preg_replace('/[^[:alpha:]]/', '', $this->get($key, $default, $deep));
    }
    public function getAlnum($key, $default = '', $deep = false)
    {
        return preg_replace('/[^[:alnum:]]/', '', $this->get($key, $default, $deep));
    }
    public function getDigits($key, $default = '', $deep = false)
    {
        return str_replace(array('-', '+'), '', $this->filter($key, $default, $deep, FILTER_SANITIZE_NUMBER_INT));
    }
    public function getInt($key, $default = 0, $deep = false)
    {
        return (int) $this->get($key, $default, $deep);
    }
    public function filter($key, $default = null, $deep = false, $filter = FILTER_DEFAULT, $options = array())
    {
        $value = $this->get($key, $default, $deep);
        if (!is_array($options) && $options) {
            $options = array('flags' => $options);
        }
        if (is_array($value) && !isset($options['flags'])) {
            $options['flags'] = FILTER_REQUIRE_ARRAY;
        }
        return filter_var($value, $filter, $options);
    }
    public function getIterator()
    {
        return new \ArrayIterator($this->parameters);
    }
    public function count()
    {
        return count($this->parameters);
    }
}
namespace Symfony\Component\HttpFoundation;

use Symfony\Component\HttpFoundation\File\UploadedFile;
class FileBag extends ParameterBag
{
    private static $fileKeys = array('error', 'name', 'size', 'tmp_name', 'type');
    public function __construct(array $parameters = array())
    {
        $this->replace($parameters);
    }
    public function replace(array $files = array())
    {
        $this->parameters = array();
        $this->add($files);
    }
    public function set($key, $value)
    {
        if (!is_array($value) && !$value instanceof UploadedFile) {
            throw new \InvalidArgumentException('An uploaded file must be an array or an instance of UploadedFile.');
        }
        parent::set($key, $this->convertFileInformation($value));
    }
    public function add(array $files = array())
    {
        foreach ($files as $key => $file) {
            $this->set($key, $file);
        }
    }
    protected function convertFileInformation($file)
    {
        if ($file instanceof UploadedFile) {
            return $file;
        }
        $file = $this->fixPhpFilesArray($file);
        if (is_array($file)) {
            $keys = array_keys($file);
            sort($keys);
            if ($keys == self::$fileKeys) {
                if (UPLOAD_ERR_NO_FILE == $file['error']) {
                    $file = null;
                } else {
                    $file = new UploadedFile($file['tmp_name'], $file['name'], $file['type'], $file['size'], $file['error']);
                }
            } else {
                $file = array_map(array($this, 'convertFileInformation'), $file);
            }
        }
        return $file;
    }
    protected function fixPhpFilesArray($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        $keys = array_keys($data);
        sort($keys);
        if (self::$fileKeys != $keys || !isset($data['name']) || !is_array($data['name'])) {
            return $data;
        }
        $files = $data;
        foreach (self::$fileKeys as $k) {
            unset($files[$k]);
        }
        foreach (array_keys($data['name']) as $key) {
            $files[$key] = $this->fixPhpFilesArray(array('error' => $data['error'][$key], 'name' => $data['name'][$key], 'type' => $data['type'][$key], 'tmp_name' => $data['tmp_name'][$key], 'size' => $data['size'][$key]));
        }
        return $files;
    }
}
namespace Symfony\Component\HttpFoundation;

class ServerBag extends ParameterBag
{
    public function getHeaders()
    {
        $headers = array();
        $contentHeaders = array('CONTENT_LENGTH' => true, 'CONTENT_MD5' => true, 'CONTENT_TYPE' => true);
        foreach ($this->parameters as $key => $value) {
            if (0 === strpos($key, 'HTTP_')) {
                $headers[substr($key, 5)] = $value;
            } elseif (isset($contentHeaders[$key])) {
                $headers[$key] = $value;
            }
        }
        if (isset($this->parameters['PHP_AUTH_USER'])) {
            $headers['PHP_AUTH_USER'] = $this->parameters['PHP_AUTH_USER'];
            $headers['PHP_AUTH_PW'] = isset($this->parameters['PHP_AUTH_PW']) ? $this->parameters['PHP_AUTH_PW'] : '';
        } else {
            $authorizationHeader = null;
            if (isset($this->parameters['HTTP_AUTHORIZATION'])) {
                $authorizationHeader = $this->parameters['HTTP_AUTHORIZATION'];
            } elseif (isset($this->parameters['REDIRECT_HTTP_AUTHORIZATION'])) {
                $authorizationHeader = $this->parameters['REDIRECT_HTTP_AUTHORIZATION'];
            }
            if (null !== $authorizationHeader) {
                if (0 === stripos($authorizationHeader, 'basic ')) {
                    $exploded = explode(':', base64_decode(substr($authorizationHeader, 6)), 2);
                    if (count($exploded) == 2) {
                        list($headers['PHP_AUTH_USER'], $headers['PHP_AUTH_PW']) = $exploded;
                    }
                } elseif (empty($this->parameters['PHP_AUTH_DIGEST']) && 0 === stripos($authorizationHeader, 'digest ')) {
                    $headers['PHP_AUTH_DIGEST'] = $authorizationHeader;
                    $this->parameters['PHP_AUTH_DIGEST'] = $authorizationHeader;
                }
            }
        }
        if (isset($headers['PHP_AUTH_USER'])) {
            $headers['AUTHORIZATION'] = 'Basic ' . base64_encode($headers['PHP_AUTH_USER'] . ':' . $headers['PHP_AUTH_PW']);
        } elseif (isset($headers['PHP_AUTH_DIGEST'])) {
            $headers['AUTHORIZATION'] = $headers['PHP_AUTH_DIGEST'];
        }
        return $headers;
    }
}
namespace Symfony\Component\HttpFoundation;

class HeaderBag implements \IteratorAggregate, \Countable
{
    protected $headers = array();
    protected $cacheControl = array();
    public function __construct(array $headers = array())
    {
        foreach ($headers as $key => $values) {
            $this->set($key, $values);
        }
    }
    public function __toString()
    {
        if (!$this->headers) {
            return '';
        }
        $max = max(array_map('strlen', array_keys($this->headers))) + 1;
        $content = '';
        ksort($this->headers);
        foreach ($this->headers as $name => $values) {
            $name = implode('-', array_map('ucfirst', explode('-', $name)));
            foreach ($values as $value) {
                $content .= sprintf("%-{$max}s %s\r\n", $name . ':', $value);
            }
        }
        return $content;
    }
    public function all()
    {
        return $this->headers;
    }
    public function keys()
    {
        return array_keys($this->headers);
    }
    public function replace(array $headers = array())
    {
        $this->headers = array();
        $this->add($headers);
    }
    public function add(array $headers)
    {
        foreach ($headers as $key => $values) {
            $this->set($key, $values);
        }
    }
    public function get($key, $default = null, $first = true)
    {
        $key = strtr(strtolower($key), '_', '-');
        if (!array_key_exists($key, $this->headers)) {
            if (null === $default) {
                return $first ? null : array();
            }
            return $first ? $default : array($default);
        }
        if ($first) {
            return count($this->headers[$key]) ? $this->headers[$key][0] : $default;
        }
        return $this->headers[$key];
    }
    public function set($key, $values, $replace = true)
    {
        $key = strtr(strtolower($key), '_', '-');
        $values = array_values((array) $values);
        if (true === $replace || !isset($this->headers[$key])) {
            $this->headers[$key] = $values;
        } else {
            $this->headers[$key] = array_merge($this->headers[$key], $values);
        }
        if ('cache-control' === $key) {
            $this->cacheControl = $this->parseCacheControl($values[0]);
        }
    }
    public function has($key)
    {
        return array_key_exists(strtr(strtolower($key), '_', '-'), $this->headers);
    }
    public function contains($key, $value)
    {
        return in_array($value, $this->get($key, null, false));
    }
    public function remove($key)
    {
        $key = strtr(strtolower($key), '_', '-');
        unset($this->headers[$key]);
        if ('cache-control' === $key) {
            $this->cacheControl = array();
        }
    }
    public function getDate($key, \DateTime $default = null)
    {
        if (null === ($value = $this->get($key))) {
            return $default;
        }
        if (false === ($date = \DateTime::createFromFormat(DATE_RFC2822, $value))) {
            throw new \RuntimeException(sprintf('The %s HTTP header is not parseable (%s).', $key, $value));
        }
        return $date;
    }
    public function addCacheControlDirective($key, $value = true)
    {
        $this->cacheControl[$key] = $value;
        $this->set('Cache-Control', $this->getCacheControlHeader());
    }
    public function hasCacheControlDirective($key)
    {
        return array_key_exists($key, $this->cacheControl);
    }
    public function getCacheControlDirective($key)
    {
        return array_key_exists($key, $this->cacheControl) ? $this->cacheControl[$key] : null;
    }
    public function removeCacheControlDirective($key)
    {
        unset($this->cacheControl[$key]);
        $this->set('Cache-Control', $this->getCacheControlHeader());
    }
    public function getIterator()
    {
        return new \ArrayIterator($this->headers);
    }
    public function count()
    {
        return count($this->headers);
    }
    protected function getCacheControlHeader()
    {
        $parts = array();
        ksort($this->cacheControl);
        foreach ($this->cacheControl as $key => $value) {
            if (true === $value) {
                $parts[] = $key;
            } else {
                if (preg_match('#[^a-zA-Z0-9._-]#', $value)) {
                    $value = '"' . $value . '"';
                }
                $parts[] = "{$key}={$value}";
            }
        }
        return implode(', ', $parts);
    }
    protected function parseCacheControl($header)
    {
        $cacheControl = array();
        preg_match_all('#([a-zA-Z][a-zA-Z_-]*)\\s*(?:=(?:"([^"]*)"|([^ \\t",;]*)))?#', $header, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $cacheControl[strtolower($match[1])] = isset($match[3]) ? $match[3] : (isset($match[2]) ? $match[2] : true);
        }
        return $cacheControl;
    }
}
namespace Symfony\Component\HttpFoundation\Session;

use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
interface SessionInterface
{
    public function start();
    public function getId();
    public function setId($id);
    public function getName();
    public function setName($name);
    public function invalidate($lifetime = null);
    public function migrate($destroy = false, $lifetime = null);
    public function save();
    public function has($name);
    public function get($name, $default = null);
    public function set($name, $value);
    public function all();
    public function replace(array $attributes);
    public function remove($name);
    public function clear();
    public function isStarted();
    public function registerBag(SessionBagInterface $bag);
    public function getBag($name);
    public function getMetadataBag();
}
namespace Symfony\Component\HttpFoundation\Session;

interface SessionBagInterface
{
    public function getName();
    public function initialize(array &$array);
    public function getStorageKey();
    public function clear();
}
namespace Symfony\Component\HttpFoundation\Session\Attribute;

use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
interface AttributeBagInterface extends SessionBagInterface
{
    public function has($name);
    public function get($name, $default = null);
    public function set($name, $value);
    public function all();
    public function replace(array $attributes);
    public function remove($name);
}
namespace Symfony\Component\HttpFoundation\Session\Attribute;

class AttributeBag implements AttributeBagInterface, \IteratorAggregate, \Countable
{
    private $name = 'attributes';
    private $storageKey;
    protected $attributes = array();
    public function __construct($storageKey = '_sf2_attributes')
    {
        $this->storageKey = $storageKey;
    }
    public function getName()
    {
        return $this->name;
    }
    public function setName($name)
    {
        $this->name = $name;
    }
    public function initialize(array &$attributes)
    {
        $this->attributes =& $attributes;
    }
    public function getStorageKey()
    {
        return $this->storageKey;
    }
    public function has($name)
    {
        return array_key_exists($name, $this->attributes);
    }
    public function get($name, $default = null)
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }
    public function set($name, $value)
    {
        $this->attributes[$name] = $value;
    }
    public function all()
    {
        return $this->attributes;
    }
    public function replace(array $attributes)
    {
        $this->attributes = array();
        foreach ($attributes as $key => $value) {
            $this->set($key, $value);
        }
    }
    public function remove($name)
    {
        $retval = null;
        if (array_key_exists($name, $this->attributes)) {
            $retval = $this->attributes[$name];
            unset($this->attributes[$name]);
        }
        return $retval;
    }
    public function clear()
    {
        $return = $this->attributes;
        $this->attributes = array();
        return $return;
    }
    public function getIterator()
    {
        return new \ArrayIterator($this->attributes);
    }
    public function count()
    {
        return count($this->attributes);
    }
}
namespace Symfony\Component\HttpFoundation\Session\Storage;

use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
class MetadataBag implements SessionBagInterface
{
    const CREATED = 'c';
    const UPDATED = 'u';
    const LIFETIME = 'l';
    private $name = '__metadata';
    private $storageKey;
    protected $meta = array(self::CREATED => 0, self::UPDATED => 0, self::LIFETIME => 0);
    private $lastUsed;
    private $updateThreshold;
    public function __construct($storageKey = '_sf2_meta', $updateThreshold = 0)
    {
        $this->storageKey = $storageKey;
        $this->updateThreshold = $updateThreshold;
    }
    public function initialize(array &$array)
    {
        $this->meta =& $array;
        if (isset($array[self::CREATED])) {
            $this->lastUsed = $this->meta[self::UPDATED];
            $timeStamp = time();
            if ($timeStamp - $array[self::UPDATED] >= $this->updateThreshold) {
                $this->meta[self::UPDATED] = $timeStamp;
            }
        } else {
            $this->stampCreated();
        }
    }
    public function getLifetime()
    {
        return $this->meta[self::LIFETIME];
    }
    public function stampNew($lifetime = null)
    {
        $this->stampCreated($lifetime);
    }
    public function getStorageKey()
    {
        return $this->storageKey;
    }
    public function getCreated()
    {
        return $this->meta[self::CREATED];
    }
    public function getLastUsed()
    {
        return $this->lastUsed;
    }
    public function clear()
    {
        
    }
    public function getName()
    {
        return $this->name;
    }
    public function setName($name)
    {
        $this->name = $name;
    }
    private function stampCreated($lifetime = null)
    {
        $timeStamp = time();
        $this->meta[self::CREATED] = $this->meta[self::UPDATED] = $this->lastUsed = $timeStamp;
        $this->meta[self::LIFETIME] = null === $lifetime ? ini_get('session.cookie_lifetime') : $lifetime;
    }
}
namespace Symfony\Component\HttpFoundation;

class AcceptHeaderItem
{
    private $value;
    private $quality = 1.0;
    private $index = 0;
    private $attributes = array();
    public function __construct($value, array $attributes = array())
    {
        $this->value = $value;
        foreach ($attributes as $name => $value) {
            $this->setAttribute($name, $value);
        }
    }
    public static function fromString($itemValue)
    {
        $bits = preg_split('/\\s*(?:;*("[^"]+");*|;*(\'[^\']+\');*|;+)\\s*/', $itemValue, 0, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        $value = array_shift($bits);
        $attributes = array();
        $lastNullAttribute = null;
        foreach ($bits as $bit) {
            if (($start = substr($bit, 0, 1)) === ($end = substr($bit, -1)) && ($start === '"' || $start === '\'')) {
                $attributes[$lastNullAttribute] = substr($bit, 1, -1);
            } elseif ('=' === $end) {
                $lastNullAttribute = $bit = substr($bit, 0, -1);
                $attributes[$bit] = null;
            } else {
                $parts = explode('=', $bit);
                $attributes[$parts[0]] = isset($parts[1]) && strlen($parts[1]) > 0 ? $parts[1] : '';
            }
        }
        return new self(($start = substr($value, 0, 1)) === ($end = substr($value, -1)) && ($start === '"' || $start === '\'') ? substr($value, 1, -1) : $value, $attributes);
    }
    public function __toString()
    {
        $string = $this->value . ($this->quality < 1 ? ';q=' . $this->quality : '');
        if (count($this->attributes) > 0) {
            $string .= ';' . implode(';', array_map(function ($name, $value) {
                return sprintf(preg_match('/[,;=]/', $value) ? '%s="%s"' : '%s=%s', $name, $value);
            }, array_keys($this->attributes), $this->attributes));
        }
        return $string;
    }
    public function setValue($value)
    {
        $this->value = $value;
        return $this;
    }
    public function getValue()
    {
        return $this->value;
    }
    public function setQuality($quality)
    {
        $this->quality = $quality;
        return $this;
    }
    public function getQuality()
    {
        return $this->quality;
    }
    public function setIndex($index)
    {
        $this->index = $index;
        return $this;
    }
    public function getIndex()
    {
        return $this->index;
    }
    public function hasAttribute($name)
    {
        return isset($this->attributes[$name]);
    }
    public function getAttribute($name, $default = null)
    {
        return isset($this->attributes[$name]) ? $this->attributes[$name] : $default;
    }
    public function getAttributes()
    {
        return $this->attributes;
    }
    public function setAttribute($name, $value)
    {
        if ('q' === $name) {
            $this->quality = (double) $value;
        } else {
            $this->attributes[$name] = (string) $value;
        }
        return $this;
    }
}
namespace Symfony\Component\HttpFoundation;

class AcceptHeader
{
    private $items = array();
    private $sorted = true;
    public function __construct(array $items)
    {
        foreach ($items as $item) {
            $this->add($item);
        }
    }
    public static function fromString($headerValue)
    {
        $index = 0;
        return new self(array_map(function ($itemValue) use(&$index) {
            $item = AcceptHeaderItem::fromString($itemValue);
            $item->setIndex($index++);
            return $item;
        }, preg_split('/\\s*(?:,*("[^"]+"),*|,*(\'[^\']+\'),*|,+)\\s*/', $headerValue, 0, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE)));
    }
    public function __toString()
    {
        return implode(',', $this->items);
    }
    public function has($value)
    {
        return isset($this->items[$value]);
    }
    public function get($value)
    {
        return isset($this->items[$value]) ? $this->items[$value] : null;
    }
    public function add(AcceptHeaderItem $item)
    {
        $this->items[$item->getValue()] = $item;
        $this->sorted = false;
        return $this;
    }
    public function all()
    {
        $this->sort();
        return $this->items;
    }
    public function filter($pattern)
    {
        return new self(array_filter($this->items, function (AcceptHeaderItem $item) use($pattern) {
            return preg_match($pattern, $item->getValue());
        }));
    }
    public function first()
    {
        $this->sort();
        return !empty($this->items) ? reset($this->items) : null;
    }
    private function sort()
    {
        if (!$this->sorted) {
            uasort($this->items, function ($a, $b) {
                $qA = $a->getQuality();
                $qB = $b->getQuality();
                if ($qA === $qB) {
                    return $a->getIndex() > $b->getIndex() ? 1 : -1;
                }
                return $qA > $qB ? -1 : 1;
            });
            $this->sorted = true;
        }
    }
}
namespace Symfony\Component\Debug;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Debug\Exception\FlattenException;
if (!defined('ENT_SUBSTITUTE')) {
    define('ENT_SUBSTITUTE', 8);
}
class ExceptionHandler
{
    private $debug;
    private $charset;
    public function __construct($debug = true, $charset = 'UTF-8')
    {
        $this->debug = $debug;
        $this->charset = $charset;
    }
    public static function register($debug = true)
    {
        $handler = new static($debug);
        set_exception_handler(array($handler, 'handle'));
        return $handler;
    }
    public function handle(\Exception $exception)
    {
        if (class_exists('Symfony\\Component\\HttpFoundation\\Response')) {
            $this->createResponse($exception)->send();
        } else {
            $this->sendPhpResponse($exception);
        }
    }
    public function sendPhpResponse($exception)
    {
        if (!$exception instanceof FlattenException) {
            $exception = FlattenException::create($exception);
        }
        if (!headers_sent()) {
            header(sprintf('HTTP/1.0 %s', $exception->getStatusCode()));
            foreach ($exception->getHeaders() as $name => $value) {
                header($name . ': ' . $value, false);
            }
        }
        echo $this->decorate($this->getContent($exception), $this->getStylesheet($exception));
    }
    public function createResponse($exception)
    {
        if (!$exception instanceof FlattenException) {
            $exception = FlattenException::create($exception);
        }
        return new Response($this->decorate($this->getContent($exception), $this->getStylesheet($exception)), $exception->getStatusCode(), $exception->getHeaders());
    }
    public function getContent(FlattenException $exception)
    {
        switch ($exception->getStatusCode()) {
            case 404:
                $title = 'Sorry, the page you are looking for could not be found.';
                break;
            default:
                $title = 'Whoops, looks like something went wrong.';
        }
        $content = '';
        if ($this->debug) {
            try {
                $count = count($exception->getAllPrevious());
                $total = $count + 1;
                foreach ($exception->toArray() as $position => $e) {
                    $ind = $count - $position + 1;
                    $class = $this->abbrClass($e['class']);
                    $message = nl2br($e['message']);
                    $content .= sprintf('                        <div class="block_exception clear_fix">
                            <h2><span>%d/%d</span> %s: %s</h2>
                        </div>
                        <div class="block">
                            <ol class="traces list_exception">', $ind, $total, $class, $message);
                    foreach ($e['trace'] as $trace) {
                        $content .= '       <li>';
                        if ($trace['function']) {
                            $content .= sprintf('at %s%s%s(%s)', $this->abbrClass($trace['class']), $trace['type'], $trace['function'], $this->formatArgs($trace['args']));
                        }
                        if (isset($trace['file']) && isset($trace['line'])) {
                            if ($linkFormat = ini_get('xdebug.file_link_format')) {
                                $link = str_replace(array('%f', '%l'), array($trace['file'], $trace['line']), $linkFormat);
                                $content .= sprintf(' in <a href="%s" title="Go to source">%s line %s</a>', $link, $trace['file'], $trace['line']);
                            } else {
                                $content .= sprintf(' in %s line %s', $trace['file'], $trace['line']);
                            }
                        }
                        $content .= '</li>
';
                    }
                    $content .= '    </ol>
</div>
';
                }
            } catch (\Exception $e) {
                if ($this->debug) {
                    $title = sprintf('Exception thrown when handling an exception (%s: %s)', get_class($exception), $exception->getMessage());
                } else {
                    $title = 'Whoops, looks like something went wrong.';
                }
            }
        }
        return "            <div id=\"sf-resetcontent\" class=\"sf-reset\">\n                <h1>{$title}</h1>\n                {$content}\n            </div>";
    }
    public function getStylesheet(FlattenException $exception)
    {
        return '            .sf-reset { font: 11px Verdana, Arial, sans-serif; color: #333 }
            .sf-reset .clear { clear:both; height:0; font-size:0; line-height:0; }
            .sf-reset .clear_fix:after { display:block; height:0; clear:both; visibility:hidden; }
            .sf-reset .clear_fix { display:inline-block; }
            .sf-reset * html .clear_fix { height:1%; }
            .sf-reset .clear_fix { display:block; }
            .sf-reset, .sf-reset .block { margin: auto }
            .sf-reset abbr { border-bottom: 1px dotted #000; cursor: help; }
            .sf-reset p { font-size:14px; line-height:20px; color:#868686; padding-bottom:20px }
            .sf-reset strong { font-weight:bold; }
            .sf-reset a { color:#6c6159; }
            .sf-reset a img { border:none; }
            .sf-reset a:hover { text-decoration:underline; }
            .sf-reset em { font-style:italic; }
            .sf-reset h1, .sf-reset h2 { font: 20px Georgia, "Times New Roman", Times, serif }
            .sf-reset h2 span { background-color: #fff; color: #333; padding: 6px; float: left; margin-right: 10px; }
            .sf-reset .traces li { font-size:12px; padding: 2px 4px; list-style-type:decimal; margin-left:20px; }
            .sf-reset .block { background-color:#FFFFFF; padding:10px 28px; margin-bottom:20px;
                -webkit-border-bottom-right-radius: 16px;
                -webkit-border-bottom-left-radius: 16px;
                -moz-border-radius-bottomright: 16px;
                -moz-border-radius-bottomleft: 16px;
                border-bottom-right-radius: 16px;
                border-bottom-left-radius: 16px;
                border-bottom:1px solid #ccc;
                border-right:1px solid #ccc;
                border-left:1px solid #ccc;
            }
            .sf-reset .block_exception { background-color:#ddd; color: #333; padding:20px;
                -webkit-border-top-left-radius: 16px;
                -webkit-border-top-right-radius: 16px;
                -moz-border-radius-topleft: 16px;
                -moz-border-radius-topright: 16px;
                border-top-left-radius: 16px;
                border-top-right-radius: 16px;
                border-top:1px solid #ccc;
                border-right:1px solid #ccc;
                border-left:1px solid #ccc;
                overflow: hidden;
                word-wrap: break-word;
            }
            .sf-reset li a { background:none; color:#868686; text-decoration:none; }
            .sf-reset li a:hover { background:none; color:#313131; text-decoration:underline; }
            .sf-reset ol { padding: 10px 0; }
            .sf-reset h1 { background-color:#FFFFFF; padding: 15px 28px; margin-bottom: 20px;
                -webkit-border-radius: 10px;
                -moz-border-radius: 10px;
                border-radius: 10px;
                border: 1px solid #ccc;
            }';
    }
    private function decorate($content, $css)
    {
        return "<!DOCTYPE html>\n<html>\n    <head>\n        <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"/>\n        <meta name=\"robots\" content=\"noindex,nofollow\" />\n        <style>\n            /* Copyright (c) 2010, Yahoo! Inc. All rights reserved. Code licensed under the BSD License: http://developer.yahoo.com/yui/license.html */\n            html{color:#000;background:#FFF;}body,div,dl,dt,dd,ul,ol,li,h1,h2,h3,h4,h5,h6,pre,code,form,fieldset,legend,input,textarea,p,blockquote,th,td{margin:0;padding:0;}table{border-collapse:collapse;border-spacing:0;}fieldset,img{border:0;}address,caption,cite,code,dfn,em,strong,th,var{font-style:normal;font-weight:normal;}li{list-style:none;}caption,th{text-align:left;}h1,h2,h3,h4,h5,h6{font-size:100%;font-weight:normal;}q:before,q:after{content:'';}abbr,acronym{border:0;font-variant:normal;}sup{vertical-align:text-top;}sub{vertical-align:text-bottom;}input,textarea,select{font-family:inherit;font-size:inherit;font-weight:inherit;}input,textarea,select{*font-size:100%;}legend{color:#000;}\n\n            html { background: #eee; padding: 10px }\n            img { border: 0; }\n            #sf-resetcontent { width:970px; margin:0 auto; }\n            {$css}\n        </style>\n    </head>\n    <body>\n        {$content}\n    </body>\n</html>";
    }
    private function abbrClass($class)
    {
        $parts = explode('\\', $class);
        return sprintf('<abbr title="%s">%s</abbr>', $class, array_pop($parts));
    }
    private function formatArgs(array $args)
    {
        $result = array();
        foreach ($args as $key => $item) {
            if ('object' === $item[0]) {
                $formattedValue = sprintf('<em>object</em>(%s)', $this->abbrClass($item[1]));
            } elseif ('array' === $item[0]) {
                $formattedValue = sprintf('<em>array</em>(%s)', is_array($item[1]) ? $this->formatArgs($item[1]) : $item[1]);
            } elseif ('string' === $item[0]) {
                $formattedValue = sprintf('\'%s\'', htmlspecialchars($item[1], ENT_QUOTES | ENT_SUBSTITUTE, $this->charset));
            } elseif ('null' === $item[0]) {
                $formattedValue = '<em>null</em>';
            } elseif ('boolean' === $item[0]) {
                $formattedValue = '<em>' . strtolower(var_export($item[1], true)) . '</em>';
            } elseif ('resource' === $item[0]) {
                $formattedValue = '<em>resource</em>';
            } else {
                $formattedValue = str_replace('
', '', var_export(htmlspecialchars((string) $item[1], ENT_QUOTES | ENT_SUBSTITUTE, $this->charset), true));
            }
            $result[] = is_int($key) ? $formattedValue : sprintf('\'%s\' => %s', $key, $formattedValue);
        }
        return implode(', ', $result);
    }
}
namespace Royalcms\Component\Support;

use ReflectionClass;
abstract class ServiceProvider
{
    protected $royalcms;
    protected $defer = false;
    public function __construct($royalcms)
    {
        $this->royalcms = $royalcms;
    }
    public function boot()
    {
        
    }
    public abstract function register();
    public function package($package, $namespace = null, $path = null)
    {
        $namespace = $this->getPackageNamespace($package, $namespace);
        $path = $path ?: $this->guessPackagePath();
        $config = $path . '/config';
        if ($this->royalcms['files']->isDirectory($config)) {
            $this->royalcms['config']->package($package, $config, $namespace);
        }
        $lang = $path . '/lang';
        if ($this->royalcms['files']->isDirectory($lang)) {
            $this->royalcms['translator']->addNamespace($namespace, $lang);
        }
        $appView = $this->getAppViewPath($package);
        if ($this->royalcms['files']->isDirectory($appView)) {
            $this->royalcms['view']->addNamespace($namespace, $appView);
        }
        $view = $path . '/views';
        if ($this->royalcms['files']->isDirectory($view)) {
            $this->royalcms['view']->addNamespace($namespace, $view);
        }
    }
    public function guessPackagePath()
    {
        $path = with(new ReflectionClass($this))->getFileName();
        return realpath(dirname($path) . '/../../../');
    }
    protected function getPackageNamespace($package, $namespace)
    {
        if (is_null($namespace)) {
            list($vendor, $namespace) = explode('/', $package);
        }
        return $namespace;
    }
    public function commands($commands)
    {
        $commands = is_array($commands) ? $commands : func_get_args();
        $events = $this->royalcms['events'];
        $events->listen('royalcmd.start', function ($royalcmd) use($commands) {
            $royalcmd->resolveCommands($commands);
        });
    }
    protected function getAppViewPath($package)
    {
        return $this->royalcms['path'] . "/views/packages/{$package}";
    }
    public function provides()
    {
        return array();
    }
    public function when()
    {
        return array();
    }
    public function isDeferred()
    {
        return $this->defer;
    }
}
namespace Royalcms\Component\Routing;

use Royalcms\Component\Support\ServiceProvider;
use Royalcms\Component\Support\Facades\Response;
class RoutingServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->registerRouter();
        $this->registerResponse();
        $this->registerUrlGenerator();
        $this->registerRedirector();
    }
    protected function registerRouter()
    {
        $this->royalcms['router'] = $this->royalcms->share(function ($royalcms) {
            $router = new Router($royalcms['events'], $royalcms);
            if ($royalcms['env'] == 'testing') {
                $router->disableFilters();
            }
            return $router;
        });
    }
    protected function registerResponse()
    {
        $this->royalcms['response'] = $this->royalcms->share(function ($royalcms) {
            $response = Response::make();
            return $response;
        });
    }
    protected function registerUrlGenerator()
    {
        $this->royalcms['url'] = $this->royalcms->share(function ($royalcms) {
            $routes = $royalcms['router']->getRoutes();
            return new UrlGenerator($routes, $royalcms->rebinding('request', function ($royalcms, $request) {
                $royalcms['url']->setRequest($request);
            }));
        });
    }
    protected function registerRedirector()
    {
        $this->royalcms['redirect'] = $this->royalcms->share(function ($royalcms) {
            $redirector = new Redirector($royalcms['url']);
            if (isset($royalcms['session.store'])) {
                $redirector->setSession($royalcms['session.store']);
            }
            return $redirector;
        });
    }
}
namespace Royalcms\Component\Event;

use Royalcms\Component\Support\ServiceProvider;
class EventServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->royalcms['events'] = $this->royalcms->share(function ($royalcms) {
            return new Dispatcher($royalcms);
        });
    }
}
namespace Royalcms\Component\Support\Facades;

abstract class Facade
{
    protected static $royalcms;
    protected static $resolvedInstance;
    public static function swap($instance)
    {
        static::$resolvedInstance[static::getFacadeAccessor()] = $instance;
        static::$system->instance(static::getFacadeAccessor(), $instance);
    }
    public static function getFacadeRoot()
    {
        return static::resolveFacadeInstance(static::getFacadeAccessor());
    }
    protected static function getFacadeAccessor()
    {
        throw new \RuntimeException('Facade does not implement getFacadeAccessor method.');
    }
    protected static function resolveFacadeInstance($name)
    {
        if (is_object($name)) {
            return $name;
        }
        if (isset(static::$resolvedInstance[$name])) {
            return static::$resolvedInstance[$name];
        }
        return static::$resolvedInstance[$name] = static::$royalcms[$name];
    }
    public static function clearResolvedInstance($name)
    {
        unset(static::$resolvedInstance[$name]);
    }
    public static function clearResolvedInstances()
    {
        static::$resolvedInstance = array();
    }
    public static function getFacadeRoyalcms()
    {
        return static::$royalcms;
    }
    public static function setFacadeRoyalcms($royalcms)
    {
        static::$royalcms = $royalcms;
    }
    public static function __callStatic($method, $args)
    {
        $instance = static::getFacadeRoot();
        switch (count($args)) {
            case 0:
                return $instance->{$method}();
            case 1:
                return $instance->{$method}($args[0]);
            case 2:
                return $instance->{$method}($args[0], $args[1]);
            case 3:
                return $instance->{$method}($args[0], $args[1], $args[2]);
            case 4:
                return $instance->{$method}($args[0], $args[1], $args[2], $args[3]);
            default:
                return call_user_func_array(array($instance, $method), $args);
        }
    }
}
namespace Symfony\Component\Debug;

use Psr\Log\LoggerInterface;
use Symfony\Component\Debug\Exception\ContextErrorException;
use Symfony\Component\Debug\Exception\FatalErrorException;
use Symfony\Component\Debug\Exception\DummyException;
use Symfony\Component\Debug\FatalErrorHandler\UndefinedFunctionFatalErrorHandler;
use Symfony\Component\Debug\FatalErrorHandler\ClassNotFoundFatalErrorHandler;
use Symfony\Component\Debug\FatalErrorHandler\FatalErrorHandlerInterface;
class ErrorHandler
{
    const TYPE_DEPRECATION = -100;
    private $levels = array(E_WARNING => 'Warning', E_NOTICE => 'Notice', E_USER_ERROR => 'User Error', E_USER_WARNING => 'User Warning', E_USER_NOTICE => 'User Notice', E_STRICT => 'Runtime Notice', E_RECOVERABLE_ERROR => 'Catchable Fatal Error', E_DEPRECATED => 'Deprecated', E_USER_DEPRECATED => 'User Deprecated', E_ERROR => 'Error', E_CORE_ERROR => 'Core Error', E_COMPILE_ERROR => 'Compile Error', E_PARSE => 'Parse');
    private $level;
    private $reservedMemory;
    private $displayErrors;
    private static $loggers = array();
    public static function register($level = null, $displayErrors = true)
    {
        $handler = new static();
        $handler->setLevel($level);
        $handler->setDisplayErrors($displayErrors);
        ini_set('display_errors', 0);
        set_error_handler(array($handler, 'handle'));
        register_shutdown_function(array($handler, 'handleFatal'));
        $handler->reservedMemory = str_repeat('x', 10240);
        return $handler;
    }
    public function setLevel($level)
    {
        $this->level = null === $level ? error_reporting() : $level;
    }
    public function setDisplayErrors($displayErrors)
    {
        $this->displayErrors = $displayErrors;
    }
    public static function setLogger(LoggerInterface $logger, $channel = 'deprecation')
    {
        self::$loggers[$channel] = $logger;
    }
    public function handle($level, $message, $file = 'unknown', $line = 0, $context = array())
    {
        if (0 === $this->level) {
            return false;
        }
        if ($level & (E_USER_DEPRECATED | E_DEPRECATED)) {
            if (isset(self::$loggers['deprecation'])) {
                if (version_compare(PHP_VERSION, '5.4', '<')) {
                    $stack = array_map(function ($row) {
                        unset($row['args']);
                        return $row;
                    }, array_slice(debug_backtrace(false), 0, 10));
                } else {
                    $stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
                }
                self::$loggers['deprecation']->warning($message, array('type' => self::TYPE_DEPRECATION, 'stack' => $stack));
            }
            return true;
        }
        if ($this->displayErrors && error_reporting() & $level && $this->level & $level) {
            if (!class_exists('Symfony\\Component\\Debug\\Exception\\ContextErrorException')) {
                require SITE_ROOT . 'vendor/symfony/debug/Symfony/Component/Debug' . '/Exception/ContextErrorException.php';
            }
            if (!class_exists('Symfony\\Component\\Debug\\Exception\\FlattenException')) {
                require SITE_ROOT . 'vendor/symfony/debug/Symfony/Component/Debug' . '/Exception/FlattenException.php';
            }
            if (PHP_VERSION_ID < 50400 && isset($context['GLOBALS']) && is_array($context)) {
                unset($context['GLOBALS']);
            }
            $exception = new ContextErrorException(sprintf('%s: %s in %s line %d', isset($this->levels[$level]) ? $this->levels[$level] : $level, $message, $file, $line), 0, $level, $file, $line, $context);
            $exceptionHandler = set_exception_handler(function () {
                
            });
            restore_exception_handler();
            if (is_array($exceptionHandler) && $exceptionHandler[0] instanceof ExceptionHandler) {
                $exceptionHandler[0]->handle($exception);
                if (!class_exists('Symfony\\Component\\Debug\\Exception\\DummyException')) {
                    require SITE_ROOT . 'vendor/symfony/debug/Symfony/Component/Debug' . '/Exception/DummyException.php';
                }
                set_exception_handler(function (\Exception $e) use($exceptionHandler) {
                    if (!$e instanceof DummyException) {
                        call_user_func($exceptionHandler, $e);
                    }
                });
                throw new DummyException();
            }
        }
        return false;
    }
    public function handleFatal()
    {
        if (null === ($error = error_get_last())) {
            return;
        }
        $this->reservedMemory = '';
        $type = $error['type'];
        if (0 === $this->level || !in_array($type, array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE))) {
            return;
        }
        if (isset(self::$loggers['emergency'])) {
            $fatal = array('type' => $type, 'file' => $error['file'], 'line' => $error['line']);
            self::$loggers['emergency']->emerg($error['message'], $fatal);
        }
        if (!$this->displayErrors) {
            return;
        }
        $exceptionHandler = set_exception_handler(function () {
            
        });
        restore_exception_handler();
        if (is_array($exceptionHandler) && $exceptionHandler[0] instanceof ExceptionHandler) {
            $this->handleFatalError($exceptionHandler[0], $error);
        }
    }
    protected function getFatalErrorHandlers()
    {
        return array(new UndefinedFunctionFatalErrorHandler(), new ClassNotFoundFatalErrorHandler());
    }
    private function handleFatalError(ExceptionHandler $exceptionHandler, array $error)
    {
        $level = isset($this->levels[$error['type']]) ? $this->levels[$error['type']] : $error['type'];
        $message = sprintf('%s: %s in %s line %d', $level, $error['message'], $error['file'], $error['line']);
        $exception = new FatalErrorException($message, 0, $error['type'], $error['file'], $error['line']);
        foreach ($this->getFatalErrorHandlers() as $handler) {
            if ($ex = $handler->handleError($error, $exception)) {
                return $exceptionHandler->handle($ex);
            }
        }
        $exceptionHandler->handle($exception);
    }
}
namespace Symfony\Component\HttpKernel\Debug;

use Symfony\Component\Debug\ErrorHandler as DebugErrorHandler;
class ErrorHandler extends DebugErrorHandler
{
    
}
namespace Royalcms\Component\Config;

use Closure;
use ArrayAccess;
use Royalcms\Component\Support\NamespacedItemResolver;
class Repository extends NamespacedItemResolver implements ArrayAccess
{
    protected $loader;
    protected $environment;
    protected $items = array();
    protected $packages = array();
    protected $afterLoad = array();
    public function __construct(LoaderInterface $loader, $environment)
    {
        $this->loader = $loader;
        $this->environment = $environment;
    }
    public function has($key)
    {
        $default = microtime(true);
        return $this->get($key, $default) !== $default;
    }
    public function hasGroup($key)
    {
        list($namespace, $group, $item) = $this->parseKey($key);
        return $this->loader->exists($group, $namespace);
    }
    public function get($key, $default = null)
    {
        list($namespace, $group, $item) = $this->parseKey($key);
        $collection = $this->getCollection($group, $namespace);
        $this->load($group, $namespace, $collection);
        return array_get($this->items[$collection], $item, $default);
    }
    public function set($key, $value)
    {
        list($namespace, $group, $item) = $this->parseKey($key);
        $collection = $this->getCollection($group, $namespace);
        $this->load($group, $namespace, $collection);
        if (is_null($item)) {
            $this->items[$collection] = $value;
        } else {
            array_set($this->items[$collection], $item, $value);
        }
    }
    protected function load($group, $namespace, $collection)
    {
        $env = $this->environment;
        if (isset($this->items[$collection])) {
            return;
        }
        $items = $this->loader->load($env, $group, $namespace);
        if (isset($this->afterLoad[$namespace])) {
            $items = $this->callAfterLoad($namespace, $group, $items);
        }
        $this->items[$collection] = $items;
    }
    protected function callAfterLoad($namespace, $group, $items)
    {
        $callback = $this->afterLoad[$namespace];
        return call_user_func($callback, $this, $group, $items);
    }
    protected function parseNamespacedSegments($key)
    {
        list($namespace, $item) = explode('::', $key);
        if (in_array($namespace, $this->packages)) {
            return $this->parsePackageSegments($key, $namespace, $item);
        }
        return parent::parseNamespacedSegments($key);
    }
    protected function parsePackageSegments($key, $namespace, $item)
    {
        $itemSegments = explode('.', $item);
        if (!$this->loader->exists($itemSegments[0], $namespace)) {
            return array($namespace, 'config', $item);
        }
        return parent::parseNamespacedSegments($key);
    }
    public function package($package, $hint, $namespace = null)
    {
        $namespace = $this->getPackageNamespace($package, $namespace);
        $this->packages[] = $namespace;
        $this->addNamespace($namespace, $hint);
        $this->afterLoading($namespace, function ($me, $group, $items) use($package) {
            $env = $me->getEnvironment();
            $loader = $me->getLoader();
            return $loader->cascadePackage($env, $package, $group, $items);
        });
    }
    protected function getPackageNamespace($package, $namespace)
    {
        if (is_null($namespace)) {
            list($vendor, $namespace) = explode('/', $package);
        }
        return $namespace;
    }
    public function afterLoading($namespace, Closure $callback)
    {
        $this->afterLoad[$namespace] = $callback;
    }
    protected function getCollection($group, $namespace = null)
    {
        $namespace = $namespace ?: '*';
        return $namespace . '::' . $group;
    }
    public function addNamespace($namespace, $hint)
    {
        $this->loader->addNamespace($namespace, $hint);
    }
    public function getNamespaces()
    {
        return $this->loader->getNamespaces();
    }
    public function getLoader()
    {
        return $this->loader;
    }
    public function setLoader(LoaderInterface $loader)
    {
        $this->loader = $loader;
    }
    public function getEnvironment()
    {
        return $this->environment;
    }
    public function getAfterLoadCallbacks()
    {
        return $this->afterLoad;
    }
    public function getItems()
    {
        return $this->items;
    }
    public function offsetExists($key)
    {
        return $this->has($key);
    }
    public function offsetGet($key)
    {
        return $this->get($key);
    }
    public function offsetSet($key, $value)
    {
        $this->set($key, $value);
    }
    public function offsetUnset($key)
    {
        $this->set($key, null);
    }
    public function load_config($file, $name = '')
    {
        if ($name) {
            return $this->get($file . '.' . strtolower($name));
        } else {
            return $this->get($file);
        }
    }
    public function system($name)
    {
        return $this->get('system.' . strtolower($name));
    }
    public function cache($name)
    {
        return $this->get('cache.' . strtolower($name));
    }
    public function database($name)
    {
        return $this->get('database.' . strtolower($name));
    }
    public function mail($name)
    {
        return $this->get('mail.' . strtolower($name));
    }
    public function route($name)
    {
        return $this->get('route.' . strtolower($name));
    }
    public function session($name)
    {
        return $this->get('session.' . strtolower($name));
    }
    public function cookie($name)
    {
        return $this->get('session.' . strtolower($name));
    }
}
namespace Royalcms\Component\Support;

class NamespacedItemResolver
{
    protected $parsed = array();
    public function parseKey($key)
    {
        if (isset($this->parsed[$key])) {
            return $this->parsed[$key];
        }
        $segments = explode('.', $key);
        if (strpos($key, '::') === false) {
            $parsed = $this->parseBasicSegments($segments);
        } else {
            $parsed = $this->parseNamespacedSegments($key);
        }
        return $this->parsed[$key] = $parsed;
    }
    protected function parseBasicSegments(array $segments)
    {
        $group = $segments[0];
        if (count($segments) == 1) {
            return array(null, $group, null);
        } else {
            $item = implode('.', array_slice($segments, 1));
            return array(null, $group, $item);
        }
    }
    protected function parseNamespacedSegments($key)
    {
        list($namespace, $item) = explode('::', $key);
        $itemSegments = explode('.', $item);
        $groupAndItem = array_slice($this->parseBasicSegments($itemSegments), 1);
        return array_merge(array($namespace), $groupAndItem);
    }
    public function setParsedKey($key, $parsed)
    {
        $this->parsed[$key] = $parsed;
    }
}
namespace Royalcms\Component\Config;

use Royalcms\Component\Filesystem\Filesystem;
class FileLoader implements LoaderInterface
{
    protected $files;
    protected $defaultPath;
    protected $sitePath;
    protected $hints = array();
    protected $exists = array();
    public function __construct(Filesystem $files, $sitePath, $defaultPath = null)
    {
        $this->files = $files;
        $this->sitePath = $sitePath;
        if ($sitePath == $defaultPath) {
            $this->defaultPath = null;
        } else {
            $this->defaultPath = $defaultPath;
        }
    }
    public function load($environment, $group, $namespace = null)
    {
        $items = array();
        $path = $this->getPath($namespace);
        if (is_null($path)) {
            return $items;
        }
        $file = SITE_ROOT . 'vendor/royalcms/framework/Royalcms/Component/Config' . "/Resources/{$group}.php";
        if ($this->files->exists($file)) {
            $items = $this->files->getRequire($file);
        }
        if (null === $namespace && null !== $this->defaultPath && !(defined('USE_AUTONOMY_CONFIG') && true === USE_AUTONOMY_CONFIG)) {
            $file = "{$this->defaultPath}/{$group}.php";
            if ($this->files->exists($file)) {
                $items = $this->mergeEnvironment($items, $file);
            }
        }
        $file = "{$path}/{$group}.php";
        if ($this->files->exists($file)) {
            $items = $this->mergeEnvironment($items, $file);
        }
        $file = "{$path}/{$environment}/{$group}.php";
        if ($this->files->exists($file)) {
            $items = $this->mergeEnvironment($items, $file);
        }
        return $items;
    }
    protected function mergeEnvironment(array $items, $file)
    {
        return array_replace_recursive($items, $this->files->getRequire($file));
    }
    public function exists($group, $namespace = null)
    {
        $key = $group . $namespace;
        if (isset($this->exists[$key])) {
            return $this->exists[$key];
        }
        $path = $this->getPath($namespace);
        if (is_null($path)) {
            return $this->exists[$key] = false;
        }
        $file = "{$path}/{$group}.php";
        $exists = $this->files->exists($file);
        if (!$exists) {
            $file = "{$this->defaultPath}/{$group}.php";
            $exists = $this->files->exists($file);
        }
        if (!$exists) {
            $file = SITE_ROOT . 'vendor/royalcms/framework/Royalcms/Component/Config' . "/Resources/{$group}.php";
            $exists = $this->files->exists($file);
        }
        return $this->exists[$key] = $exists;
    }
    public function cascadePackage($env, $package, $group, $items)
    {
        $file = "packages/{$package}/{$group}.php";
        if ($this->files->exists($path = $this->sitePath . '/' . $file)) {
            $items = array_merge($items, $this->getRequire($path));
        }
        $path = $this->getPackagePath($env, $package, $group);
        if ($this->files->exists($path)) {
            $items = array_merge($items, $this->getRequire($path));
        }
        return $items;
    }
    protected function getPackagePath($env, $package, $group)
    {
        $file = "{$env}/packages/{$package}/{$group}.php";
        return $this->sitePath . '/' . $file;
    }
    protected function getPath($namespace)
    {
        if (is_null($namespace)) {
            return $this->sitePath;
        } elseif (isset($this->hints[$namespace])) {
            return $this->hints[$namespace];
        }
    }
    public function addNamespace($namespace, $hint)
    {
        $this->hints[$namespace] = $hint;
    }
    public function getNamespaces()
    {
        return $this->hints;
    }
    protected function getRequire($path)
    {
        return $this->files->getRequire($path);
    }
    public function getFilesystem()
    {
        return $this->files;
    }
}
namespace Royalcms\Component\Config;

interface LoaderInterface
{
    public function load($environment, $group, $namespace = null);
    public function exists($group, $namespace = null);
    public function addNamespace($namespace, $hint);
    public function getNamespaces();
    public function cascadePackage($environment, $package, $group, $items);
}
namespace Royalcms\Component\Config;

interface EnvironmentVariablesLoaderInterface
{
    public function load($environment = null);
}
namespace Royalcms\Component\Config;

use Royalcms\Component\Filesystem\Filesystem;
class FileEnvironmentVariablesLoader implements EnvironmentVariablesLoaderInterface
{
    protected $files;
    protected $path;
    public function __construct(Filesystem $files, $path = null)
    {
        $this->files = $files;
        $this->path = $path ?: base_path();
    }
    public function load($environment = null)
    {
        if ($environment == 'production') {
            $environment = null;
        }
        if (!$this->files->exists($path = $this->getFile($environment))) {
            return array();
        } else {
            return $this->files->getRequire($path);
        }
    }
    protected function getFile($environment)
    {
        if ($environment) {
            return $this->path . '/.env.' . $environment . '.php';
        } else {
            return $this->path . '/.env.php';
        }
    }
}
namespace Royalcms\Component\Config;

class EnvironmentVariables
{
    protected $loader;
    public function __construct(EnvironmentVariablesLoaderInterface $loader)
    {
        $this->loader = $loader;
    }
    public function load($environment = null)
    {
        foreach ($this->loader->load($environment) as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}
namespace Royalcms\Component\Config;

class Dotenv
{
    protected static $immutable = true;
    public static function load($path, $file = '.env')
    {
        if (!is_string($file)) {
            $file = '.env';
        }
        $filePath = rtrim($path, '/') . '/' . $file;
        if (!is_readable($filePath) || !is_file($filePath)) {
            throw new \InvalidArgumentException(sprintf('Dotenv: Environment file %s not found or not readable. ' . 'Create file with your environment settings at %s', $file, $filePath));
        }
        $autodetect = ini_get('auto_detect_line_endings');
        ini_set('auto_detect_line_endings', '1');
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        ini_set('auto_detect_line_endings', $autodetect);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                static::setEnvironmentVariable($line);
            }
        }
    }
    public static function setEnvironmentVariable($name, $value = null)
    {
        list($name, $value) = static::normaliseEnvironmentVariable($name, $value);
        if (static::$immutable === true && !is_null(static::findEnvironmentVariable($name))) {
            return;
        }
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
    public static function required($environmentVariables, array $allowedValues = array())
    {
        $environmentVariables = (array) $environmentVariables;
        $missingEnvironmentVariables = array();
        foreach ($environmentVariables as $environmentVariable) {
            $value = static::findEnvironmentVariable($environmentVariable);
            if (is_null($value)) {
                $missingEnvironmentVariables[] = $environmentVariable;
            } elseif ($allowedValues) {
                if (!in_array($value, $allowedValues)) {
                    $missingEnvironmentVariables[] = $environmentVariable;
                }
            }
        }
        if ($missingEnvironmentVariables) {
            throw new \RuntimeException(sprintf('Required environment variable missing, or value not allowed: \'%s\'', implode('\', \'', $missingEnvironmentVariables)));
        }
        return true;
    }
    protected static function normaliseEnvironmentVariable($name, $value)
    {
        list($name, $value) = static::splitCompoundStringIntoParts($name, $value);
        $name = static::sanitiseVariableName($name);
        $value = static::sanitiseVariableValue($value);
        $value = static::resolveNestedVariables($value);
        return array($name, $value);
    }
    protected static function splitCompoundStringIntoParts($name, $value)
    {
        if (strpos($name, '=') !== false) {
            list($name, $value) = array_map('trim', explode('=', $name, 2));
        }
        return array($name, $value);
    }
    protected static function sanitiseVariableValue($value)
    {
        $value = trim($value);
        if (!$value) {
            return '';
        }
        if (strpbrk($value[0], '"\'') !== false) {
            $quote = $value[0];
            $regexPattern = sprintf('/^
                %1$s          # match a quote at the start of the value
                (             # capturing sub-pattern used
                 (?:          # we do not need to capture this
                  [^%1$s\\\\] # any character other than a quote or backslash
                  |\\\\\\\\   # or two backslashes together
                  |\\\\%1$s   # or an escaped quote e.g \\"
                 )*           # as many characters that match the previous rules
                )             # end of the capturing sub-pattern
                %1$s          # and the closing quote
                .*$           # and discard any string after the closing quote
                /mx', $quote);
            $value = preg_replace($regexPattern, '$1', $value);
            $value = str_replace("\\{$quote}", $quote, $value);
            $value = str_replace('\\\\', '\\', $value);
        } else {
            $parts = explode(' #', $value, 2);
            $value = $parts[0];
        }
        return trim($value);
    }
    protected static function sanitiseVariableName($name)
    {
        return trim(str_replace(array('export ', '\'', '"'), '', $name));
    }
    protected static function resolveNestedVariables($value)
    {
        if (strpos($value, '$') !== false) {
            $value = preg_replace_callback('/{\\$([a-zA-Z0-9_]+)}/', function ($matchedPatterns) {
                $nestedVariable = Dotenv::findEnvironmentVariable($matchedPatterns[1]);
                if (is_null($nestedVariable)) {
                    return $matchedPatterns[0];
                } else {
                    return $nestedVariable;
                }
            }, $value);
        }
        return $value;
    }
    public static function findEnvironmentVariable($name)
    {
        switch (true) {
            case array_key_exists($name, $_ENV):
                return $_ENV[$name];
            case array_key_exists($name, $_SERVER):
                return $_SERVER[$name];
            default:
                $value = getenv($name);
                return $value === false ? null : $value;
        }
    }
    public static function isImmutable()
    {
        return static::$immutable;
    }
    public static function makeImmutable()
    {
        static::$immutable = true;
    }
    public static function makeMutable()
    {
        static::$immutable = false;
    }
}
namespace Royalcms\Component\Filesystem;

use Closure;
use BadMethodCallException;
use FilesystemIterator;
use ErrorException;
use Symfony\Component\Finder\Finder;
use Royalcms\Component\Support\Contracts\Filesystem\FileNotFoundException;
class Filesystem
{
    protected static $macros = array();
    public static function macro($name, callable $macro)
    {
        static::$macros[$name] = $macro;
    }
    public static function hasMacro($name)
    {
        return isset(static::$macros[$name]);
    }
    public static function __callStatic($method, $parameters)
    {
        if (static::hasMacro($method)) {
            if (static::$macros[$method] instanceof Closure) {
                return call_user_func_array(Closure::bind(static::$macros[$method], null, get_called_class()), $parameters);
            } else {
                return call_user_func_array(static::$macros[$method], $parameters);
            }
        }
        throw new BadMethodCallException("Method {$method} does not exist.");
    }
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            if (static::$macros[$method] instanceof Closure) {
                return call_user_func_array(static::$macros[$method]->bindTo($this, get_class($this)), $parameters);
            } else {
                return call_user_func_array(static::$macros[$method], $parameters);
            }
        }
        throw new BadMethodCallException("Method {$method} does not exist.");
    }
    public function exists($path)
    {
        return file_exists($path);
    }
    public function get($path)
    {
        if ($this->isFile($path)) {
            return file_get_contents($path);
        }
        throw new FileNotFoundException("File does not exist at path {$path}");
    }
    public function getRequire($path)
    {
        if ($this->isFile($path)) {
            return require $path;
        }
        throw new FileNotFoundException("File does not exist at path {$path}");
    }
    public function requireOnce($file)
    {
        require_once $file;
    }
    public function put($path, $contents)
    {
        return file_put_contents($path, $contents);
    }
    public function prepend($path, $data)
    {
        if ($this->exists($path)) {
            return $this->put($path, $data . $this->get($path));
        } else {
            return $this->put($path, $data);
        }
    }
    public function append($path, $data)
    {
        return file_put_contents($path, $data, FILE_APPEND);
    }
    public function delete($paths)
    {
        $paths = is_array($paths) ? $paths : func_get_args();
        $success = true;
        foreach ($paths as $path) {
            if (!@unlink($path)) {
                $success = false;
            }
        }
        return $success;
    }
    public function move($path, $target)
    {
        return rename($path, $target);
    }
    public function copy($path, $target)
    {
        return copy($path, $target);
    }
    public function extension($path)
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }
    public function type($path)
    {
        return filetype($path);
    }
    public function size($path)
    {
        return filesize($path);
    }
    public function lastModified($path)
    {
        return filemtime($path);
    }
    public function isDirectory($directory)
    {
        return is_dir($directory);
    }
    public function isWritable($path)
    {
        return is_writable($path);
    }
    public function isFile($file)
    {
        return is_file($file);
    }
    public function glob($pattern, $flags = 0)
    {
        return glob($pattern, $flags);
    }
    public function files($directory)
    {
        $glob = glob($directory . '/*');
        if ($glob === false) {
            return array();
        }
        return array_filter($glob, function ($file) {
            return filetype($file) == 'file';
        });
    }
    public function allFiles($directory)
    {
        return iterator_to_array(Finder::create()->files()->in($directory), false);
    }
    public function directories($directory)
    {
        $directories = array();
        foreach (Finder::create()->in($directory)->directories()->depth(0) as $dir) {
            $directories[] = $dir->getPathname();
        }
        return $directories;
    }
    public function makeDirectory($path, $mode = 511, $recursive = false, $force = false)
    {
        if ($force) {
            return @mkdir($path, $mode, $recursive);
        } else {
            return mkdir($path, $mode, $recursive);
        }
    }
    public function copyDirectory($directory, $destination, $options = null)
    {
        if (!$this->isDirectory($directory)) {
            return false;
        }
        $options = $options ?: FilesystemIterator::SKIP_DOTS;
        if (!$this->isDirectory($destination)) {
            $this->makeDirectory($destination, 511, true);
        }
        $items = new FilesystemIterator($directory, $options);
        foreach ($items as $item) {
            $target = $destination . '/' . $item->getBasename();
            if ($item->isDir()) {
                $path = $item->getPathname();
                if (!$this->copyDirectory($path, $target, $options)) {
                    return false;
                }
            } else {
                if (!$this->copy($item->getPathname(), $target)) {
                    return false;
                }
            }
        }
        return true;
    }
    public function deleteDirectory($directory, $preserve = false)
    {
        if (!$this->isDirectory($directory)) {
            return false;
        }
        $items = new FilesystemIterator($directory);
        foreach ($items as $item) {
            if ($item->isDir()) {
                $this->deleteDirectory($item->getPathname());
            } else {
                $this->delete($item->getPathname());
            }
        }
        if (!$preserve) {
            @rmdir($directory);
        }
        return true;
    }
    public function cleanDirectory($directory)
    {
        return $this->deleteDirectory($directory, true);
    }
}
namespace Royalcms\Component\Foundation;

class AliasLoader
{
    protected $aliases;
    protected $registered = false;
    protected static $instance;
    public function __construct(array $aliases = array())
    {
        $this->aliases = $aliases;
    }
    public static function getInstance(array $aliases = array())
    {
        if (is_null(static::$instance)) {
            static::$instance = new static($aliases);
        }
        $aliases = array_merge(static::$instance->getAliases(), $aliases);
        static::$instance->setAliases($aliases);
        return static::$instance;
    }
    public function load($alias)
    {
        if (isset($this->aliases[$alias])) {
            return class_alias($this->aliases[$alias], $alias);
        }
    }
    public function alias($class, $alias)
    {
        $this->aliases[$class] = $alias;
    }
    public function register()
    {
        if (!$this->registered) {
            $this->prependToLoaderStack();
            $this->registered = true;
        }
    }
    protected function prependToLoaderStack()
    {
        spl_autoload_register(array($this, 'load'), true, true);
    }
    public function getAliases()
    {
        return $this->aliases;
    }
    public function setAliases(array $aliases)
    {
        $this->aliases = $aliases;
    }
    public function isRegistered()
    {
        return $this->registered;
    }
    public function setRegistered($value)
    {
        $this->registered = $value;
    }
    public static function setInstance($loader)
    {
        static::$instance = $loader;
    }
}
namespace Royalcms\Component\Foundation;

use Royalcms\Component\Filesystem\Filesystem;
class ProviderRepository
{
    protected $files;
    protected $manifestPath;
    protected $default = array('when' => array());
    public function __construct(Filesystem $files, $manifestPath)
    {
        $this->files = $files;
        $this->manifestPath = $manifestPath;
    }
    public function load(Royalcms $royalcms, array $providers)
    {
        $manifest = $this->loadManifest();
        if ($this->shouldRecompile($manifest, $providers)) {
            $manifest = $this->compileManifest($royalcms, $providers);
        }
        if ($royalcms->runningInConsole()) {
            $manifest['eager'] = $manifest['providers'];
        }
        foreach ($manifest['when'] as $provider => $events) {
            $this->registerLoadEvents($royalcms, $provider, $events);
        }
        foreach ($manifest['eager'] as $provider) {
            $royalcms->register($this->createProvider($royalcms, $provider));
        }
        $royalcms->setDeferredServices($manifest['deferred']);
    }
    protected function registerLoadEvents(Royalcms $royalcms, $provider, array $events)
    {
        if (count($events) < 1) {
            return;
        }
        $royalcms->make('events')->listen($events, function () use($royalcms, $provider) {
            $royalcms->register($provider);
        });
    }
    protected function compileManifest(Royalcms $royalcms, $providers)
    {
        $manifest = $this->freshManifest($providers);
        foreach ($providers as $provider) {
            $instance = $this->createProvider($royalcms, $provider);
            if ($instance->isDeferred()) {
                foreach ($instance->provides() as $service) {
                    $manifest['deferred'][$service] = $provider;
                }
                $manifest['when'][$provider] = $instance->when();
            } else {
                $manifest['eager'][] = $provider;
            }
        }
        return $this->writeManifest($manifest);
    }
    public function createProvider(Royalcms $royalcms, $provider)
    {
        return new $provider($royalcms);
    }
    public function shouldRecompile($manifest, $providers)
    {
        return is_null($manifest) || $manifest['providers'] != $providers;
    }
    public function loadManifest()
    {
        $path = $this->manifestPath . '/services.json';
        if ($this->files->exists($path)) {
            $manifest = json_decode($this->files->get($path), true);
            return array_merge($this->default, $manifest);
        }
    }
    public function writeManifest($manifest)
    {
        if (!$this->files->isDirectory($this->manifestPath)) {
            $this->files->makeDirectory($this->manifestPath, 493, true);
        }
        $path = $this->manifestPath . '/services.json';
        $this->files->put($path, json_encode($manifest));
        return $manifest;
    }
    protected function freshManifest(array $providers)
    {
        list($eager, $deferred) = array(array(), array());
        return compact('providers', 'eager', 'deferred');
    }
    public function getFilesystem()
    {
        return $this->files;
    }
}
namespace Royalcms\Component\Foundation\Providers;

use Royalcms\Component\Support\ServiceProvider;
use Royalcms\Component\Foundation\Phpinfo;
class PhpinfoServiceProvider extends ServiceProvider
{
    protected $defer = true;
    public function register()
    {
        $this->royalcms->bindShared('phpinfo', function ($royalcms) {
            return new Phpinfo();
        });
    }
    public function provides()
    {
        return array('phpinfo');
    }
}
namespace Royalcms\Component\Cookie;

use Royalcms\Component\Support\ServiceProvider;
class CookieServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->royalcms->bindShared('cookie', function ($royalcms) {
            $config = $royalcms['config']['cookie'];
            return with(new CookieJar())->setDefaultPathAndDomain($config['path'], $config['domain']);
        });
    }
}
namespace Royalcms\Component\Database;

use Royalcms\Component\Database\Eloquent\Model;
use Royalcms\Component\Support\ServiceProvider;
use Royalcms\Component\Database\Connectors\ConnectionFactory;
class DatabaseServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Model::setConnectionResolver($this->royalcms['db']);
        Model::setEventDispatcher($this->royalcms['events']);
    }
    public function register()
    {
        $this->royalcms->bindShared('db.factory', function ($royalcms) {
            return new ConnectionFactory($royalcms);
        });
        $this->royalcms->bindShared('db', function ($royalcms) {
            return new DatabaseManager($royalcms, $royalcms['db.factory']);
        });
    }
}
namespace Royalcms\Component\Encryption;

use Royalcms\Component\Support\ServiceProvider;
class EncryptionServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->royalcms->bindShared('encrypter', function ($royalcms) {
            return new Encrypter($royalcms['config']['system.auth_key']);
        });
    }
}
namespace Royalcms\Component\Filesystem;

use Royalcms\Component\Support\ServiceProvider;
class FilesystemServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->registerNativeFilesystem();
        $this->registerFlysystem();
    }
    protected function registerNativeFilesystem()
    {
        $this->royalcms->bindShared('files', function () {
            return new Filesystem();
        });
    }
    protected function registerFlysystem()
    {
        $this->registerManager();
        $this->royalcms->bindShared('filesystem.disk', function ($royalcms) {
            return $royalcms['filesystem']->disk($this->getDefaultDriver());
        });
        $this->royalcms->bindShared('filesystem.cloud', function ($royalcms) {
            return $royalcms['filesystem']->disk($this->getCloudDriver());
        });
    }
    protected function registerManager()
    {
        $this->royalcms->bindShared('filesystem', function ($royalcms) {
            return new FilesystemManager($royalcms);
        });
    }
    protected function getDefaultDriver()
    {
        return $this->royalcms['config']['filesystems.default'];
    }
    protected function getCloudDriver()
    {
        return $this->royalcms['config']['filesystems.cloud'];
    }
}
namespace Royalcms\Component\Session;

use Royalcms\Component\Support\ServiceProvider;
class SessionServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->setupDefaultDriver();
        $this->registerSessionManager();
        $this->registerSessionDriver();
        $this->registerStartSession();
    }
    protected function setupDefaultDriver()
    {
        if ($this->royalcms->runningInConsole()) {
            $this->royalcms['config']['session.driver'] = 'array';
        }
    }
    protected function registerSessionManager()
    {
        $this->royalcms->bindShared('session', function ($royalcms) {
            return new SessionManager($royalcms);
        });
    }
    protected function registerSessionDriver()
    {
        $this->royalcms->bindShared('session.store', function ($royalcms) {
            $manager = $royalcms['session'];
            return $manager->driver();
        });
    }
    protected function registerStartSession()
    {
        $this->royalcms->bindShared('session.start', function ($royalcms) {
            $manager = $royalcms['session'];
            return new StartSession($manager);
        });
    }
    protected function registerSessionMiddleware()
    {
        $royalcms = $this->royalcms;
        $sessionReject = $royalcms->bound('session.reject') ? $royalcms['session.reject'] : null;
        $royalcms->middleware('Royalcms\\Component\\Session\\Middleware', array($royalcms['session'], $sessionReject));
    }
    protected function getDriver()
    {
        return $this->royalcms['config']['session.driver'];
    }
}
namespace Royalcms\Component\View;

use Royalcms\Component\Support\MessageBag;
use Royalcms\Component\View\Engines\PhpEngine;
use Royalcms\Component\Support\ServiceProvider;
use Royalcms\Component\View\Engines\EngineResolver;
class ViewServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->registerEngineResolver();
        $this->registerViewFinder();
        $this->registerEnvironment();
        $this->registerSessionBinder();
    }
    public function registerEngineResolver()
    {
        $me = $this;
        $this->royalcms->bindShared('view.engine.resolver', function ($royalcms) use($me) {
            $resolver = new EngineResolver();
            foreach (array('php') as $engine) {
                $me->{'register' . ucfirst($engine) . 'Engine'}($resolver);
            }
            return $resolver;
        });
    }
    public function registerPhpEngine($resolver)
    {
        $resolver->register('php', function () {
            return new PhpEngine();
        });
    }
    public function registerViewFinder()
    {
        $this->royalcms->bindShared('view.finder', function ($royalcms) {
            $paths = $royalcms['config']['view.paths'];
            return new FileViewFinder($royalcms['files'], $paths);
        });
    }
    public function registerEnvironment()
    {
        $this->royalcms->bindShared('view', function ($royalcms) {
            $resolver = $royalcms['view.engine.resolver'];
            $finder = $royalcms['view.finder'];
            $env = new Environment($resolver, $finder, $royalcms['events']);
            $env->setContainer($royalcms);
            $env->share('royalcms', $royalcms);
            return $env;
        });
    }
    protected function registerSessionBinder()
    {
        list($royalcms, $me) = array($this->royalcms, $this);
        $royalcms->booted(function () use($royalcms, $me) {
            if ($me->sessionHasErrors($royalcms)) {
                $errors = $royalcms['session.store']->get('errors');
                $royalcms['view']->share('errors', $errors);
            } else {
                $royalcms['view']->share('errors', new MessageBag());
            }
        });
    }
    public function sessionHasErrors($royalcms)
    {
        $config = $royalcms['config']['session'];
        if (isset($royalcms['session.store']) && !is_null($config['driver'])) {
            return $royalcms['session.store']->has('errors');
        }
    }
}
namespace Royalcms\Component\FilesystemKernel;

use Royalcms\Component\Support\ServiceProvider;
class FilesystemServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->registerManager();
        $this->royalcms->bindShared('filesystemkernel.disk', function ($royalcms) {
            return $royalcms['filesystemkernel']->disk($this->getDefaultDriver());
        });
        $this->royalcms->bindShared('filesystemkernel.cloud', function ($royalcms) {
            return $royalcms['filesystemkernel']->disk($this->getCloudDriver());
        });
    }
    protected function registerManager()
    {
        $this->royalcms->bindShared('filesystemkernel', function ($royalcms) {
            return new FilesystemManager($royalcms);
        });
    }
    protected function getDefaultDriver()
    {
        return $this->royalcms['config']['filesystems.default'];
    }
    protected function getCloudDriver()
    {
        return $this->royalcms['config']['filesystems.cloud'];
    }
}
namespace Royalcms\Component\Hook;

use Royalcms\Component\Support\ServiceProvider;
class HookServiceProvider extends ServiceProvider
{
    protected $defer = true;
    public function register()
    {
        $this->registerHooks();
    }
    protected function registerHooks()
    {
        $this->royalcms->bindShared('hook', function ($royalcms) {
            return new Hooks();
        });
    }
    public function provides()
    {
        return array('hook');
    }
}
namespace Royalcms\Component\Error;

use Royalcms\Component\Support\ServiceProvider;
class ErrorServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->royalcms->bindShared('error', function ($royalcms) {
            return new Error();
        });
    }
}
namespace Royalcms\Component\Timer;

use Royalcms\Component\Support\ServiceProvider;
class TimerServiceProvider extends ServiceProvider
{
    protected $defer = false;
    public function register()
    {
        $this->royalcms->singleton('timer', function () {
            $startTime = defined('ROYALCMS_START') ? ROYALCMS_START : null;
            return new Timer($startTime);
        });
        $this->loadAlias();
    }
    protected function loadAlias()
    {
        $this->royalcms->booting(function () {
            $loader = \Royalcms\Component\Foundation\AliasLoader::getInstance();
            $loader->alias('RC_Timer', 'Royalcms\\Component\\Timer\\Facades\\Timer');
        });
    }
}
namespace Royalcms\Component\Routing;

interface RouteFiltererInterface
{
    public function filter($name, $callback);
    public function callRouteFilter($filter, $parameters, $route, $request, $response = null);
}
namespace Royalcms\Component\Routing;

use Closure;
use Royalcms\Component\HttpKernel\Request;
use Royalcms\Component\HttpKernel\Response;
use Royalcms\Component\Event\Dispatcher;
use Royalcms\Component\Container\Container;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
class Router implements HttpKernelInterface, RouteFiltererInterface
{
    protected $events;
    protected $container;
    protected $routes;
    protected $current;
    protected $currentRequest;
    protected $controllerDispatcher;
    protected $inspector;
    protected $filtering = true;
    protected $patternFilters = array();
    protected $regexFilters = array();
    protected $binders = array();
    protected $patterns = array();
    protected $groupStack = array();
    public static $verbs = array('GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS');
    protected $resourceDefaults = array('index', 'create', 'store', 'show', 'edit', 'update', 'destroy');
    public function __construct(Dispatcher $events, Container $container = null)
    {
        $this->events = $events;
        $this->routes = new RouteCollection();
        $this->container = $container ?: new Container();
        $this->bind('_missing', function ($v) {
            return explode('/', $v);
        });
    }
    public function get($uri, $action)
    {
        return $this->addRoute(array('GET', 'HEAD'), $uri, $action);
    }
    public function post($uri, $action)
    {
        return $this->addRoute('POST', $uri, $action);
    }
    public function put($uri, $action)
    {
        return $this->addRoute('PUT', $uri, $action);
    }
    public function patch($uri, $action)
    {
        return $this->addRoute('PATCH', $uri, $action);
    }
    public function delete($uri, $action)
    {
        return $this->addRoute('DELETE', $uri, $action);
    }
    public function options($uri, $action)
    {
        return $this->addRoute('OPTIONS', $uri, $action);
    }
    public function any($uri, $action)
    {
        $verbs = array('GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE');
        return $this->addRoute($verbs, $uri, $action);
    }
    public function match($methods, $uri, $action)
    {
        return $this->addRoute($methods, $uri, $action);
    }
    public function controllers(array $controllers)
    {
        foreach ($controllers as $uri => $name) {
            $this->controller($uri, $name);
        }
    }
    public function controller($uri, $controller, $names = array())
    {
        $prepended = $controller;
        if (count($this->groupStack) > 0) {
            $prepended = $this->prependGroupUses($controller);
        }
        $routable = $this->getInspector()->getRoutable($prepended, $uri);
        foreach ($routable as $method => $routes) {
            foreach ($routes as $route) {
                $this->registerInspected($route, $controller, $method, $names);
            }
        }
        $this->addFallthroughRoute($controller, $uri);
    }
    protected function registerInspected($route, $controller, $method, &$names)
    {
        $action = array('uses' => $controller . '@' . $method);
        $action['as'] = array_pull($names, $method);
        $this->{$route['verb']}($route['uri'], $action);
    }
    protected function addFallthroughRoute($controller, $uri)
    {
        $missing = $this->any($uri . '/{_missing}', $controller . '@missingMethod');
        $missing->where('_missing', '(.*)');
    }
    public function resource($name, $controller, array $options = array())
    {
        if (str_contains($name, '/')) {
            $this->prefixedResource($name, $controller, $options);
            return;
        }
        $base = $this->getResourceWildcard(array_last(explode('.', $name)));
        $defaults = $this->resourceDefaults;
        foreach ($this->getResourceMethods($defaults, $options) as $m) {
            $this->{'addResource' . ucfirst($m)}($name, $base, $controller, $options);
        }
    }
    protected function prefixedResource($name, $controller, array $options)
    {
        list($name, $prefix) = $this->getResourcePrefix($name);
        $callback = function ($me) use($name, $controller, $options) {
            $me->resource($name, $controller, $options);
        };
        return $this->group(compact('prefix'), $callback);
    }
    protected function getResourcePrefix($name)
    {
        $segments = explode('/', $name);
        $prefix = implode('/', array_slice($segments, 0, -1));
        return array($segments[count($segments) - 1], $prefix);
    }
    protected function getResourceMethods($defaults, $options)
    {
        if (isset($options['only'])) {
            return array_intersect($defaults, $options['only']);
        } elseif (isset($options['except'])) {
            return array_diff($defaults, $options['except']);
        }
        return $defaults;
    }
    public function getResourceUri($resource)
    {
        if (!str_contains($resource, '.')) {
            return $resource;
        }
        $segments = explode('.', $resource);
        $uri = $this->getNestedResourceUri($segments);
        return str_replace('/{' . $this->getResourceWildcard(array_last($segments)) . '}', '', $uri);
    }
    protected function getNestedResourceUri(array $segments)
    {
        $me = $this;
        return implode('/', array_map(function ($s) use($me) {
            return $s . '/{' . $me->getResourceWildcard($s) . '}';
        }, $segments));
    }
    protected function getResourceAction($resource, $controller, $method, $options)
    {
        $name = $this->getResourceName($resource, $method, $options);
        return array('as' => $name, 'uses' => $controller . '@' . $method);
    }
    protected function getResourceName($resource, $method, $options)
    {
        if (isset($options['names'][$method])) {
            return $options['names'][$method];
        }
        $prefix = isset($options['as']) ? $options['as'] . '.' : '';
        if (count($this->groupStack) == 0) {
            return $prefix . $resource . '.' . $method;
        }
        return $this->getGroupResourceName($prefix, $resource, $method);
    }
    protected function getGroupResourceName($prefix, $resource, $method)
    {
        $group = str_replace('/', '.', $this->getLastGroupPrefix());
        return trim("{$prefix}{$group}.{$resource}.{$method}", '.');
    }
    public function getResourceWildcard($value)
    {
        return str_replace('-', '_', $value);
    }
    protected function addResourceIndex($name, $base, $controller, $options)
    {
        $action = $this->getResourceAction($name, $controller, 'index', $options);
        return $this->get($this->getResourceUri($name), $action);
    }
    protected function addResourceCreate($name, $base, $controller, $options)
    {
        $action = $this->getResourceAction($name, $controller, 'create', $options);
        return $this->get($this->getResourceUri($name) . '/create', $action);
    }
    protected function addResourceStore($name, $base, $controller, $options)
    {
        $action = $this->getResourceAction($name, $controller, 'store', $options);
        return $this->post($this->getResourceUri($name), $action);
    }
    protected function addResourceShow($name, $base, $controller, $options)
    {
        $uri = $this->getResourceUri($name) . '/{' . $base . '}';
        return $this->get($uri, $this->getResourceAction($name, $controller, 'show', $options));
    }
    protected function addResourceEdit($name, $base, $controller, $options)
    {
        $uri = $this->getResourceUri($name) . '/{' . $base . '}/edit';
        return $this->get($uri, $this->getResourceAction($name, $controller, 'edit', $options));
    }
    protected function addResourceUpdate($name, $base, $controller, $options)
    {
        $this->addPutResourceUpdate($name, $base, $controller, $options);
        return $this->addPatchResourceUpdate($name, $base, $controller);
    }
    protected function addPutResourceUpdate($name, $base, $controller, $options)
    {
        $uri = $this->getResourceUri($name) . '/{' . $base . '}';
        return $this->put($uri, $this->getResourceAction($name, $controller, 'update', $options));
    }
    protected function addPatchResourceUpdate($name, $base, $controller)
    {
        $uri = $this->getResourceUri($name) . '/{' . $base . '}';
        $this->patch($uri, $controller . '@update');
    }
    protected function addResourceDestroy($name, $base, $controller, $options)
    {
        $action = $this->getResourceAction($name, $controller, 'destroy', $options);
        return $this->delete($this->getResourceUri($name) . '/{' . $base . '}', $action);
    }
    public function group(array $attributes, Closure $callback)
    {
        $this->updateGroupStack($attributes);
        call_user_func($callback, $this);
        array_pop($this->groupStack);
    }
    protected function updateGroupStack(array $attributes)
    {
        if (count($this->groupStack) > 0) {
            $attributes = $this->mergeGroup($attributes, array_last($this->groupStack));
        }
        $this->groupStack[] = $attributes;
    }
    public function mergeWithLastGroup($new)
    {
        return $this->mergeGroup($new, array_last($this->groupStack));
    }
    public static function mergeGroup($new, $old)
    {
        $new['namespace'] = static::formatUsesPrefix($new, $old);
        $new['prefix'] = static::formatGroupPrefix($new, $old);
        if (isset($new['domain'])) {
            unset($old['domain']);
        }
        return array_merge_recursive(array_except($old, array('namespace', 'prefix')), $new);
    }
    protected static function formatUsesPrefix($new, $old)
    {
        if (isset($new['namespace']) && isset($old['namespace'])) {
            return trim(array_get($old, 'namespace'), '\\') . '\\' . trim($new['namespace'], '\\');
        } elseif (isset($new['namespace'])) {
            return trim($new['namespace'], '\\');
        }
        return array_get($old, 'namespace');
    }
    protected static function formatGroupPrefix($new, $old)
    {
        if (isset($new['prefix'])) {
            return trim(array_get($old, 'prefix'), '/') . '/' . trim($new['prefix'], '/');
        }
        return array_get($old, 'prefix');
    }
    protected function getLastGroupPrefix()
    {
        if (count($this->groupStack) > 0) {
            return array_get(array_last($this->groupStack), 'prefix', '');
        }
        return '';
    }
    protected function addRoute($methods, $uri, $action)
    {
        return $this->routes->add($this->createRoute($methods, $uri, $action));
    }
    protected function createRoute($methods, $uri, $action)
    {
        if ($this->routingToController($action)) {
            $action = $this->getControllerAction($action);
        }
        $route = $this->newRoute($methods, $uri = $this->prefix($uri), $action);
        $route->where($this->patterns);
        if (count($this->groupStack) > 0) {
            $this->mergeController($route);
        }
        return $route;
    }
    protected function newRoute($methods, $uri, $action)
    {
        return new Route($methods, $uri, $action);
    }
    protected function prefix($uri)
    {
        return trim(trim($this->getLastGroupPrefix(), '/') . '/' . trim($uri, '/'), '/') ?: '/';
    }
    protected function mergeController($route)
    {
        $action = $this->mergeWithLastGroup($route->getAction());
        $route->setAction($action);
    }
    protected function routingToController($action)
    {
        if ($action instanceof Closure) {
            return false;
        }
        return is_string($action) || is_string(array_get($action, 'uses'));
    }
    protected function getControllerAction($action)
    {
        if (is_string($action)) {
            $action = array('uses' => $action);
        }
        if (count($this->groupStack) > 0) {
            $action['uses'] = $this->prependGroupUses($action['uses']);
        }
        $action['controller'] = $action['uses'];
        $closure = $this->getClassClosure($action['uses']);
        return array_set($action, 'uses', $closure);
    }
    protected function getClassClosure($controller)
    {
        $me = $this;
        $d = $this->getControllerDispatcher();
        return function () use($me, $d, $controller) {
            $route = $me->current();
            $request = $me->getCurrentRequest();
            list($class, $method) = explode('@', $controller);
            return $d->dispatch($route, $request, $class, $method);
        };
    }
    protected function prependGroupUses($uses)
    {
        $group = array_last($this->groupStack);
        return isset($group['namespace']) ? $group['namespace'] . '\\' . $uses : $uses;
    }
    public function dispatch(Request $request)
    {
        $this->currentRequest = $request;
        $response = $this->callFilter('before', $request);
        if (is_null($response)) {
            $response = $this->dispatchToRoute($request);
        }
        $response = $this->prepareResponse($request, $response);
        $this->callFilter('after', $request, $response);
        return $response;
    }
    public function dispatchToRoute(Request $request)
    {
        $route = $this->findRoute($request);
        $this->events->fire('router.matched', array($route, $request));
        $response = $this->callRouteBefore($route, $request);
        if (is_null($response)) {
            $response = $route->run($request);
        }
        $response = $this->prepareResponse($request, $response);
        $this->callRouteAfter($route, $request, $response);
        return $response;
    }
    protected function findRoute($request)
    {
        $this->current = $route = $this->routes->match($request);
        return $this->substituteBindings($route);
    }
    protected function substituteBindings($route)
    {
        foreach ($route->parameters() as $key => $value) {
            if (isset($this->binders[$key])) {
                $route->setParameter($key, $this->performBinding($key, $value, $route));
            }
        }
        return $route;
    }
    protected function performBinding($key, $value, $route)
    {
        return call_user_func($this->binders[$key], $value, $route);
    }
    public function matched($callback)
    {
        $this->events->listen('router.matched', $callback);
    }
    public function before($callback)
    {
        $this->addGlobalFilter('before', $callback);
    }
    public function after($callback)
    {
        $this->addGlobalFilter('after', $callback);
    }
    protected function addGlobalFilter($filter, $callback)
    {
        $this->events->listen('router.' . $filter, $this->parseFilter($callback));
    }
    public function filter($name, $callback)
    {
        $this->events->listen('router.filter: ' . $name, $this->parseFilter($callback));
    }
    protected function parseFilter($callback)
    {
        if (is_string($callback) && !str_contains($callback, '@')) {
            return $callback . '@filter';
        } else {
            return $callback;
        }
    }
    public function when($pattern, $name, $methods = null)
    {
        if (!is_null($methods)) {
            $methods = array_map('strtoupper', (array) $methods);
        }
        $this->patternFilters[$pattern][] = compact('name', 'methods');
    }
    public function whenRegex($pattern, $name, $methods = null)
    {
        if (!is_null($methods)) {
            $methods = array_map('strtoupper', (array) $methods);
        }
        $this->regexFilters[$pattern][] = compact('name', 'methods');
    }
    public function model($key, $class, Closure $callback = null)
    {
        return $this->bind($key, function ($value) use($class, $callback) {
            if (is_null($value)) {
                return null;
            }
            if (($model = with(new $class())->find($value)) !== false) {
                return $model;
            }
            if ($callback instanceof Closure) {
                return call_user_func($callback);
            }
            throw new NotFoundHttpException();
        });
    }
    public function bind($key, $binder)
    {
        $this->binders[str_replace('-', '_', $key)] = $binder;
    }
    public function pattern($key, $pattern)
    {
        $this->patterns[$key] = $pattern;
    }
    protected function callFilter($filter, $request, $response = null)
    {
        if (!$this->filtering) {
            return null;
        }
        return $this->events->until('router.' . $filter, array($request, $response));
    }
    public function callRouteBefore($route, $request)
    {
        $response = $this->callPatternFilters($route, $request);
        return $response ?: $this->callAttachedBefores($route, $request);
    }
    protected function callPatternFilters($route, $request)
    {
        foreach ($this->findPatternFilters($request) as $filter => $parameters) {
            $response = $this->callRouteFilter($filter, $parameters, $route, $request);
            if (!is_null($response)) {
                return $response;
            }
        }
    }
    public function findPatternFilters($request)
    {
        $results = array();
        list($path, $method) = array($request->path(), $request->getMethod());
        foreach ($this->patternFilters as $pattern => $filters) {
            if (str_is($pattern, $path)) {
                $merge = $this->patternsByMethod($method, $filters);
                $results = array_merge($results, $merge);
            }
        }
        foreach ($this->regexFilters as $pattern => $filters) {
            if (preg_match($pattern, $path)) {
                $merge = $this->patternsByMethod($method, $filters);
                $results = array_merge($results, $merge);
            }
        }
        return $results;
    }
    protected function patternsByMethod($method, $filters)
    {
        $results = array();
        foreach ($filters as $filter) {
            if ($this->filterSupportsMethod($filter, $method)) {
                $parsed = Route::parseFilters($filter['name']);
                $results = array_merge($results, $parsed);
            }
        }
        return $results;
    }
    protected function filterSupportsMethod($filter, $method)
    {
        $methods = $filter['methods'];
        return is_null($methods) || in_array($method, $methods);
    }
    protected function callAttachedBefores($route, $request)
    {
        foreach ($route->beforeFilters() as $filter => $parameters) {
            $response = $this->callRouteFilter($filter, $parameters, $route, $request);
            if (!is_null($response)) {
                return $response;
            }
        }
    }
    public function callRouteAfter($route, $request, $response)
    {
        foreach ($route->afterFilters() as $filter => $parameters) {
            $this->callRouteFilter($filter, $parameters, $route, $request, $response);
        }
    }
    public function callRouteFilter($filter, $parameters, $route, $request, $response = null)
    {
        if (!$this->filtering) {
            return null;
        }
        $data = array_merge(array($route, $request, $response), $parameters);
        return $this->events->until('router.filter: ' . $filter, $this->cleanFilterParameters($data));
    }
    protected function cleanFilterParameters(array $parameters)
    {
        return array_filter($parameters, function ($p) {
            return !is_null($p) && $p !== '';
        });
    }
    protected function prepareResponse($request, $response)
    {
        if (!$response instanceof SymfonyResponse) {
            $response = new Response($response);
        }
        return $response->prepare($request);
    }
    public function withoutFilters($callback)
    {
        $this->disableFilters();
        call_user_func($callback);
        $this->enableFilters();
    }
    public function enableFilters()
    {
        $this->filtering = true;
    }
    public function disableFilters()
    {
        $this->filtering = false;
    }
    public function input($key, $default = null)
    {
        return $this->current()->parameter($key, $default);
    }
    public function getCurrentRoute()
    {
        return $this->current();
    }
    public function current()
    {
        return $this->current;
    }
    public function currentRouteName()
    {
        return $this->current() ? $this->current()->getName() : null;
    }
    public function is()
    {
        foreach (func_get_args() as $pattern) {
            if (str_is($pattern, $this->currentRouteName())) {
                return true;
            }
        }
        return false;
    }
    public function currentRouteNamed($name)
    {
        return $this->current() ? $this->current()->getName() == $name : false;
    }
    public function currentRouteAction()
    {
        $action = $this->current()->getAction();
        return isset($action['controller']) ? $action['controller'] : null;
    }
    public function isAction()
    {
        foreach (func_get_args() as $pattern) {
            if (str_is($pattern, $this->currentRouteAction())) {
                return true;
            }
        }
        return false;
    }
    public function currentRouteUses($action)
    {
        return $this->currentRouteAction() == $action;
    }
    public function getCurrentRequest()
    {
        return $this->currentRequest;
    }
    public function getRoutes()
    {
        return $this->routes;
    }
    public function getControllerDispatcher()
    {
        if (is_null($this->controllerDispatcher)) {
            $this->controllerDispatcher = new ControllerDispatcher($this, $this->container);
        }
        return $this->controllerDispatcher;
    }
    public function setControllerDispatcher(ControllerDispatcher $dispatcher)
    {
        $this->controllerDispatcher = $dispatcher;
    }
    public function getInspector()
    {
        return $this->inspector ?: ($this->inspector = new ControllerInspector());
    }
    public function handle(SymfonyRequest $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        return $this->dispatch(Request::createFromBase($request));
    }
}
namespace Royalcms\Component\Routing;

use Royalcms\Component\HttpKernel\Request;
use Royalcms\Component\Routing\Matching\UriValidator;
use Royalcms\Component\Routing\Matching\HostValidator;
use Royalcms\Component\Routing\Matching\MethodValidator;
use Royalcms\Component\Routing\Matching\SchemeValidator;
use Symfony\Component\Routing\Route as SymfonyRoute;
class Route
{
    protected $uri;
    protected $methods;
    protected $action;
    protected $defaults = array();
    protected $wheres = array();
    protected $parameters;
    protected $parameterNames;
    protected $compiled;
    protected static $validators;
    public function __construct($methods, $uri, $action)
    {
        $this->uri = $uri;
        $this->methods = (array) $methods;
        $this->action = $this->parseAction($action);
        if (isset($this->action['prefix'])) {
            $this->prefix($this->action['prefix']);
        }
    }
    public function run()
    {
        $parameters = array_filter($this->parameters(), function ($p) {
            return isset($p);
        });
        return call_user_func_array($this->action['uses'], $parameters);
    }
    public function matches(Request $request, $includingMethod = true)
    {
        $this->compileRoute();
        foreach ($this->getValidators() as $validator) {
            if (!$includingMethod && $validator instanceof MethodValidator) {
                continue;
            }
            if (!$validator->matches($this, $request)) {
                return false;
            }
        }
        return true;
    }
    protected function compileRoute()
    {
        $optionals = $this->extractOptionalParameters();
        $uri = preg_replace('/\\{(\\w+?)\\?\\}/', '{$1}', $this->uri);
        $this->compiled = with(new SymfonyRoute($uri, $optionals, $this->wheres, array(), $this->domain() ?: ''))->compile();
    }
    protected function extractOptionalParameters()
    {
        preg_match_all('/\\{(\\w+?)\\?\\}/', $this->uri, $matches);
        $optional = array();
        if (isset($matches[1])) {
            foreach ($matches[1] as $key) {
                $optional[$key] = null;
            }
        }
        return $optional;
    }
    public function beforeFilters()
    {
        if (!isset($this->action['before'])) {
            return array();
        }
        return $this->parseFilters($this->action['before']);
    }
    public function afterFilters()
    {
        if (!isset($this->action['after'])) {
            return array();
        }
        return $this->parseFilters($this->action['after']);
    }
    public static function parseFilters($filters)
    {
        return array_build(static::explodeFilters($filters), function ($key, $value) {
            return Route::parseFilter($value);
        });
    }
    protected static function explodeFilters($filters)
    {
        if (is_array($filters)) {
            return static::explodeArrayFilters($filters);
        }
        return explode('|', $filters);
    }
    protected static function explodeArrayFilters(array $filters)
    {
        $results = array();
        foreach ($filters as $filter) {
            $results = array_merge($results, explode('|', $filter));
        }
        return $results;
    }
    public static function parseFilter($filter)
    {
        if (!str_contains($filter, ':')) {
            return array($filter, array());
        }
        return static::parseParameterFilter($filter);
    }
    protected static function parseParameterFilter($filter)
    {
        list($name, $parameters) = explode(':', $filter, 2);
        return array($name, explode(',', $parameters));
    }
    public function getParameter($name, $default = null)
    {
        return $this->parameter($name, $default);
    }
    public function parameter($name, $default = null)
    {
        return array_get($this->parameters(), $name) ?: $default;
    }
    public function setParameter($name, $value)
    {
        $this->parameters();
        $this->parameters[$name] = $value;
    }
    public function forgetParameter($name)
    {
        $this->parameters();
        unset($this->parameters[$name]);
    }
    public function parameters()
    {
        if (isset($this->parameters)) {
            return array_map(function ($value) {
                return is_string($value) ? urldecode($value) : $value;
            }, $this->parameters);
        }
        throw new \LogicException('Route is not bound.');
    }
    public function parametersWithoutNulls()
    {
        return array_filter($this->parameters(), function ($p) {
            return !is_null($p);
        });
    }
    public function parameterNames()
    {
        if (isset($this->parameterNames)) {
            return $this->parameterNames;
        }
        return $this->parameterNames = $this->compileParameterNames();
    }
    protected function compileParameterNames()
    {
        preg_match_all('/\\{(.*?)\\}/', $this->domain() . $this->uri, $matches);
        return array_map(function ($m) {
            return trim($m, '?');
        }, $matches[1]);
    }
    public function bind(Request $request)
    {
        $this->compileRoute();
        $this->bindParameters($request);
        return $this;
    }
    public function bindParameters(Request $request)
    {
        $params = $this->matchToKeys(array_slice($this->bindPathParameters($request), 1));
        if (!is_null($this->compiled->getHostRegex())) {
            $params = $this->bindHostParameters($request, $params);
        }
        return $this->parameters = $this->replaceDefaults($params);
    }
    protected function bindPathParameters(Request $request)
    {
        preg_match($this->compiled->getRegex(), '/' . $request->decodedPath(), $matches);
        return $matches;
    }
    protected function bindHostParameters(Request $request, $parameters)
    {
        preg_match($this->compiled->getHostRegex(), $request->getHost(), $matches);
        return array_merge($this->matchToKeys(array_slice($matches, 1)), $parameters);
    }
    protected function matchToKeys(array $matches)
    {
        if (count($this->parameterNames()) == 0) {
            return array();
        }
        $parameters = array_intersect_key($matches, array_flip($this->parameterNames()));
        return array_filter($parameters, function ($value) {
            return is_string($value) && strlen($value) > 0;
        });
    }
    protected function replaceDefaults(array $parameters)
    {
        foreach ($parameters as $key => &$value) {
            $value = isset($value) ? $value : array_get($this->defaults, $key);
        }
        return $parameters;
    }
    protected function parseAction($action)
    {
        if (is_callable($action)) {
            return array('uses' => $action);
        } elseif (!isset($action['uses'])) {
            $action['uses'] = $this->findClosure($action);
        }
        return $action;
    }
    protected function findClosure(array $action)
    {
        return array_first($action, function ($key, $value) {
            return is_callable($value);
        });
    }
    public static function getValidators()
    {
        if (isset(static::$validators)) {
            return static::$validators;
        }
        return static::$validators = array(new MethodValidator(), new SchemeValidator(), new HostValidator(), new UriValidator());
    }
    public function before($filters)
    {
        return $this->addFilters('before', $filters);
    }
    public function after($filters)
    {
        return $this->addFilters('after', $filters);
    }
    protected function addFilters($type, $filters)
    {
        if (isset($this->action[$type])) {
            $this->action[$type] .= '|' . $filters;
        } else {
            $this->action[$type] = $filters;
        }
        return $this;
    }
    public function defaults($key, $value)
    {
        $this->defaults[$key] = $value;
        return $this;
    }
    public function where($name, $expression = null)
    {
        foreach ($this->parseWhere($name, $expression) as $name => $expression) {
            $this->wheres[$name] = $expression;
        }
        return $this;
    }
    protected function parseWhere($name, $expression)
    {
        return is_array($name) ? $name : array($name => $expression);
    }
    protected function whereArray(array $wheres)
    {
        foreach ($wheres as $name => $expression) {
            $this->where($name, $expression);
        }
        return $this;
    }
    public function prefix($prefix)
    {
        $this->uri = trim($prefix, '/') . '/' . trim($this->uri, '/');
        return $this;
    }
    public function getPath()
    {
        return $this->uri();
    }
    public function uri()
    {
        return $this->uri;
    }
    public function getMethods()
    {
        return $this->methods();
    }
    public function methods()
    {
        return $this->methods;
    }
    public function httpOnly()
    {
        return in_array('http', $this->action, true);
    }
    public function httpsOnly()
    {
        return $this->secure();
    }
    public function secure()
    {
        return in_array('https', $this->action, true);
    }
    public function domain()
    {
        return array_get($this->action, 'domain');
    }
    public function getUri()
    {
        return $this->uri;
    }
    public function setUri($uri)
    {
        $this->uri = $uri;
        return $this;
    }
    public function getPrefix()
    {
        return array_get($this->action, 'prefix');
    }
    public function getName()
    {
        return array_get($this->action, 'as');
    }
    public function getActionName()
    {
        return array_get($this->action, 'controller', 'Closure');
    }
    public function getAction()
    {
        return $this->action;
    }
    public function setAction(array $action)
    {
        $this->action = $action;
        return $this;
    }
    public function getCompiled()
    {
        return $this->compiled;
    }
}
namespace Royalcms\Component\Routing;

use Countable;
use ArrayIterator;
use IteratorAggregate;
use Royalcms\Component\HttpKernel\Request;
use Royalcms\Component\HttpKernel\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
class RouteCollection implements Countable, IteratorAggregate
{
    protected $routes = array();
    protected $allRoutes = array();
    protected $nameList = array();
    protected $actionList = array();
    public function add(Route $route)
    {
        $this->addToCollections($route);
        $this->addLookups($route);
        return $route;
    }
    protected function addToCollections($route)
    {
        foreach ($route->methods() as $method) {
            $this->routes[$method][$route->domain() . $route->getUri()] = $route;
        }
        $this->allRoutes[$method . $route->domain() . $route->getUri()] = $route;
    }
    protected function addLookups($route)
    {
        $action = $route->getAction();
        if (isset($action['as'])) {
            $this->nameList[$action['as']] = $route;
        }
        if (isset($action['controller'])) {
            $this->addToActionList($action, $route);
        }
    }
    protected function addToActionList($action, $route)
    {
        if (!isset($this->actionList[$action['controller']])) {
            $this->actionList[$action['controller']] = $route;
        }
    }
    public function match(Request $request)
    {
        $routes = $this->get($request->getMethod());
        $route = $this->check($routes, $request);
        if (!is_null($route)) {
            return $route->bind($request);
        }
        $others = $this->checkForAlternateVerbs($request);
        if (count($others) > 0) {
            return $this->getOtherMethodsRoute($request, $others);
        }
        throw new NotFoundHttpException();
    }
    protected function checkForAlternateVerbs($request)
    {
        $methods = array_diff(Router::$verbs, array($request->getMethod()));
        $others = array();
        foreach ($methods as $method) {
            if (!is_null($this->check($this->get($method), $request, false))) {
                $others[] = $method;
            }
        }
        return $others;
    }
    protected function getOtherMethodsRoute($request, array $others)
    {
        if ($request->method() == 'OPTIONS') {
            return with(new Route('OPTIONS', $request->path(), function () use($others) {
                return new Response('', 200, array('Allow' => implode(',', $others)));
            }))->bind($request);
        } else {
            $this->methodNotAllowed($others);
        }
    }
    protected function methodNotAllowed(array $others)
    {
        throw new MethodNotAllowedHttpException($others);
    }
    protected function check(array $routes, $request, $includingMethod = true)
    {
        return array_first($routes, function ($key, $value) use($request, $includingMethod) {
            return $value->matches($request, $includingMethod);
        });
    }
    protected function get($method = null)
    {
        if (is_null($method)) {
            return $this->getRoutes();
        }
        return array_get($this->routes, $method, array());
    }
    public function hasNamedRoute($name)
    {
        return !is_null($this->getByName($name));
    }
    public function getByName($name)
    {
        return isset($this->nameList[$name]) ? $this->nameList[$name] : null;
    }
    public function getByAction($action)
    {
        return isset($this->actionList[$action]) ? $this->actionList[$action] : null;
    }
    public function getRoutes()
    {
        return array_values($this->allRoutes);
    }
    public function getIterator()
    {
        return new ArrayIterator($this->getRoutes());
    }
    public function count()
    {
        return count($this->getRoutes());
    }
}
namespace Royalcms\Component\Routing;

use Closure;
use Royalcms\Component\HttpKernel\Request;
use Royalcms\Component\Container\Container;
class ControllerDispatcher
{
    protected $filterer;
    protected $container;
    public function __construct(RouteFiltererInterface $filterer, Container $container = null)
    {
        $this->filterer = $filterer;
        $this->container = $container;
    }
    public function dispatch(Route $route, Request $request, $controller, $method)
    {
        $instance = $this->makeController($controller);
        $this->assignAfter($instance, $route, $request, $method);
        $response = $this->before($instance, $route, $request, $method);
        if (is_null($response)) {
            $response = $this->call($instance, $route, $method);
        }
        return $response;
    }
    protected function makeController($controller)
    {
        Controller::setFilterer($this->filterer);
        return $this->container->make($controller);
    }
    protected function call($instance, $route, $method)
    {
        $parameters = $route->parametersWithoutNulls();
        return $instance->callAction($method, $parameters);
    }
    protected function before($instance, $route, $request, $method)
    {
        foreach ($instance->getBeforeFilters() as $filter) {
            if ($this->filterApplies($filter, $request, $method)) {
                $response = $this->callFilter($filter, $route, $request);
                if (!is_null($response)) {
                    return $response;
                }
            }
        }
    }
    protected function assignAfter($instance, $route, $request, $method)
    {
        foreach ($instance->getAfterFilters() as $filter) {
            if ($this->filterApplies($filter, $request, $method)) {
                $route->after($this->getAssignableAfter($filter));
            }
        }
    }
    protected function getAssignableAfter($filter)
    {
        return $filter['original'] instanceof Closure ? $filter['filter'] : $filter['original'];
    }
    protected function filterApplies($filter, $request, $method)
    {
        foreach (array('Only', 'Except', 'On') as $type) {
            if ($this->{"filterFails{$type}"}($filter, $request, $method)) {
                return false;
            }
        }
        return true;
    }
    protected function filterFailsOnly($filter, $request, $method)
    {
        if (!isset($filter['options']['only'])) {
            return false;
        }
        return !in_array($method, (array) $filter['options']['only']);
    }
    protected function filterFailsExcept($filter, $request, $method)
    {
        if (!isset($filter['options']['except'])) {
            return false;
        }
        return in_array($method, (array) $filter['options']['except']);
    }
    protected function filterFailsOn($filter, $request, $method)
    {
        $on = array_get($filter, 'options.on', null);
        if (is_null($on)) {
            return false;
        }
        if (is_string($on)) {
            $on = explode('|', $on);
        }
        return !in_array(strtolower($request->getMethod()), $on);
    }
    protected function callFilter($filter, $route, $request)
    {
        extract($filter);
        return $this->filterer->callRouteFilter($filter, $parameters, $route, $request);
    }
}
namespace Royalcms\Component\Routing;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
class UrlGenerator
{
    protected $routes;
    protected $request;
    protected $dontEncode = array('%2F' => '/', '%40' => '@', '%3A' => ':', '%3B' => ';', '%2C' => ',', '%3D' => '=', '%2B' => '+', '%21' => '!', '%2A' => '*', '%7C' => '|');
    public function __construct(RouteCollection $routes, Request $request)
    {
        $this->routes = $routes;
        $this->setRequest($request);
    }
    public function full()
    {
        return $this->request->fullUrl();
    }
    public function current()
    {
        return $this->to($this->request->getPathInfo());
    }
    public function previous()
    {
        return $this->to($this->request->headers->get('referer'));
    }
    public function to($path, $extra = array(), $secure = null)
    {
        if ($this->isValidUrl($path)) {
            return $path;
        }
        $scheme = $this->getScheme($secure);
        $tail = implode('/', array_map('rawurlencode', (array) $extra));
        $root = $this->getRootUrl($scheme);
        return $this->trimUrl($root, $path, $tail);
    }
    public function secure($path, $parameters = array())
    {
        return $this->to($path, $parameters, true);
    }
    public function asset($path, $secure = null)
    {
        if ($this->isValidUrl($path)) {
            return $path;
        }
        $root = $this->getRootUrl($this->getScheme($secure));
        return $this->removeIndex($root) . '/' . trim($path, '/');
    }
    protected function removeIndex($root)
    {
        $i = 'index.php';
        return str_contains($root, $i) ? str_replace('/' . $i, '', $root) : $root;
    }
    public function secureAsset($path)
    {
        return $this->asset($path, true);
    }
    protected function getScheme($secure)
    {
        if (is_null($secure)) {
            return $this->request->getScheme() . '://';
        } else {
            return $secure ? 'https://' : 'http://';
        }
    }
    public function route($name, $parameters = array(), $absolute = true, $route = null)
    {
        $route = $route ?: $this->routes->getByName($name);
        $parameters = (array) $parameters;
        if (!is_null($route)) {
            return $this->toRoute($route, $parameters, $absolute);
        } else {
            throw new InvalidArgumentException("Route [{$name}] not defined.");
        }
    }
    protected function toRoute($route, array $parameters, $absolute)
    {
        $domain = $this->getRouteDomain($route, $parameters);
        $uri = strtr(rawurlencode($this->trimUrl($root = $this->replaceRoot($route, $domain, $parameters), $this->replaceRouteParameters($route->uri(), $parameters))), $this->dontEncode) . $this->getRouteQueryString($parameters);
        return $absolute ? $uri : '/' . ltrim(str_replace($root, '', $uri), '/');
    }
    protected function replaceRoot($route, $domain, &$parameters)
    {
        return $this->replaceRouteParameters($this->getRouteRoot($route, $domain), $parameters);
    }
    protected function replaceRouteParameters($path, array &$parameters)
    {
        if (count($parameters)) {
            $path = preg_replace_sub('/\\{.*?\\}/', $parameters, $this->replaceNamedParameters($path, $parameters));
        }
        return trim(preg_replace('/\\{.*?\\?\\}/', '', $path), '/');
    }
    protected function replaceNamedParameters($path, &$parameters)
    {
        return preg_replace_callback('/\\{(.*?)\\??\\}/', function ($m) use(&$parameters) {
            return isset($parameters[$m[1]]) ? array_pull($parameters, $m[1]) : $m[0];
        }, $path);
    }
    protected function getRouteQueryString(array $parameters)
    {
        if (count($parameters) == 0) {
            return '';
        }
        $query = http_build_query($keyed = $this->getStringParameters($parameters));
        if (count($keyed) < count($parameters)) {
            $query .= '&' . implode('&', $this->getNumericParameters($parameters));
        }
        return '?' . trim($query, '&');
    }
    protected function getStringParameters(array $parameters)
    {
        return array_where($parameters, function ($k, $v) {
            return is_string($k);
        });
    }
    protected function getNumericParameters(array $parameters)
    {
        return array_where($parameters, function ($k, $v) {
            return is_numeric($k);
        });
    }
    protected function getRouteDomain($route, &$parameters)
    {
        return $route->domain() ? $this->formatDomain($route, $parameters) : null;
    }
    protected function formatDomain($route, &$parameters)
    {
        return $this->addPortToDomain($this->getDomainAndScheme($route));
    }
    protected function getDomainAndScheme($route)
    {
        return $this->getRouteScheme($route) . $route->domain();
    }
    protected function addPortToDomain($domain)
    {
        if (in_array($this->request->getPort(), array('80', '443'))) {
            return $domain;
        } else {
            return $domain .= ':' . $this->request->getPort();
        }
    }
    protected function getRouteRoot($route, $domain)
    {
        return $this->getRootUrl($this->getRouteScheme($route), $domain);
    }
    protected function getRouteScheme($route)
    {
        if ($route->httpOnly()) {
            return $this->getScheme(false);
        } elseif ($route->httpsOnly()) {
            return $this->getScheme(true);
        } else {
            return $this->getScheme(null);
        }
    }
    public function action($action, $parameters = array(), $absolute = true)
    {
        return $this->route($action, $parameters, $absolute, $this->routes->getByAction($action));
    }
    protected function getRootUrl($scheme, $root = null)
    {
        $root = $root ?: $this->request->root();
        $start = starts_with($root, 'http://') ? 'http://' : 'https://';
        return preg_replace('~' . $start . '~', $scheme, $root, 1);
    }
    public function isValidUrl($path)
    {
        if (starts_with($path, array('#', '//', 'mailto:', 'tel:'))) {
            return true;
        }
        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }
    protected function trimUrl($root, $path, $tail = '')
    {
        return trim($root . '/' . trim($path . '/' . $tail, '/'), '/');
    }
    public function getRequest()
    {
        return $this->request;
    }
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }
}
namespace Royalcms\Component\Routing\Matching;

use Royalcms\Component\HttpKernel\Request;
use Royalcms\Component\Routing\Route;
interface ValidatorInterface
{
    public function matches(Route $route, Request $request);
}
namespace Royalcms\Component\Routing\Matching;

use Royalcms\Component\HttpKernel\Request;
use Royalcms\Component\Routing\Route;
class HostValidator implements ValidatorInterface
{
    public function matches(Route $route, Request $request)
    {
        if (is_null($route->getCompiled()->getHostRegex())) {
            return true;
        }
        return preg_match($route->getCompiled()->getHostRegex(), $request->getHost());
    }
}
namespace Royalcms\Component\Routing\Matching;

use Royalcms\Component\HttpKernel\Request;
use Royalcms\Component\Routing\Route;
class MethodValidator implements ValidatorInterface
{
    public function matches(Route $route, Request $request)
    {
        return in_array($request->getMethod(), $route->methods());
    }
}
namespace Royalcms\Component\Routing\Matching;

use Royalcms\Component\HttpKernel\Request;
use Royalcms\Component\Routing\Route;
class SchemeValidator implements ValidatorInterface
{
    public function matches(Route $route, Request $request)
    {
        if ($route->httpOnly()) {
            return !$request->secure();
        } elseif ($route->secure()) {
            return $request->secure();
        }
        return true;
    }
}
namespace Royalcms\Component\Routing\Matching;

use Royalcms\Component\HttpKernel\Request;
use Royalcms\Component\Routing\Route;
class UriValidator implements ValidatorInterface
{
    public function matches(Route $route, Request $request)
    {
        $path = $request->path() == '/' ? '/' : '/' . $request->path();
        return preg_match($route->getCompiled()->getRegex(), rawurldecode($path));
    }
}
namespace Royalcms\Component\Event;

use Royalcms\Component\Container\Container;
class Dispatcher
{
    protected $container;
    protected $listeners = array();
    protected $wildcards = array();
    protected $sorted = array();
    protected $firing = array();
    public function __construct(Container $container = null)
    {
        $this->container = $container ?: new Container();
    }
    public function listen($events, $listener, $priority = 0)
    {
        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                return $this->setupWildcardListen($event, $listener);
            }
            $this->listeners[$event][$priority][] = $this->makeListener($listener);
            unset($this->sorted[$event]);
        }
    }
    protected function setupWildcardListen($event, $listener)
    {
        $this->wildcards[$event][] = $this->makeListener($listener);
    }
    public function hasListeners($eventName)
    {
        return isset($this->listeners[$eventName]);
    }
    public function queue($event, $payload = array())
    {
        $me = $this;
        $this->listen($event . '_queue', function () use($me, $event, $payload) {
            $me->fire($event, $payload);
        });
    }
    public function subscribe($subscriber)
    {
        $subscriber = $this->resolveSubscriber($subscriber);
        $subscriber->subscribe($this);
    }
    protected function resolveSubscriber($subscriber)
    {
        if (is_string($subscriber)) {
            return $this->container->make($subscriber);
        }
        return $subscriber;
    }
    public function until($event, $payload = array())
    {
        return $this->fire($event, $payload, true);
    }
    public function flush($event)
    {
        $this->fire($event . '_queue');
    }
    public function firing()
    {
        return last($this->firing);
    }
    public function fire($event, $payload = array(), $halt = false)
    {
        $responses = array();
        if (!is_array($payload)) {
            $payload = array($payload);
        }
        $this->firing[] = $event;
        foreach ($this->getListeners($event) as $listener) {
            $response = call_user_func_array($listener, $payload);
            if (!is_null($response) && $halt) {
                array_pop($this->firing);
                return $response;
            }
            if ($response === false) {
                break;
            }
            $responses[] = $response;
        }
        array_pop($this->firing);
        return $halt ? null : $responses;
    }
    public function getListeners($eventName)
    {
        $wildcards = $this->getWildcardListeners($eventName);
        if (!isset($this->sorted[$eventName])) {
            $this->sortListeners($eventName);
        }
        return array_merge($this->sorted[$eventName], $wildcards);
    }
    protected function getWildcardListeners($eventName)
    {
        $wildcards = array();
        foreach ($this->wildcards as $key => $listeners) {
            if (str_is($key, $eventName)) {
                $wildcards = array_merge($wildcards, $listeners);
            }
        }
        return $wildcards;
    }
    protected function sortListeners($eventName)
    {
        $this->sorted[$eventName] = array();
        if (isset($this->listeners[$eventName])) {
            krsort($this->listeners[$eventName]);
            $this->sorted[$eventName] = call_user_func_array('array_merge', $this->listeners[$eventName]);
        }
    }
    public function makeListener($listener)
    {
        if (is_string($listener)) {
            $listener = $this->createClassListener($listener);
        }
        return $listener;
    }
    public function createClassListener($listener)
    {
        $container = $this->container;
        return function () use($listener, $container) {
            $segments = explode('@', $listener);
            $method = count($segments) == 2 ? $segments[1] : 'handle';
            $callable = array($container->make($segments[0]), $method);
            $data = func_get_args();
            return call_user_func_array($callable, $data);
        };
    }
    public function forget($event)
    {
        unset($this->listeners[$event]);
        unset($this->sorted[$event]);
    }
}
namespace Royalcms\Component\Support\Contracts;

interface ArrayableInterface
{
    public function toArray();
}
namespace Royalcms\Component\Support\Contracts;

interface JsonableInterface
{
    public function toJson($options = 0);
}
namespace Royalcms\Component\Database\Eloquent;

use DateTime;
use ArrayAccess;
use Royalcms\Component\DateTime\Carbon;
use LogicException;
use DateTimeInterface;
use Royalcms\Component\Event\Dispatcher;
use Royalcms\Component\Database\Eloquent\Relations\Pivot;
use Royalcms\Component\Database\Eloquent\Relations\HasOne;
use Royalcms\Component\Database\Eloquent\Relations\HasMany;
use Royalcms\Component\Database\Eloquent\Relations\MorphTo;
use Royalcms\Component\Support\Contracts\JsonableInterface;
use Royalcms\Component\Support\Contracts\ArrayableInterface;
use Royalcms\Component\Database\Eloquent\Relations\Relation;
use Royalcms\Component\Database\Eloquent\Relations\MorphOne;
use Royalcms\Component\Database\Eloquent\Relations\MorphMany;
use Royalcms\Component\Database\Eloquent\Relations\BelongsTo;
use Royalcms\Component\Database\Query\Builder as QueryBuilder;
use Royalcms\Component\Database\Eloquent\Relations\MorphToMany;
use Royalcms\Component\Database\Eloquent\Relations\BelongsToMany;
use Royalcms\Component\Database\Eloquent\Relations\HasManyThrough;
use Royalcms\Component\Database\ConnectionResolverInterface as Resolver;
use Royalcms\Component\Support\Collection as BaseCollection;
abstract class Model implements ArrayAccess, ArrayableInterface, JsonableInterface
{
    protected $connection;
    protected $table;
    protected $primaryKey = 'id';
    protected $perPage = 15;
    public $incrementing = true;
    public $timestamps = true;
    protected $attributes = array();
    protected $original = array();
    protected $relations = array();
    protected $hidden = array();
    protected $visible = array();
    protected $appends = array();
    protected $fillable = array();
    protected $guarded = array('*');
    protected $dates = array();
    protected $dateFormat;
    protected $casts = array();
    protected $touches = array();
    protected $observables = array();
    protected $with = array();
    protected $morphClass;
    public $exists = false;
    protected $softDelete = false;
    public static $snakeAttributes = true;
    protected static $resolver;
    protected static $dispatcher;
    protected static $booted = array();
    protected static $unguarded = false;
    protected static $mutatorCache = array();
    public static $manyMethods = array('belongsToMany', 'morphToMany', 'morphedByMany');
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';
    const DELETED_AT = 'deleted_at';
    public function __construct(array $attributes = array())
    {
        $this->bootIfNotBooted();
        $this->syncOriginal();
        $this->fill($attributes);
    }
    protected function bootIfNotBooted()
    {
        if (!isset(static::$booted[get_class($this)])) {
            static::$booted[get_class($this)] = true;
            $this->fireModelEvent('booting', false);
            static::boot();
            $this->fireModelEvent('booted', false);
        }
    }
    protected static function boot()
    {
        $class = get_called_class();
        static::$mutatorCache[$class] = array();
        foreach (get_class_methods($class) as $method) {
            if (preg_match('/^get(.+)Attribute$/', $method, $matches)) {
                if (static::$snakeAttributes) {
                    $matches[1] = snake_case($matches[1]);
                }
                static::$mutatorCache[$class][] = lcfirst($matches[1]);
            }
        }
    }
    public static function observe($class)
    {
        $instance = new static();
        $className = get_class($class);
        foreach ($instance->getObservableEvents() as $event) {
            if (method_exists($class, $event)) {
                static::registerModelEvent($event, $className . '@' . $event);
            }
        }
    }
    public function fill(array $attributes)
    {
        $totallyGuarded = $this->totallyGuarded();
        foreach ($this->fillableFromArray($attributes) as $key => $value) {
            $key = $this->removeTableFromKey($key);
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($totallyGuarded) {
                throw new MassAssignmentException($key);
            }
        }
        return $this;
    }
    protected function fillableFromArray(array $attributes)
    {
        if (count($this->fillable) > 0 && !static::$unguarded) {
            return array_intersect_key($attributes, array_flip($this->fillable));
        }
        return $attributes;
    }
    public function newInstance($attributes = array(), $exists = false)
    {
        $model = new static((array) $attributes);
        $model->exists = $exists;
        return $model;
    }
    public function newFromBuilder($attributes = array())
    {
        $instance = $this->newInstance(array(), true);
        $instance->setRawAttributes((array) $attributes, true);
        return $instance;
    }
    public static function hydrate(array $items, $connection = null)
    {
        $collection = with($instance = new static())->newCollection();
        foreach ($items as $item) {
            $model = $instance->newFromBuilder($item);
            if (!is_null($connection)) {
                $model->setConnection($connection);
            }
            $collection->push($model);
        }
        return $collection;
    }
    public static function hydrateRaw($query, $bindings = array(), $connection = null)
    {
        $instance = new static();
        if (!is_null($connection)) {
            $instance->setConnection($connection);
        }
        $items = $instance->getConnection()->select($query, $bindings);
        return static::hydrate($items, $connection);
    }
    public static function create(array $attributes)
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }
    public static function firstOrCreate(array $attributes)
    {
        if (!is_null($instance = static::firstByAttributes($attributes))) {
            return $instance;
        }
        return static::create($attributes);
    }
    public static function firstOrNew(array $attributes)
    {
        if (!is_null($instance = static::firstByAttributes($attributes))) {
            return $instance;
        }
        return new static($attributes);
    }
    protected static function firstByAttributes($attributes)
    {
        $query = static::query();
        foreach ($attributes as $key => $value) {
            $query->where($key, $value);
        }
        return $query->first() ?: null;
    }
    public static function query()
    {
        return with(new static())->newQuery();
    }
    public static function on($connection = null)
    {
        $instance = new static();
        $instance->setConnection($connection);
        return $instance->newQuery();
    }
    public static function all($columns = array('*'))
    {
        $instance = new static();
        return $instance->newQuery()->get($columns);
    }
    public static function find($id, $columns = array('*'))
    {
        if (is_array($id) && empty($id)) {
            return new Collection();
        }
        $instance = new static();
        return $instance->newQuery()->find($id, $columns);
    }
    public static function findOrNew($id, $columns = array('*'))
    {
        if (!is_null($model = static::find($id, $columns))) {
            return $model;
        }
        return new static($columns);
    }
    public static function findOrFail($id, $columns = array('*'))
    {
        if (!is_null($model = static::find($id, $columns))) {
            return $model;
        }
        throw with(new ModelNotFoundException())->setModel(get_called_class());
    }
    public function load($relations)
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }
        $query = $this->newQuery()->with($relations);
        $query->eagerLoadRelations(array($this));
        return $this;
    }
    public static function with($relations)
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }
        $instance = new static();
        return $instance->newQuery()->with($relations);
    }
    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $instance = new $related();
        $localKey = $localKey ?: $this->getKeyName();
        return new HasOne($instance->newQuery(), $this, $instance->getTable() . '.' . $foreignKey, $localKey);
    }
    public function morphOne($related, $name, $type = null, $id = null, $localKey = null)
    {
        $instance = new $related();
        list($type, $id) = $this->getMorphs($name, $type, $id);
        $table = $instance->getTable();
        $localKey = $localKey ?: $this->getKeyName();
        return new MorphOne($instance->newQuery(), $this, $table . '.' . $type, $table . '.' . $id, $localKey);
    }
    public function belongsTo($related, $foreignKey = null, $otherKey = null, $relation = null)
    {
        if (is_null($relation)) {
            list(, $caller) = debug_backtrace(false);
            $relation = $caller['function'];
        }
        if (is_null($foreignKey)) {
            $foreignKey = snake_case($relation) . '_id';
        }
        $instance = new $related();
        $query = $instance->newQuery();
        $otherKey = $otherKey ?: $instance->getKeyName();
        return new BelongsTo($query, $this, $foreignKey, $otherKey, $relation);
    }
    public function morphTo($name = null, $type = null, $id = null)
    {
        if (is_null($name)) {
            list(, $caller) = debug_backtrace(false);
            $name = snake_case($caller['function']);
        }
        list($type, $id) = $this->getMorphs($name, $type, $id);
        if (is_null($class = $this->{$type})) {
            return new MorphTo($this->newQuery(), $this, $id, null, $type, $name);
        } else {
            $instance = new $class();
            return new MorphTo(with($instance)->newQuery(), $this, $id, $instance->getKeyName(), $type, $name);
        }
    }
    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $instance = new $related();
        $localKey = $localKey ?: $this->getKeyName();
        return new HasMany($instance->newQuery(), $this, $instance->getTable() . '.' . $foreignKey, $localKey);
    }
    public function hasManyThrough($related, $through, $firstKey = null, $secondKey = null)
    {
        $through = new $through();
        $firstKey = $firstKey ?: $this->getForeignKey();
        $secondKey = $secondKey ?: $through->getForeignKey();
        return new HasManyThrough(with(new $related())->newQuery(), $this, $through, $firstKey, $secondKey);
    }
    public function morphMany($related, $name, $type = null, $id = null, $localKey = null)
    {
        $instance = new $related();
        list($type, $id) = $this->getMorphs($name, $type, $id);
        $table = $instance->getTable();
        $localKey = $localKey ?: $this->getKeyName();
        return new MorphMany($instance->newQuery(), $this, $table . '.' . $type, $table . '.' . $id, $localKey);
    }
    public function belongsToMany($related, $table = null, $foreignKey = null, $otherKey = null, $relation = null)
    {
        if (is_null($relation)) {
            $relation = $this->getBelongsToManyCaller();
        }
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $instance = new $related();
        $otherKey = $otherKey ?: $instance->getForeignKey();
        if (is_null($table)) {
            $table = $this->joiningTable($related);
        }
        $query = $instance->newQuery();
        return new BelongsToMany($query, $this, $table, $foreignKey, $otherKey, $relation);
    }
    public function morphToMany($related, $name, $table = null, $foreignKey = null, $otherKey = null, $inverse = false)
    {
        $caller = $this->getBelongsToManyCaller();
        $foreignKey = $foreignKey ?: $name . '_id';
        $instance = new $related();
        $otherKey = $otherKey ?: $instance->getForeignKey();
        $query = $instance->newQuery();
        $table = $table ?: str_plural($name);
        return new MorphToMany($query, $this, $name, $table, $foreignKey, $otherKey, $caller, $inverse);
    }
    public function morphedByMany($related, $name, $table = null, $foreignKey = null, $otherKey = null)
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $otherKey = $otherKey ?: $name . '_id';
        return $this->morphToMany($related, $name, $table, $foreignKey, $otherKey, true);
    }
    protected function getBelongsToManyCaller()
    {
        $self = __FUNCTION__;
        $caller = array_first(debug_backtrace(false), function ($key, $trace) use($self) {
            $caller = $trace['function'];
            return !in_array($caller, Model::$manyMethods) && $caller != $self;
        });
        return !is_null($caller) ? $caller['function'] : null;
    }
    public function joiningTable($related)
    {
        $base = snake_case(class_basename($this));
        $related = snake_case(class_basename($related));
        $models = array($related, $base);
        sort($models);
        return strtolower(implode('_', $models));
    }
    public static function destroy($ids)
    {
        $count = 0;
        $ids = is_array($ids) ? $ids : func_get_args();
        $instance = new static();
        $key = $instance->getKeyName();
        foreach ($instance->whereIn($key, $ids)->get() as $model) {
            if ($model->delete()) {
                $count++;
            }
        }
        return $count;
    }
    public function delete()
    {
        if (is_null($this->primaryKey)) {
            throw new \Exception('No primary key defined on model.');
        }
        if ($this->exists) {
            if ($this->fireModelEvent('deleting') === false) {
                return false;
            }
            $this->touchOwners();
            $this->performDeleteOnModel();
            $this->exists = false;
            $this->fireModelEvent('deleted', false);
            return true;
        }
    }
    public function forceDelete()
    {
        $softDelete = $this->softDelete;
        $this->softDelete = false;
        $this->delete();
        $this->softDelete = $softDelete;
    }
    protected function performDeleteOnModel()
    {
        $query = $this->newQuery()->where($this->getKeyName(), $this->getKey());
        if ($this->softDelete) {
            $this->{static::DELETED_AT} = $time = $this->freshTimestamp();
            $query->update(array(static::DELETED_AT => $this->fromDateTime($time)));
        } else {
            $query->delete();
        }
    }
    public function restore()
    {
        if ($this->softDelete) {
            if ($this->fireModelEvent('restoring') === false) {
                return false;
            }
            $this->{static::DELETED_AT} = null;
            $result = $this->save();
            $this->fireModelEvent('restored', false);
            return $result;
        }
    }
    public static function saving($callback)
    {
        static::registerModelEvent('saving', $callback);
    }
    public static function saved($callback)
    {
        static::registerModelEvent('saved', $callback);
    }
    public static function updating($callback)
    {
        static::registerModelEvent('updating', $callback);
    }
    public static function updated($callback)
    {
        static::registerModelEvent('updated', $callback);
    }
    public static function creating($callback)
    {
        static::registerModelEvent('creating', $callback);
    }
    public static function created($callback)
    {
        static::registerModelEvent('created', $callback);
    }
    public static function deleting($callback)
    {
        static::registerModelEvent('deleting', $callback);
    }
    public static function deleted($callback)
    {
        static::registerModelEvent('deleted', $callback);
    }
    public static function restoring($callback)
    {
        static::registerModelEvent('restoring', $callback);
    }
    public static function restored($callback)
    {
        static::registerModelEvent('restored', $callback);
    }
    public static function flushEventListeners()
    {
        if (!isset(static::$dispatcher)) {
            return;
        }
        $instance = new static();
        foreach ($instance->getObservableEvents() as $event) {
            static::$dispatcher->forget("eloquent.{$event}: " . get_called_class());
        }
    }
    protected static function registerModelEvent($event, $callback)
    {
        if (isset(static::$dispatcher)) {
            $name = get_called_class();
            static::$dispatcher->listen("eloquent.{$event}: {$name}", $callback);
        }
    }
    public function getObservableEvents()
    {
        return array_merge(array('creating', 'created', 'updating', 'updated', 'deleting', 'deleted', 'saving', 'saved', 'restoring', 'restored'), $this->observables);
    }
    protected function increment($column, $amount = 1)
    {
        return $this->incrementOrDecrement($column, $amount, 'increment');
    }
    protected function decrement($column, $amount = 1)
    {
        return $this->incrementOrDecrement($column, $amount, 'decrement');
    }
    protected function incrementOrDecrement($column, $amount, $method)
    {
        $query = $this->newQuery();
        if (!$this->exists) {
            return $query->{$method}($column, $amount);
        }
        return $query->where($this->getKeyName(), $this->getKey())->{$method}($column, $amount);
    }
    public function update(array $attributes = array())
    {
        if (!$this->exists) {
            return $this->newQuery()->update($attributes);
        }
        return $this->fill($attributes)->save();
    }
    public function push()
    {
        if (!$this->save()) {
            return false;
        }
        foreach ($this->relations as $models) {
            foreach (Collection::make($models) as $model) {
                if (!$model->push()) {
                    return false;
                }
            }
        }
        return true;
    }
    public function save(array $options = array())
    {
        $query = $this->newQueryWithDeleted();
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }
        if ($this->exists) {
            $saved = $this->performUpdate($query);
        } else {
            $saved = $this->performInsert($query);
        }
        if ($saved) {
            $this->finishSave($options);
        }
        return $saved;
    }
    protected function finishSave(array $options)
    {
        $this->syncOriginal();
        $this->fireModelEvent('saved', false);
        if (array_get($options, 'touch', true)) {
            $this->touchOwners();
        }
    }
    protected function performUpdate(Builder $query)
    {
        $dirty = $this->getDirty();
        if (count($dirty) > 0) {
            if ($this->fireModelEvent('updating') === false) {
                return false;
            }
            if ($this->timestamps) {
                $this->updateTimestamps();
            }
            $dirty = $this->getDirty();
            if (count($dirty) > 0) {
                $this->setKeysForSaveQuery($query)->update($dirty);
                $this->fireModelEvent('updated', false);
            }
        }
        return true;
    }
    protected function performInsert(Builder $query)
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }
        if ($this->timestamps) {
            $this->updateTimestamps();
        }
        $attributes = $this->attributes;
        if ($this->incrementing) {
            $this->insertAndSetId($query, $attributes);
        } else {
            $query->insert($attributes);
        }
        $this->exists = true;
        $this->fireModelEvent('created', false);
        return true;
    }
    protected function insertAndSetId(Builder $query, $attributes)
    {
        $id = $query->insertGetId($attributes, $keyName = $this->getKeyName());
        $this->setAttribute($keyName, $id);
    }
    public function touchOwners()
    {
        foreach ($this->touches as $relation) {
            $this->{$relation}()->touch();
        }
    }
    public function touches($relation)
    {
        return in_array($relation, $this->touches);
    }
    protected function fireModelEvent($event, $halt = true)
    {
        if (!isset(static::$dispatcher)) {
            return true;
        }
        $event = "eloquent.{$event}: " . get_class($this);
        $method = $halt ? 'until' : 'fire';
        return static::$dispatcher->{$method}($event, $this);
    }
    protected function setKeysForSaveQuery(Builder $query)
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());
        return $query;
    }
    protected function getKeyForSaveQuery()
    {
        if (isset($this->original[$this->getKeyName()])) {
            return $this->original[$this->getKeyName()];
        } else {
            return $this->getAttribute($this->getKeyName());
        }
    }
    public function touch()
    {
        $this->updateTimestamps();
        return $this->save();
    }
    protected function updateTimestamps()
    {
        $time = $this->freshTimestamp();
        if (!$this->isDirty(static::UPDATED_AT)) {
            $this->setUpdatedAt($time);
        }
        if (!$this->exists && !$this->isDirty(static::CREATED_AT)) {
            $this->setCreatedAt($time);
        }
    }
    public function setCreatedAt($value)
    {
        $this->{static::CREATED_AT} = $value;
    }
    public function setUpdatedAt($value)
    {
        $this->{static::UPDATED_AT} = $value;
    }
    public function getCreatedAtColumn()
    {
        return static::CREATED_AT;
    }
    public function getUpdatedAtColumn()
    {
        return static::UPDATED_AT;
    }
    public function getDeletedAtColumn()
    {
        return static::DELETED_AT;
    }
    public function getQualifiedDeletedAtColumn()
    {
        return $this->getTable() . '.' . $this->getDeletedAtColumn();
    }
    public function freshTimestamp()
    {
        return new Carbon();
    }
    public function freshTimestampString()
    {
        return $this->fromDateTime($this->freshTimestamp());
    }
    public function newQuery($excludeDeleted = true)
    {
        $builder = $this->newEloquentBuilder($this->newBaseQueryBuilder());
        $builder->setModel($this)->with($this->with);
        if ($excludeDeleted && $this->softDelete) {
            $builder->whereNull($this->getQualifiedDeletedAtColumn());
        }
        return $builder;
    }
    public function newQueryWithDeleted()
    {
        return $this->newQuery(false);
    }
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }
    public function trashed()
    {
        return $this->softDelete && !is_null($this->{static::DELETED_AT});
    }
    public static function withTrashed()
    {
        return with(new static())->newQueryWithDeleted();
    }
    public static function onlyTrashed()
    {
        $instance = new static();
        $column = $instance->getQualifiedDeletedAtColumn();
        return $instance->newQueryWithDeleted()->whereNotNull($column);
    }
    protected function newBaseQueryBuilder()
    {
        $conn = $this->getConnection();
        $grammar = $conn->getQueryGrammar();
        return new QueryBuilder($conn, $grammar, $conn->getPostProcessor());
    }
    public function newCollection(array $models = array())
    {
        return new Collection($models);
    }
    public function newPivot(Model $parent, array $attributes, $table, $exists)
    {
        return new Pivot($parent, $attributes, $table, $exists);
    }
    public function getTable()
    {
        if (isset($this->table)) {
            return $this->table;
        }
        return str_replace('\\', '', snake_case(str_plural(class_basename($this))));
    }
    public function setTable($table)
    {
        $this->table = $table;
    }
    public function getKey()
    {
        return $this->getAttribute($this->getKeyName());
    }
    public function getKeyName()
    {
        return $this->primaryKey;
    }
    public function getQualifiedKeyName()
    {
        return $this->getTable() . '.' . $this->getKeyName();
    }
    public function usesTimestamps()
    {
        return $this->timestamps;
    }
    public function isSoftDeleting()
    {
        return $this->softDelete;
    }
    public function setSoftDeleting($enabled)
    {
        $this->softDelete = $enabled;
    }
    protected function getMorphs($name, $type, $id)
    {
        $type = $type ?: $name . '_type';
        $id = $id ?: $name . '_id';
        return array($type, $id);
    }
    public function getMorphClass()
    {
        return $this->morphClass ?: get_class($this);
    }
    public function getPerPage()
    {
        return $this->perPage;
    }
    public function setPerPage($perPage)
    {
        $this->perPage = $perPage;
    }
    public function getForeignKey()
    {
        return snake_case(class_basename($this)) . '_id';
    }
    public function getHidden()
    {
        return $this->hidden;
    }
    public function setHidden(array $hidden)
    {
        $this->hidden = $hidden;
    }
    public function setVisible(array $visible)
    {
        $this->visible = $visible;
    }
    public function setAppends(array $appends)
    {
        $this->appends = $appends;
    }
    public function getFillable()
    {
        return $this->fillable;
    }
    public function fillable(array $fillable)
    {
        $this->fillable = $fillable;
        return $this;
    }
    public function guard(array $guarded)
    {
        $this->guarded = $guarded;
        return $this;
    }
    public static function unguard()
    {
        static::$unguarded = true;
    }
    public static function reguard()
    {
        static::$unguarded = false;
    }
    public static function setUnguardState($state)
    {
        static::$unguarded = $state;
    }
    public function isFillable($key)
    {
        if (static::$unguarded) {
            return true;
        }
        if (in_array($key, $this->fillable)) {
            return true;
        }
        if ($this->isGuarded($key)) {
            return false;
        }
        return empty($this->fillable) && !starts_with($key, '_');
    }
    public function isGuarded($key)
    {
        return in_array($key, $this->guarded) || $this->guarded == array('*');
    }
    public function totallyGuarded()
    {
        return count($this->fillable) == 0 && $this->guarded == array('*');
    }
    protected function removeTableFromKey($key)
    {
        if (!str_contains($key, '.')) {
            return $key;
        }
        return last(explode('.', $key));
    }
    public function getTouchedRelations()
    {
        return $this->touches;
    }
    public function setTouchedRelations(array $touches)
    {
        $this->touches = $touches;
    }
    public function getIncrementing()
    {
        return $this->incrementing;
    }
    public function setIncrementing($value)
    {
        $this->incrementing = $value;
    }
    public function toJson($options = 0)
    {
        return json_encode($this->jsonSerialize(), $options);
    }
    public function jsonSerialize()
    {
        return $this->toArray();
    }
    public function toArray()
    {
        $attributes = $this->attributesToArray();
        return array_merge($attributes, $this->relationsToArray());
    }
    public function attributesToArray()
    {
        $attributes = $this->getArrayableAttributes();
        foreach ($this->getDates() as $key) {
            if (!isset($attributes[$key])) {
                continue;
            }
            $attributes[$key] = $this->serializeDate($this->asDateTime($attributes[$key]));
        }
        $mutatedAttributes = $this->getMutatedAttributes();
        foreach ($mutatedAttributes as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }
            $attributes[$key] = $this->mutateAttributeForArray($key, $attributes[$key]);
        }
        foreach ($this->getCasts() as $key => $value) {
            if (!array_key_exists($key, $attributes) || in_array($key, $mutatedAttributes)) {
                continue;
            }
            $attributes[$key] = $this->castAttribute($key, $attributes[$key]);
            if ($attributes[$key] && ($value === 'date' || $value === 'datetime')) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }
        }
        foreach ($this->appends as $key) {
            $attributes[$key] = $this->mutateAttributeForArray($key, null);
        }
        return $attributes;
    }
    protected function getArrayableAttributes()
    {
        return $this->getArrayableItems($this->attributes);
    }
    public function relationsToArray()
    {
        $attributes = array();
        foreach ($this->getArrayableRelations() as $key => $value) {
            if (in_array($key, $this->hidden)) {
                continue;
            }
            if ($value instanceof ArrayableInterface) {
                $relation = $value->toArray();
            } elseif (is_null($value)) {
                $relation = $value;
            }
            if (static::$snakeAttributes) {
                $key = snake_case($key);
            }
            if (isset($relation) || is_null($value)) {
                $attributes[$key] = $relation;
            }
        }
        return $attributes;
    }
    protected function getArrayableRelations()
    {
        return $this->getArrayableItems($this->relations);
    }
    protected function getArrayableItems(array $values)
    {
        if (count($this->visible) > 0) {
            return array_intersect_key($values, array_flip($this->visible));
        }
        return array_diff_key($values, array_flip($this->hidden));
    }
    public function getAttribute($key)
    {
        $inAttributes = array_key_exists($key, $this->attributes);
        if ($inAttributes || $this->hasGetMutator($key)) {
            return $this->getAttributeValue($key);
        }
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }
        $camelKey = camel_case($key);
        if (method_exists($this, $camelKey)) {
            return $this->getRelationshipFromMethod($key, $camelKey);
        }
    }
    protected function getAttributeValue($key)
    {
        $value = $this->getAttributeFromArray($key);
        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $value);
        } elseif (in_array($key, $this->getDates())) {
            if ($value) {
                return $this->asDateTime($value);
            }
        }
        return $value;
    }
    protected function getAttributeFromArray($key)
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }
    }
    protected function getRelationshipFromMethod($key, $camelKey)
    {
        $relations = $this->{$camelKey}();
        if (!$relations instanceof Relation) {
            throw new LogicException('Relationship method must return an object of type ' . 'Royalcms\\Component\\Database\\Eloquent\\Relations\\Relation');
        }
        return $this->relations[$key] = $relations->getResults();
    }
    public function hasGetMutator($key)
    {
        return method_exists($this, 'get' . studly_case($key) . 'Attribute');
    }
    protected function mutateAttribute($key, $value)
    {
        return $this->{'get' . studly_case($key) . 'Attribute'}($value);
    }
    protected function mutateAttributeForArray($key, $value)
    {
        $value = $this->mutateAttribute($key, $value);
        return $value instanceof ArrayableInterface ? $value->toArray() : $value;
    }
    public function hasCast($key, $types = null)
    {
        if (array_key_exists($key, $this->getCasts())) {
            return $types ? in_array($this->getCastType($key), (array) $types, true) : true;
        }
        return false;
    }
    public function getCasts()
    {
        if ($this->getIncrementing()) {
            return array_merge(array($this->getKeyName() => $this->keyType), $this->casts);
        }
        return $this->casts;
    }
    protected function isDateCastable($key)
    {
        return $this->hasCast($key, array('date', 'datetime'));
    }
    protected function isJsonCastable($key)
    {
        return $this->hasCast($key, array('array', 'json', 'object', 'collection'));
    }
    protected function getCastType($key)
    {
        $casts = $this->getCasts();
        return trim(strtolower($casts[$key]));
    }
    protected function castAttribute($key, $value)
    {
        if (is_null($value)) {
            return $value;
        }
        switch ($this->getCastType($key)) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (double) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'object':
                return $this->fromJson($value, true);
            case 'array':
            case 'json':
                return $this->fromJson($value);
            case 'collection':
                return new BaseCollection($this->fromJson($value));
            case 'date':
            case 'datetime':
                return $this->asDateTime($value);
            case 'timestamp':
                return $this->asTimeStamp($value);
            default:
                return $value;
        }
    }
    public function setAttribute($key, $value)
    {
        if ($this->hasSetMutator($key)) {
            $method = 'set' . studly_case($key) . 'Attribute';
            return $this->{$method}($value);
        } elseif (in_array($key, $this->getDates())) {
            if ($value) {
                $value = $this->fromDateTime($value);
            }
        }
        $this->attributes[$key] = $value;
    }
    public function hasSetMutator($key)
    {
        return method_exists($this, 'set' . studly_case($key) . 'Attribute');
    }
    public function getDates()
    {
        $defaults = array(static::CREATED_AT, static::UPDATED_AT, static::DELETED_AT);
        return array_merge($this->dates, $defaults);
    }
    public function fromDateTime($value)
    {
        $format = $this->getDateFormat();
        if ($value instanceof DateTime) {
            
        } elseif (is_numeric($value)) {
            $value = Carbon::createFromTimestamp($value);
        } elseif (preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/', $value)) {
            $value = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } elseif (!$value instanceof DateTime) {
            $value = Carbon::createFromFormat($format, $value);
        }
        return $value->format($format);
    }
    protected function asDateTime($value)
    {
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value);
        } elseif (preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/', $value)) {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } elseif (!$value instanceof DateTime) {
            $format = $this->getDateFormat();
            return Carbon::createFromFormat($format, $value);
        }
        return Carbon::instance($value);
    }
    protected function asTimeStamp($value)
    {
        return $this->asDateTime($value)->getTimestamp();
    }
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format($this->getDateFormat());
    }
    protected function getDateFormat()
    {
        return $this->getConnection()->getQueryGrammar()->getDateFormat();
    }
    protected function asJson($value)
    {
        return json_encode($value);
    }
    public function fromJson($value, $asObject = false)
    {
        return json_decode($value, !$asObject);
    }
    public function replicate()
    {
        $attributes = array_except($this->attributes, array($this->getKeyName()));
        with($instance = new static())->setRawAttributes($attributes);
        return $instance->setRelations($this->relations);
    }
    public function getAttributes()
    {
        return $this->attributes;
    }
    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->attributes = $attributes;
        if ($sync) {
            $this->syncOriginal();
        }
    }
    public function getOriginal($key = null, $default = null)
    {
        return array_get($this->original, $key, $default);
    }
    public function syncOriginal()
    {
        $this->original = $this->attributes;
        return $this;
    }
    public function isDirty($attribute)
    {
        return array_key_exists($attribute, $this->getDirty());
    }
    public function getDirty()
    {
        $dirty = array();
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } elseif ($value !== $this->original[$key] && !$this->originalIsNumericallyEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }
    protected function originalIsNumericallyEquivalent($key)
    {
        $current = $this->attributes[$key];
        $original = $this->original[$key];
        return is_numeric($current) && is_numeric($original) && strcmp((string) $current, (string) $original) === 0;
    }
    public function getRelations()
    {
        return $this->relations;
    }
    public function getRelation($relation)
    {
        return $this->relations[$relation];
    }
    public function setRelation($relation, $value)
    {
        $this->relations[$relation] = $value;
        return $this;
    }
    public function setRelations(array $relations)
    {
        $this->relations = $relations;
        return $this;
    }
    public function getConnection()
    {
        return static::resolveConnection($this->connection);
    }
    public function getConnectionName()
    {
        return $this->connection;
    }
    public function setConnection($name)
    {
        $this->connection = $name;
        return $this;
    }
    public static function resolveConnection($connection = null)
    {
        return static::$resolver->connection($connection);
    }
    public static function getConnectionResolver()
    {
        return static::$resolver;
    }
    public static function setConnectionResolver(Resolver $resolver)
    {
        static::$resolver = $resolver;
    }
    public static function unsetConnectionResolver()
    {
        static::$resolver = null;
    }
    public static function getEventDispatcher()
    {
        return static::$dispatcher;
    }
    public static function setEventDispatcher(Dispatcher $dispatcher)
    {
        static::$dispatcher = $dispatcher;
    }
    public static function unsetEventDispatcher()
    {
        static::$dispatcher = null;
    }
    public function getMutatedAttributes()
    {
        $class = get_class($this);
        if (isset(static::$mutatorCache[$class])) {
            return static::$mutatorCache[get_class($this)];
        }
        return array();
    }
    public function __get($key)
    {
        return $this->getAttribute($key);
    }
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }
    public function offsetExists($offset)
    {
        return isset($this->{$offset});
    }
    public function offsetGet($offset)
    {
        return $this->{$offset};
    }
    public function offsetSet($offset, $value)
    {
        $this->{$offset} = $value;
    }
    public function offsetUnset($offset)
    {
        unset($this->{$offset});
    }
    public function __isset($key)
    {
        return isset($this->attributes[$key]) || isset($this->relations[$key]) || $this->hasGetMutator($key) && !is_null($this->getAttributeValue($key));
    }
    public function __unset($key)
    {
        unset($this->attributes[$key]);
        unset($this->relations[$key]);
    }
    public function __call($method, $parameters)
    {
        if (in_array($method, array('increment', 'decrement'))) {
            return call_user_func_array(array($this, $method), $parameters);
        }
        $query = $this->newQuery();
        return call_user_func_array(array($query, $method), $parameters);
    }
    public static function __callStatic($method, $parameters)
    {
        $instance = new static();
        return call_user_func_array(array($instance, $method), $parameters);
    }
    public function __toString()
    {
        return $this->toJson();
    }
    public function __wakeup()
    {
        $this->bootIfNotBooted();
    }
}
namespace Royalcms\Component\Database;

use Royalcms\Component\Database\Connectors\ConnectionFactory;
class DatabaseManager implements ConnectionResolverInterface
{
    protected $royalcms;
    protected $factory;
    protected $connections = array();
    protected $extensions = array();
    public function __construct($royalcms, ConnectionFactory $factory)
    {
        $this->royalcms = $royalcms;
        $this->factory = $factory;
    }
    public function connection($name = null)
    {
        $name = $name ?: $this->getDefaultConnection();
        if (!isset($this->connections[$name])) {
            $connection = $this->makeConnection($name);
            $this->connections[$name] = $this->prepare($connection);
        }
        return $this->connections[$name];
    }
    public function reconnect($name = null)
    {
        $name = $name ?: $this->getDefaultConnection();
        $this->disconnect($name);
        return $this->connection($name);
    }
    public function disconnect($name = null)
    {
        $name = $name ?: $this->getDefaultConnection();
        unset($this->connections[$name]);
    }
    protected function makeConnection($name)
    {
        $config = $this->getConfig($name);
        if (isset($this->extensions[$name])) {
            return call_user_func($this->extensions[$name], $config, $name);
        }
        $driver = $config['driver'];
        if (isset($this->extensions[$driver])) {
            return call_user_func($this->extensions[$driver], $config, $name);
        }
        return $this->factory->make($config, $name);
    }
    protected function prepare(Connection $connection)
    {
        $connection->setFetchMode($this->royalcms['config']['database.fetch']);
        if ($this->royalcms->bound('events')) {
            $connection->setEventDispatcher($this->royalcms['events']);
        }
        $royalcms = $this->royalcms;
        $connection->setCacheManager(function () use($royalcms) {
            return $royalcms['cache'];
        });
        $connection->setPaginator(function () use($royalcms) {
            return $royalcms['paginator'];
        });
        return $connection;
    }
    protected function getConfig($name)
    {
        $name = $name ?: $this->getDefaultConnection();
        $connections = $this->royalcms['config']['database.connections'];
        if (is_null($config = array_get($connections, $name))) {
            throw new \InvalidArgumentException("Database [{$name}] not configured.");
        }
        return $config;
    }
    public function getDefaultConnection()
    {
        return $this->royalcms['config']['database.defaultconnection'];
    }
    public function setDefaultConnection($name)
    {
        $this->royalcms['config']['database.defaultconnection'] = $name;
    }
    public function extend($name, $resolver)
    {
        $this->extensions[$name] = $resolver;
    }
    public function getConnections()
    {
        return $this->connections;
    }
    public function __call($method, $parameters)
    {
        return call_user_func_array(array($this->connection(), $method), $parameters);
    }
}
namespace Royalcms\Component\Database;

use PDO;
use Closure;
use DateTime;
use Royalcms\Component\Database\Query\Processors\Processor;
use Doctrine\DBAL\Connection as DoctrineConnection;
class Connection implements ConnectionInterface
{
    protected $pdo;
    protected $readPdo;
    protected $queryGrammar;
    protected $schemaGrammar;
    protected $postProcessor;
    protected $events;
    protected $paginator;
    protected $cache;
    protected $fetchMode = PDO::FETCH_ASSOC;
    protected $transactions = 0;
    protected $queryLog = array();
    protected $loggingQueries = true;
    protected $pretending = false;
    protected $database;
    protected $tablePrefix = '';
    protected $config = array();
    public function __construct(PDO $pdo, $database = '', $tablePrefix = '', array $config = array())
    {
        $this->pdo = $pdo;
        $this->database = $database;
        $this->tablePrefix = $tablePrefix;
        $this->config = $config;
        $this->useDefaultQueryGrammar();
        $this->useDefaultPostProcessor();
    }
    public function useDefaultQueryGrammar()
    {
        $this->queryGrammar = $this->getDefaultQueryGrammar();
    }
    protected function getDefaultQueryGrammar()
    {
        return new Query\Grammars\Grammar();
    }
    public function useDefaultSchemaGrammar()
    {
        $this->schemaGrammar = $this->getDefaultSchemaGrammar();
    }
    protected function getDefaultSchemaGrammar()
    {
        
    }
    public function useDefaultPostProcessor()
    {
        $this->postProcessor = $this->getDefaultPostProcessor();
    }
    protected function getDefaultPostProcessor()
    {
        return new Query\Processors\Processor();
    }
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }
        return new Schema\Builder($this);
    }
    public function table($table)
    {
        $processor = $this->getPostProcessor();
        $query = new Query\Builder($this, $this->getQueryGrammar(), $processor);
        return $query->from($table);
    }
    public function raw($value)
    {
        return new Query\Expression($value);
    }
    public function selectOne($query, $bindings = array())
    {
        $records = $this->select($query, $bindings);
        return count($records) > 0 ? reset($records) : null;
    }
    public function select($query, $bindings = array())
    {
        return $this->run($query, $bindings, function ($me, $query, $bindings) {
            if ($me->pretending()) {
                return array();
            }
            $statement = $me->getReadPdo()->prepare($query);
            $statement->execute($me->prepareBindings($bindings));
            return $statement->fetchAll($me->getFetchMode());
        });
    }
    public function insert($query, $bindings = array())
    {
        return $this->statement($query, $bindings);
    }
    public function update($query, $bindings = array())
    {
        return $this->affectingStatement($query, $bindings);
    }
    public function delete($query, $bindings = array())
    {
        return $this->affectingStatement($query, $bindings);
    }
    public function statement($query, $bindings = array())
    {
        return $this->run($query, $bindings, function ($me, $query, $bindings) {
            if ($me->pretending()) {
                return true;
            }
            $bindings = $me->prepareBindings($bindings);
            return $me->getPdo()->prepare($query)->execute($bindings);
        });
    }
    public function affectingStatement($query, $bindings = array())
    {
        return $this->run($query, $bindings, function ($me, $query, $bindings) {
            if ($me->pretending()) {
                return 0;
            }
            $statement = $me->getPdo()->prepare($query);
            $statement->execute($me->prepareBindings($bindings));
            return $statement->rowCount();
        });
    }
    public function unprepared($query)
    {
        return $this->run($query, array(), function ($me, $query) {
            if ($me->pretending()) {
                return true;
            }
            return (bool) $me->getPdo()->exec($query);
        });
    }
    public function prepareBindings(array $bindings)
    {
        $grammar = $this->getQueryGrammar();
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTime) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif ($value === false) {
                $bindings[$key] = 0;
            }
        }
        return $bindings;
    }
    public function transaction(Closure $callback)
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }
        return $result;
    }
    public function beginTransaction()
    {
        ++$this->transactions;
        if ($this->transactions == 1) {
            $this->pdo->beginTransaction();
        }
        $this->fireConnectionEvent('beganTransaction');
    }
    public function commit()
    {
        if ($this->transactions == 1) {
            $this->pdo->commit();
        }
        --$this->transactions;
        $this->fireConnectionEvent('committed');
    }
    public function rollBack()
    {
        if ($this->transactions == 1) {
            $this->transactions = 0;
            $this->pdo->rollBack();
        } else {
            --$this->transactions;
        }
        $this->fireConnectionEvent('rollingBack');
    }
    public function transactionLevel()
    {
        return $this->transactions;
    }
    public function pretend(Closure $callback)
    {
        $this->pretending = true;
        $this->queryLog = array();
        $callback($this);
        $this->pretending = false;
        return $this->queryLog;
    }
    protected function run($query, $bindings, Closure $callback)
    {
        $start = microtime(true);
        try {
            $result = $callback($this, $query, $bindings);
        } catch (\Exception $e) {
            throw new QueryException($query, $this->prepareBindings($bindings), $e);
        }
        $time = $this->getElapsedTime($start);
        $this->logQuery($query, $bindings, $time);
        return $result;
    }
    public function logQuery($query, $bindings, $time = null)
    {
        if (isset($this->events)) {
            $this->events->fire('royalcms.query', array($query, $bindings, $time, $this->getName()));
        }
        if (!$this->loggingQueries) {
            return;
        }
        $this->queryLog[] = compact('query', 'bindings', 'time');
    }
    public function listen(Closure $callback)
    {
        if (isset($this->events)) {
            $this->events->listen('royalcms.query', $callback);
        }
    }
    protected function fireConnectionEvent($event)
    {
        if (isset($this->events)) {
            $this->events->fire('connection.' . $this->getName() . '.' . $event, $this);
        }
    }
    protected function getElapsedTime($start)
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
    public function getDoctrineColumn($table, $column)
    {
        $schema = $this->getDoctrineSchemaManager();
        return $schema->listTableDetails($table)->getColumn($column);
    }
    public function getDoctrineSchemaManager()
    {
        return $this->getDoctrineDriver()->getSchemaManager($this->getDoctrineConnection());
    }
    public function getDoctrineConnection()
    {
        $driver = $this->getDoctrineDriver();
        $data = array('pdo' => $this->pdo, 'dbname' => $this->getConfig('database'));
        return new DoctrineConnection($data, $driver);
    }
    public function getPdo()
    {
        return $this->pdo;
    }
    public function getReadPdo()
    {
        if ($this->transactions >= 1) {
            return $this->getPdo();
        }
        return $this->readPdo ?: $this->pdo;
    }
    public function setPdo(PDO $pdo)
    {
        $this->pdo = $pdo;
        return $this;
    }
    public function setReadPdo(PDO $pdo)
    {
        $this->readPdo = $pdo;
        return $this;
    }
    public function getName()
    {
        return $this->getConfig('name');
    }
    public function getConfig($option)
    {
        return array_get($this->config, $option);
    }
    public function getDriverName()
    {
        return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
    public function getQueryGrammar()
    {
        return $this->queryGrammar;
    }
    public function setQueryGrammar(Query\Grammars\Grammar $grammar)
    {
        $this->queryGrammar = $grammar;
    }
    public function getSchemaGrammar()
    {
        return $this->schemaGrammar;
    }
    public function setSchemaGrammar(Schema\Grammars\Grammar $grammar)
    {
        $this->schemaGrammar = $grammar;
    }
    public function getPostProcessor()
    {
        return $this->postProcessor;
    }
    public function setPostProcessor(Processor $processor)
    {
        $this->postProcessor = $processor;
    }
    public function getEventDispatcher()
    {
        return $this->events;
    }
    public function setEventDispatcher(\Royalcms\Component\Event\Dispatcher $events)
    {
        $this->events = $events;
    }
    public function getPaginator()
    {
        if ($this->paginator instanceof Closure) {
            $this->paginator = call_user_func($this->paginator);
        }
        return $this->paginator;
    }
    public function setPaginator($paginator)
    {
        $this->paginator = $paginator;
    }
    public function getCacheManager()
    {
        if ($this->cache instanceof Closure) {
            $this->cache = call_user_func($this->cache);
        }
        return $this->cache;
    }
    public function setCacheManager($cache)
    {
        $this->cache = $cache;
    }
    public function pretending()
    {
        return $this->pretending === true;
    }
    public function getFetchMode()
    {
        return $this->fetchMode;
    }
    public function setFetchMode($fetchMode)
    {
        $this->fetchMode = $fetchMode;
    }
    public function getQueryLog()
    {
        return $this->queryLog;
    }
    public function flushQueryLog()
    {
        $this->queryLog = array();
    }
    public function enableQueryLog()
    {
        $this->loggingQueries = true;
    }
    public function disableQueryLog()
    {
        $this->loggingQueries = false;
    }
    public function logging()
    {
        return $this->loggingQueries;
    }
    public function getDatabaseName()
    {
        return $this->database;
    }
    public function setDatabaseName($database)
    {
        $this->database = $database;
    }
    public function getTableFullName($table)
    {
        return $this->tablePrefix . $table;
    }
    public function getTablePrefix()
    {
        return $this->tablePrefix;
    }
    public function setTablePrefix($prefix)
    {
        $this->tablePrefix = $prefix;
        $this->getQueryGrammar()->setTablePrefix($prefix);
    }
    public function withTablePrefix(Grammar $grammar)
    {
        $grammar->setTablePrefix($this->tablePrefix);
        return $grammar;
    }
}
namespace Royalcms\Component\Database;

use Closure;
interface ConnectionInterface
{
    public function selectOne($query, $bindings = array());
    public function select($query, $bindings = array());
    public function insert($query, $bindings = array());
    public function update($query, $bindings = array());
    public function delete($query, $bindings = array());
    public function statement($query, $bindings = array());
    public function transaction(Closure $callback);
}
namespace Royalcms\Component\Database;

use Royalcms\Component\Database\Schema\MySqlBuilder;
use Doctrine\DBAL\Driver\PDOMySql\Driver as DoctrineDriver;
use Royalcms\Component\Database\Query\Grammars\MySqlGrammar as QueryGrammar;
use Royalcms\Component\Database\Schema\Grammars\MySqlGrammar as SchemaGrammar;
class MySqlConnection extends Connection
{
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }
        return new MySqlBuilder($this);
    }
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar());
    }
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar());
    }
    protected function getDefaultPostProcessor()
    {
        return new Query\Processors\MySqlProcessor();
    }
    protected function getDoctrineDriver()
    {
        return new DoctrineDriver();
    }
}
namespace Royalcms\Component\Database;

interface ConnectionResolverInterface
{
    public function connection($name = null);
    public function getDefaultConnection();
    public function setDefaultConnection($name);
}
namespace Royalcms\Component\Database\Connectors;

use PDO;
use Royalcms\Component\Container\Container;
use Royalcms\Component\Database\MySqlConnection;
use Royalcms\Component\Database\SQLiteConnection;
use Royalcms\Component\Database\PostgresConnection;
use Royalcms\Component\Database\SqlServerConnection;
class ConnectionFactory
{
    protected $container;
    public function __construct(Container $container)
    {
        $this->container = $container;
    }
    public function make(array $config, $name = null)
    {
        $config = $this->parseConfig($config, $name);
        if (isset($config['read'])) {
            return $this->createReadWriteConnection($config);
        } else {
            return $this->createSingleConnection($config);
        }
    }
    protected function createSingleConnection(array $config)
    {
        $pdo = $this->createConnector($config)->connect($config);
        return $this->createConnection($config['driver'], $pdo, $config['database'], $config['prefix'], $config);
    }
    protected function createReadWriteConnection(array $config)
    {
        $connection = $this->createSingleConnection($this->getWriteConfig($config));
        return $connection->setReadPdo($this->createReadPdo($config));
    }
    protected function createReadPdo(array $config)
    {
        $readConfig = $this->getReadConfig($config);
        return $this->createConnector($readConfig)->connect($readConfig);
    }
    protected function getReadConfig(array $config)
    {
        $readConfig = $this->getReadWriteConfig($config, 'read');
        return $this->mergeReadWriteConfig($config, $readConfig);
    }
    protected function getWriteConfig(array $config)
    {
        $writeConfig = $this->getReadWriteConfig($config, 'write');
        return $this->mergeReadWriteConfig($config, $writeConfig);
    }
    protected function getReadWriteConfig(array $config, $type)
    {
        if (isset($config[$type][0])) {
            return $config[$type][array_rand($config[$type])];
        } else {
            return $config[$type];
        }
    }
    protected function mergeReadWriteConfig(array $config, array $merge)
    {
        return array_except(array_merge($config, $merge), array('read', 'write'));
    }
    protected function parseConfig(array $config, $name)
    {
        return array_add(array_add($config, 'prefix', ''), 'name', $name);
    }
    public function createConnector(array $config)
    {
        if (!isset($config['driver'])) {
            throw new \InvalidArgumentException('A driver must be specified.');
        }
        if ($this->container->bound($key = "db.connector.{$config['driver']}")) {
            return $this->container->make($key);
        }
        switch ($config['driver']) {
            case 'mysql':
                return new MySqlConnector();
            case 'pgsql':
                return new PostgresConnector();
            case 'sqlite':
                return new SQLiteConnector();
            case 'sqlsrv':
                return new SqlServerConnector();
        }
        throw new \InvalidArgumentException("Unsupported driver [{$config['driver']}]");
    }
    protected function createConnection($driver, PDO $connection, $database, $prefix = '', array $config = array())
    {
        if ($this->container->bound($key = "db.connection.{$driver}")) {
            return $this->container->make($key, array($connection, $database, $prefix, $config));
        }
        switch ($driver) {
            case 'mysql':
                return new MySqlConnection($connection, $database, $prefix, $config);
            case 'pgsql':
                return new PostgresConnection($connection, $database, $prefix, $config);
            case 'sqlite':
                return new SQLiteConnection($connection, $database, $prefix, $config);
            case 'sqlsrv':
                return new SqlServerConnection($connection, $database, $prefix, $config);
        }
        throw new \InvalidArgumentException("Unsupported driver [{$driver}]");
    }
}
namespace Royalcms\Component\Database\Connectors;

interface ConnectorInterface
{
    public function connect(array $config);
}
namespace Royalcms\Component\Database\Connectors;

use PDO;
class Connector
{
    protected $options = array(PDO::ATTR_CASE => PDO::CASE_NATURAL, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL, PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_EMULATE_PREPARES => false);
    public function getOptions(array $config)
    {
        $options = array_get($config, 'options', array());
        return array_diff_key($this->options, $options) + $options;
    }
    public function createConnection($dsn, array $config, array $options)
    {
        $username = array_get($config, 'username');
        $password = array_get($config, 'password');
        return new PDO($dsn, $username, $password, $options);
    }
    public function getDefaultOptions()
    {
        return $this->options;
    }
    public function setDefaultOptions(array $options)
    {
        $this->options = $options;
    }
}
namespace Royalcms\Component\Database\Connectors;

use PDO;
class MySqlConnector extends Connector implements ConnectorInterface
{
    public function connect(array $config)
    {
        $dsn = $this->getDsn($config);
        $options = $this->getOptions($config);
        $connection = $this->createConnection($dsn, $config, $options);
        if (!empty($config['database'])) {
            $connection->exec("use `{$config['database']}`;");
        }
        $collation = $config['collation'];
        if (isset($config['charset'])) {
            $charset = $config['charset'];
            $names = "set names '{$charset}'" . (!is_null($collation) ? " collate '{$collation}'" : '');
            $connection->prepare($names)->execute();
        }
        if (isset($config['timezone'])) {
            $connection->prepare('set time_zone="' . $config['timezone'] . '"')->execute();
        }
        $this->setModes($connection, $config);
        return $connection;
    }
    protected function getDsn(array $config)
    {
        return $this->configHasSocket($config) ? $this->getSocketDsn($config) : $this->getHostDsn($config);
    }
    protected function configHasSocket(array $config)
    {
        return isset($config['unix_socket']) && !empty($config['unix_socket']);
    }
    protected function getSocketDsn(array $config)
    {
        return "mysql:unix_socket={$config['unix_socket']};dbname={$config['database']}";
    }
    protected function getHostDsn(array $config)
    {
        $host = $database = $port = null;
        extract($config, EXTR_IF_EXISTS);
        return isset($port) ? "mysql:host={$host};port={$port};dbname={$database}" : "mysql:host={$host};dbname={$database}";
    }
    protected function setModes(PDO $connection, array $config)
    {
        if (isset($config['modes'])) {
            $modes = implode(',', $config['modes']);
            $connection->prepare("set session sql_mode='{$modes}'")->execute();
        } elseif (isset($config['strict'])) {
            if ($config['strict']) {
                $connection->prepare('set session sql_mode=\'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION\'')->execute();
            } else {
                $connection->prepare('set session sql_mode=\'NO_ENGINE_SUBSTITUTION\'')->execute();
            }
        }
    }
}
namespace Royalcms\Component\Database\Query\Processors;

use Royalcms\Component\Database\Query\Builder;
class Processor
{
    public function processSelect(Builder $query, $results)
    {
        return $results;
    }
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $query->getConnection()->insert($sql, $values);
        $id = $query->getConnection()->getPdo()->lastInsertId($sequence);
        return is_numeric($id) ? (int) $id : $id;
    }
    public function processColumnListing($results)
    {
        return $results;
    }
}
namespace Royalcms\Component\Database\Query\Processors;

class MySqlProcessor extends Processor
{
    public function processColumnListing($results)
    {
        return array_map(function ($r) {
            return $r->column_name;
        }, $results);
    }
}
namespace Royalcms\Component\Database;

abstract class Grammar
{
    protected $tablePrefix = '';
    public function wrapArray(array $values)
    {
        return array_map(array($this, 'wrap'), $values);
    }
    public function wrapTable($table)
    {
        if ($this->isExpression($table)) {
            return $this->getValue($table);
        }
        return $this->wrap($this->tablePrefix . $table);
    }
    public function wrap($value)
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }
        if (strpos(strtolower($value), ' as ') !== false) {
            $segments = explode(' ', $value);
            return $this->wrap($segments[0]) . ' as ' . $this->wrap($segments[2]);
        }
        $wrapped = array();
        $segments = explode('.', $value);
        foreach ($segments as $key => $segment) {
            if ($key == 0 && count($segments) > 1) {
                $wrapped[] = $this->wrapTable($segment);
            } else {
                $wrapped[] = $this->wrapValue($segment);
            }
        }
        return implode('.', $wrapped);
    }
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }
        return '"' . str_replace('"', '""', $value) . '"';
    }
    public function columnize(array $columns)
    {
        return implode(', ', array_map(array($this, 'wrap'), $columns));
    }
    public function parameterize(array $values)
    {
        return implode(', ', array_map(array($this, 'parameter'), $values));
    }
    public function parameter($value)
    {
        return $this->isExpression($value) ? $this->getValue($value) : '?';
    }
    public function getValue($expression)
    {
        return $expression->getValue();
    }
    public function isExpression($value)
    {
        return $value instanceof Query\Expression;
    }
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }
    public function getTablePrefix()
    {
        return $this->tablePrefix;
    }
    public function setTablePrefix($prefix)
    {
        $this->tablePrefix = $prefix;
        return $this;
    }
}
namespace Royalcms\Component\Database\Query\Grammars;

use Royalcms\Component\Database\Query\Builder;
use Royalcms\Component\Database\Grammar as BaseGrammar;
class Grammar extends BaseGrammar
{
    protected $selectComponents = array('aggregate', 'columns', 'from', 'joins', 'wheres', 'groups', 'havings', 'orders', 'limit', 'offset', 'unions', 'lock');
    public function compileSelect(Builder $query)
    {
        if (is_null($query->columns)) {
            $query->columns = array('*');
        }
        return trim($this->concatenate($this->compileComponents($query)));
    }
    protected function compileComponents(Builder $query)
    {
        $sql = array();
        foreach ($this->selectComponents as $component) {
            if (!is_null($query->{$component})) {
                $method = 'compile' . ucfirst($component);
                $sql[$component] = $this->{$method}($query, $query->{$component});
            }
        }
        return $sql;
    }
    protected function compileAggregate(Builder $query, $aggregate)
    {
        $column = $this->columnize($aggregate['columns']);
        if ($query->distinct && $column !== '*') {
            $column = 'distinct ' . $column;
        }
        return 'select ' . $aggregate['function'] . '(' . $column . ') as aggregate';
    }
    protected function compileColumns(Builder $query, $columns)
    {
        if (!is_null($query->aggregate)) {
            return;
        }
        $select = $query->distinct ? 'select distinct ' : 'select ';
        return $select . $this->columnize($columns);
    }
    protected function compileFrom(Builder $query, $table)
    {
        return 'from ' . $this->wrapTable($table);
    }
    protected function compileJoins(Builder $query, $joins)
    {
        $sql = array();
        foreach ($joins as $join) {
            $table = $this->wrapTable($join->table);
            $clauses = array();
            foreach ($join->clauses as $clause) {
                $clauses[] = $this->compileJoinConstraint($clause);
            }
            $clauses[0] = $this->removeLeadingBoolean($clauses[0]);
            $clauses = implode(' ', $clauses);
            $type = $join->type;
            $sql[] = "{$type} join {$table} on {$clauses}";
        }
        return implode(' ', $sql);
    }
    protected function compileJoinConstraint(array $clause)
    {
        $first = $this->wrap($clause['first']);
        $second = $clause['where'] ? '?' : $this->wrap($clause['second']);
        return "{$clause['boolean']} {$first} {$clause['operator']} {$second}";
    }
    protected function compileWheres(Builder $query)
    {
        $sql = array();
        if (is_null($query->wheres)) {
            return '';
        }
        foreach ($query->wheres as $where) {
            $method = "where{$where['type']}";
            $sql[] = $where['boolean'] . ' ' . $this->{$method}($query, $where);
        }
        if (count($sql) > 0) {
            $sql = implode(' ', $sql);
            return 'where ' . preg_replace('/and |or /', '', $sql, 1);
        }
        return '';
    }
    protected function whereNested(Builder $query, $where)
    {
        $nested = $where['query'];
        return '(' . substr($this->compileWheres($nested), 6) . ')';
    }
    protected function whereSub(Builder $query, $where)
    {
        $select = $this->compileSelect($where['query']);
        return $this->wrap($where['column']) . ' ' . $where['operator'] . " ({$select})";
    }
    protected function whereBasic(Builder $query, $where)
    {
        $value = $this->parameter($where['value']);
        return $this->wrap($where['column']) . ' ' . $where['operator'] . ' ' . $value;
    }
    protected function whereBetween(Builder $query, $where)
    {
        $between = $where['not'] ? 'not between' : 'between';
        return $this->wrap($where['column']) . ' ' . $between . ' ? and ?';
    }
    protected function whereExists(Builder $query, $where)
    {
        return 'exists (' . $this->compileSelect($where['query']) . ')';
    }
    protected function whereNotExists(Builder $query, $where)
    {
        return 'not exists (' . $this->compileSelect($where['query']) . ')';
    }
    protected function whereIn(Builder $query, $where)
    {
        $values = $this->parameterize($where['values']);
        return $this->wrap($where['column']) . ' in (' . $values . ')';
    }
    protected function whereNotIn(Builder $query, $where)
    {
        $values = $this->parameterize($where['values']);
        return $this->wrap($where['column']) . ' not in (' . $values . ')';
    }
    protected function whereInSub(Builder $query, $where)
    {
        $select = $this->compileSelect($where['query']);
        return $this->wrap($where['column']) . ' in (' . $select . ')';
    }
    protected function whereNotInSub(Builder $query, $where)
    {
        $select = $this->compileSelect($where['query']);
        return $this->wrap($where['column']) . ' not in (' . $select . ')';
    }
    protected function whereNull(Builder $query, $where)
    {
        return $this->wrap($where['column']) . ' is null';
    }
    protected function whereNotNull(Builder $query, $where)
    {
        return $this->wrap($where['column']) . ' is not null';
    }
    protected function whereDay(Builder $query, $where)
    {
        return $this->dateBasedWhere('day', $query, $where);
    }
    protected function whereMonth(Builder $query, $where)
    {
        return $this->dateBasedWhere('month', $query, $where);
    }
    protected function whereYear(Builder $query, $where)
    {
        return $this->dateBasedWhere('year', $query, $where);
    }
    protected function dateBasedWhere($type, Builder $query, $where)
    {
        $value = $this->parameter($where['value']);
        return $type . '(' . $this->wrap($where['column']) . ') ' . $where['operator'] . ' ' . $value;
    }
    protected function whereRaw(Builder $query, $where)
    {
        return $where['sql'];
    }
    protected function compileGroups(Builder $query, $groups)
    {
        return 'group by ' . $this->columnize($groups);
    }
    protected function compileHavings(Builder $query, $havings)
    {
        $sql = implode(' ', array_map(array($this, 'compileHaving'), $havings));
        return 'having ' . preg_replace('/and /', '', $sql, 1);
    }
    protected function compileHaving(array $having)
    {
        if ($having['type'] === 'raw') {
            return $having['boolean'] . ' ' . $having['sql'];
        }
        return $this->compileBasicHaving($having);
    }
    protected function compileBasicHaving($having)
    {
        $column = $this->wrap($having['column']);
        $parameter = $this->parameter($having['value']);
        return 'and ' . $column . ' ' . $having['operator'] . ' ' . $parameter;
    }
    protected function compileOrders(Builder $query, $orders)
    {
        $me = $this;
        return 'order by ' . implode(', ', array_map(function ($order) use($me) {
            if (isset($order['sql'])) {
                return $order['sql'];
            }
            return $me->wrap($order['column']) . ' ' . $order['direction'];
        }, $orders));
    }
    protected function compileLimit(Builder $query, $limit)
    {
        return 'limit ' . (int) $limit;
    }
    protected function compileOffset(Builder $query, $offset)
    {
        return 'offset ' . (int) $offset;
    }
    protected function compileUnions(Builder $query)
    {
        $sql = '';
        foreach ($query->unions as $union) {
            $sql .= $this->compileUnion($union);
        }
        return ltrim($sql);
    }
    protected function compileUnion(array $union)
    {
        $joiner = $union['all'] ? ' union all ' : ' union ';
        return $joiner . $union['query']->toSql();
    }
    public function compileInsert(Builder $query, array $values)
    {
        $table = $this->wrapTable($query->from);
        if (!is_array(reset($values))) {
            $values = array($values);
        }
        $columns = $this->columnize(array_keys(reset($values)));
        $parameters = $this->parameterize(reset($values));
        $value = array_fill(0, count($values), "({$parameters})");
        $parameters = implode(', ', $value);
        return "insert into {$table} ({$columns}) values {$parameters}";
    }
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        return $this->compileInsert($query, $values);
    }
    public function compileUpdate(Builder $query, $values)
    {
        $table = $this->wrapTable($query->from);
        $columns = array();
        foreach ($values as $key => $value) {
            $columns[] = $this->wrap($key) . ' = ' . $this->parameter($value);
        }
        $columns = implode(', ', $columns);
        if (isset($query->joins)) {
            $joins = ' ' . $this->compileJoins($query, $query->joins);
        } else {
            $joins = '';
        }
        $where = $this->compileWheres($query);
        return trim("update {$table}{$joins} set {$columns} {$where}");
    }
    public function compileDelete(Builder $query)
    {
        $table = $this->wrapTable($query->from);
        $where = is_array($query->wheres) ? $this->compileWheres($query) : '';
        return trim("delete from {$table} " . $where);
    }
    public function compileTruncate(Builder $query)
    {
        return array('truncate ' . $this->wrapTable($query->from) => array());
    }
    protected function compileLock(Builder $query, $value)
    {
        return is_string($value) ? $value : '';
    }
    protected function concatenate($segments)
    {
        return implode(' ', array_filter($segments, function ($value) {
            return (string) $value !== '';
        }));
    }
    protected function removeLeadingBoolean($value)
    {
        return preg_replace('/and |or /', '', $value, 1);
    }
}
namespace Royalcms\Component\Database\Query\Grammars;

use Royalcms\Component\Database\Query\Builder;
class MySqlGrammar extends Grammar
{
    protected $selectComponents = array('aggregate', 'columns', 'from', 'joins', 'wheres', 'groups', 'havings', 'orders', 'limit', 'offset', 'lock');
    public function compileSelect(Builder $query)
    {
        $sql = parent::compileSelect($query);
        if ($query->unions) {
            $sql = '(' . $sql . ') ' . $this->compileUnions($query);
        }
        return $sql;
    }
    protected function compileUnion(array $union)
    {
        $joiner = $union['all'] ? ' union all ' : ' union ';
        return $joiner . '(' . $union['query']->toSql() . ')';
    }
    protected function compileLock(Builder $query, $value)
    {
        if (is_string($value)) {
            return $value;
        }
        return $value ? 'for update' : 'lock in share mode';
    }
    public function compileUpdate(Builder $query, $values)
    {
        $sql = parent::compileUpdate($query, $values);
        if (isset($query->orders)) {
            $sql .= ' ' . $this->compileOrders($query, $query->orders);
        }
        if (isset($query->limit)) {
            $sql .= ' ' . $this->compileLimit($query, $query->limit);
        }
        return rtrim($sql);
    }
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
namespace Royalcms\Component\Session;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface as BaseSessionInterface;
interface SessionInterface extends BaseSessionInterface
{
    public function getHandler();
    public function handlerNeedsRequest();
    public function setRequestOnHandler(Request $request);
}
namespace Royalcms\Component\Session;

use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Royalcms\Component\Support\Facades\Hook;
use Royalcms\Component\Support\Facades\Config;
class Store implements SessionInterface
{
    protected $id;
    protected $name;
    protected $attributes = array();
    protected $bags = array();
    protected $metaBag;
    protected $bagData = array();
    protected $handler;
    protected $started = false;
    public function __construct($name, SessionHandlerInterface $handler, $id = null)
    {
        $this->name = $name;
        $this->handler = $handler;
        $this->metaBag = new MetadataBag();
        $this->setId($id ?: $this->generateSessionId());
    }
    public function session()
    {
        return $this->getHandler();
    }
    public function session_id()
    {
        return $this->getId();
    }
    public function destroy()
    {
        return $this->flush();
    }
    public function delete($name)
    {
        $this->remove($name);
    }
    public function start()
    {
        session_start();
        $this->loadSession();
        if (!$this->has('_token')) {
            $this->regenerateToken();
        }
        return $this->started = true;
    }
    protected function loadSession()
    {
        $this->attributes = $_SESSION;
        foreach (array_merge($this->bags, array($this->metaBag)) as $bag) {
            $this->initializeLocalBag($bag);
            $bag->initialize($this->bagData[$bag->getStorageKey()]);
        }
    }
    protected function readFromHandler()
    {
        $data = $this->handler->read($this->getId());
        return $data ? Serialize::unserialize($data) : array();
    }
    protected function initializeLocalBag($bag)
    {
        $this->bagData[$bag->getStorageKey()] = $this->get($bag->getStorageKey(), array());
        $this->forget($bag->getStorageKey());
    }
    public function getId()
    {
        return $this->id;
    }
    public function setId($id)
    {
        $this->id = $id ?: $this->generateSessionId();
    }
    protected function generateSessionId()
    {
        $sessionId = sha1(uniqid(true) . str_random(25) . microtime(true));
        return Hook::apply_filters('rc_session_generate_id', $sessionId);
    }
    public function getName()
    {
        return $this->name;
    }
    public function setName($name)
    {
        $this->name = $name;
    }
    public function invalidate($lifetime = null)
    {
        $this->attributes = array();
        $this->migrate();
        return true;
    }
    public function migrate($destroy = false, $lifetime = null)
    {
        if ($destroy) {
            $this->handler->destroy($this->getId());
        }
        $this->id = $this->generateSessionId();
        return true;
    }
    public function regenerate($destroy = false)
    {
        session_regenerate_id();
        return $this->migrate($destroy);
    }
    public function save()
    {
        $this->addBagDataToSession();
        $this->ageFlashData();
        $this->handler->write($this->getId(), serialize($this->attributes));
        $this->started = false;
    }
    protected function addBagDataToSession()
    {
        foreach (array_merge($this->bags, array($this->metaBag)) as $bag) {
            $this->put($bag->getStorageKey(), $this->bagData[$bag->getStorageKey()]);
        }
    }
    public function ageFlashData()
    {
        foreach ($this->get('flash.old', array()) as $old) {
            $this->forget($old);
        }
        $this->put('flash.old', $this->get('flash.new', array()));
        $this->put('flash.new', array());
    }
    public function has($name)
    {
        return !is_null($this->get($name));
    }
    public function get($name, $default = null)
    {
        return array_get($this->attributes, $name, $default);
    }
    public function hasOldInput($key = null)
    {
        $old = $this->getOldInput($key);
        return is_null($key) ? count($old) > 0 : !is_null($old);
    }
    public function getOldInput($key = null, $default = null)
    {
        $input = $this->get('_old_input', array());
        if (is_null($key)) {
            return $input;
        }
        return array_get($input, $key, $default);
    }
    public function set($name, $value)
    {
        array_set($this->attributes, $name, $value);
    }
    public function put($key, $value)
    {
        if (!is_array($key)) {
            $key = array($key => $value);
        }
        foreach ($key as $arrayKey => $arrayValue) {
            $this->set($arrayKey, $arrayValue);
        }
    }
    public function push($key, $value)
    {
        $array = $this->get($key, array());
        $array[] = $value;
        $this->put($key, $array);
    }
    public function flash($key, $value)
    {
        $this->put($key, $value);
        $this->push('flash.new', $key);
        $this->removeFromOldFlashData(array($key));
    }
    public function flashInput(array $value)
    {
        $this->flash('_old_input', $value);
    }
    public function reflash()
    {
        $this->mergeNewFlashes($this->get('flash.old', array()));
        $this->put('flash.old', array());
    }
    public function keep($keys = null)
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $this->mergeNewFlashes($keys);
        $this->removeFromOldFlashData($keys);
    }
    protected function mergeNewFlashes(array $keys)
    {
        $values = array_unique(array_merge($this->get('flash.new', array()), $keys));
        $this->put('flash.new', $values);
    }
    protected function removeFromOldFlashData(array $keys)
    {
        $this->put('flash.old', array_diff($this->get('flash.old', array()), $keys));
    }
    public function all()
    {
        return $this->attributes;
    }
    public function replace(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->put($key, $value);
        }
    }
    public function remove($name)
    {
        return array_pull($this->attributes, $name);
    }
    public function forget($key)
    {
        array_forget($this->attributes, $key);
    }
    public function clear()
    {
        $this->attributes = array();
        foreach ($this->bags as $bag) {
            $bag->clear();
        }
    }
    public function flush()
    {
        $this->clear();
        $_SESSION = array();
        session_unset();
        session_destroy();
        session_write_close();
    }
    public function isStarted()
    {
        return $this->started;
    }
    public function registerBag(SessionBagInterface $bag)
    {
        $this->bags[$bag->getStorageKey()] = $bag;
    }
    public function getBag($name)
    {
        return array_get($this->bags, $name, function () {
            throw new \InvalidArgumentException('Bag not registered.');
        });
    }
    public function getMetadataBag()
    {
        return $this->metaBag;
    }
    public function getBagData($name)
    {
        return array_get($this->bagData, $name, array());
    }
    public function token()
    {
        return $this->get('_token');
    }
    public function getToken()
    {
        return $this->token();
    }
    public function regenerateToken()
    {
        $this->put('_token', str_random(40));
    }
    public function getHandler()
    {
        return $this->handler;
    }
    public function handlerNeedsRequest()
    {
        return $this->handler instanceof CookieSessionHandler;
    }
    public function setRequestOnHandler(Request $request)
    {
        if ($this->handlerNeedsRequest()) {
            $this->handler->setRequest($request);
        }
    }
}
namespace Royalcms\Component\Session;

use Royalcms\Component\Support\Manager;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NullSessionHandler;
use Royalcms\Component\Support\Facades\Hook as RC_Hook;
class SessionManager extends Manager
{
    protected function callCustomCreator($driver)
    {
        return $this->buildSession(parent::callCustomCreator($driver));
    }
    protected function createArrayDriver()
    {
        return new Store($this->royalcms['config']['session.name'], new NullSessionHandler());
    }
    protected function createCookieDriver()
    {
        $lifetime = $this->royalcms['config']['session.lifetime'];
        return $this->buildSession(new CookieSessionHandler($this->royalcms['cookie'], $lifetime));
    }
    protected function createFileDriver()
    {
        return $this->createNativeDriver();
    }
    protected function createNativeDriver()
    {
        $path = $this->royalcms['config']['session.files'];
        return $this->buildSession(new FileSessionHandler($this->royalcms['files'], $path));
    }
    protected function createDatabaseDriver()
    {
        $connection = $this->getDatabaseConnection();
        $table = $connection->getTablePrefix() . $this->royalcms['config']['session.table'];
        return $this->buildSession(new PdoSessionHandler($connection->getPdo(), $this->getDatabaseOptions($table)));
    }
    protected function getDatabaseConnection()
    {
        $connection = $this->royalcms['config']['session.connection'];
        return $this->royalcms['db']->connection($connection);
    }
    protected function getDatabaseOptions($table)
    {
        return array('db_table' => $table, 'db_id_col' => 'id', 'db_data_col' => 'payload', 'db_time_col' => 'last_activity');
    }
    protected function createApcDriver()
    {
        return $this->createCacheBased('apc');
    }
    protected function createMemcachedDriver()
    {
        return $this->createCacheBased('memcached');
    }
    protected function createWincacheDriver()
    {
        return $this->createCacheBased('wincache');
    }
    protected function createRedisDriver()
    {
        $handler = $this->createCacheHandler('redis');
        $handler->getCache()->getStore()->setConnection($this->royalcms['config']['session.connection']);
        return $this->buildSession($handler);
    }
    protected function createCacheBased($driver)
    {
        return $this->buildSession($this->createCacheHandler($driver));
    }
    protected function createCacheHandler($driver)
    {
        $minutes = $this->royalcms['config']['session.lifetime'];
        return new CacheBasedSessionHandler($this->royalcms['cache']->driver($driver), $minutes);
    }
    protected function buildSession($handler)
    {
        $session_name = $this->royalcms['config']['session.name'];
        $session_name = RC_Hook::apply_filters('royalcms_session_name', $session_name);
        $session_id = RC_Hook::apply_filters('royalcms_session_id', null);
        return new Store($session_name, $handler, $session_id);
    }
    public function getSessionConfig()
    {
        return $this->royalcms['config']['session'];
    }
    public function getDefaultDriver()
    {
        return $this->royalcms['config']['session.driver'];
    }
    public function setDefaultDriver($name)
    {
        $this->royalcms['config']['session.driver'] = $name;
    }
}
namespace Royalcms\Component\Support;

use Closure;
abstract class Manager
{
    protected $royalcms;
    protected $customCreators = array();
    protected $drivers = array();
    public function __construct($royalcms)
    {
        $this->royalcms = $royalcms;
    }
    public function driver($driver = null)
    {
        $driver = $driver ?: $this->getDefaultDriver();
        if (!isset($this->drivers[$driver])) {
            $this->drivers[$driver] = $this->createDriver($driver);
        }
        return $this->drivers[$driver];
    }
    protected function createDriver($driver)
    {
        $method = 'create' . ucfirst($driver) . 'Driver';
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        } elseif (method_exists($this, $method)) {
            return $this->{$method}();
        }
        throw new \InvalidArgumentException("Driver [{$driver}] not supported.");
    }
    protected function callCustomCreator($driver)
    {
        return $this->customCreators[$driver]($this->royalcms);
    }
    public function extend($driver, Closure $callback)
    {
        $this->customCreators[$driver] = $callback;
        return $this;
    }
    public function getDrivers()
    {
        return $this->drivers;
    }
    public function __call($method, $parameters)
    {
        return call_user_func_array(array($this->driver(), $method), $parameters);
    }
}
namespace Royalcms\Component\Cookie;

use Symfony\Component\HttpFoundation\Cookie;
use Royalcms\Component\Support\Facades\Config;
class CookieJar
{
    protected $path = '/';
    protected $domain = null;
    protected $queued = array();
    public function make($name, $value, $minutes = 0, $path = null, $domain = null, $secure = false, $httpOnly = true)
    {
        list($path, $domain) = $this->getPathAndDomain($path, $domain);
        $time = $minutes == 0 ? 0 : time() + $minutes * 60;
        $name = Config::get('cookie.prefix') . $name;
        return new Cookie($name, $value, $time, $path, $domain, $secure, $httpOnly);
    }
    public function forever($name, $value, $path = null, $domain = null, $secure = false, $httpOnly = true)
    {
        return $this->make($name, $value, 2628000, $path, $domain, $secure, $httpOnly);
    }
    public function forget($name, $path = null, $domain = null)
    {
        return $this->make($name, null, -2628000, $path, $domain);
    }
    public function hasQueued($key)
    {
        return !is_null($this->queued($key));
    }
    public function queued($key, $default = null)
    {
        return array_get($this->queued, $key, $default);
    }
    public function queue()
    {
        if (head(func_get_args()) instanceof Cookie) {
            $cookie = head(func_get_args());
        } else {
            $cookie = call_user_func_array(array($this, 'make'), func_get_args());
        }
        $this->queued[$cookie->getName()] = $cookie;
    }
    public function unqueue($name)
    {
        $name = Config::get('cookie.prefix') . $name;
        unset($this->queued[$name]);
    }
    protected function getPathAndDomain($path, $domain)
    {
        return array($path ?: $this->path, $domain ?: $this->domain);
    }
    public function setDefaultPathAndDomain($path, $domain)
    {
        list($this->path, $this->domain) = array($path, $domain);
        return $this;
    }
    public function getQueuedCookies()
    {
        return $this->queued;
    }
}
namespace Royalcms\Component\Cookie;

use Royalcms\Component\Encryption\Encrypter;
use Royalcms\Component\Encryption\DecryptException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
class Guard implements HttpKernelInterface
{
    protected $royalcms;
    protected $encrypter;
    public function __construct(HttpKernelInterface $royalcms, Encrypter $encrypter)
    {
        $this->royalcms = $royalcms;
        $this->encrypter = $encrypter;
    }
    public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        return $this->encrypt($this->royalcms->handle($this->decrypt($request), $type, $catch));
    }
    protected function decrypt(Request $request)
    {
        foreach ($request->cookies as $key => $c) {
            try {
                $request->cookies->set($key, $this->decryptCookie($c));
            } catch (DecryptException $e) {
                $request->cookies->set($key, null);
            }
        }
        return $request;
    }
    protected function decryptCookie($cookie)
    {
        return is_array($cookie) ? $this->decryptArray($cookie) : $this->encrypter->decrypt($cookie);
    }
    protected function decryptArray(array $cookie)
    {
        $decrypted = array();
        foreach ($cookie as $key => $value) {
            $decrypted[$key] = $this->encrypter->decrypt($value);
        }
        return $decrypted;
    }
    protected function encrypt(Response $response)
    {
        foreach ($response->headers->getCookies() as $key => $c) {
            $encrypted = $this->encrypter->encrypt($c->getValue());
            $response->headers->setCookie($this->duplicate($c, $encrypted));
        }
        return $response;
    }
    protected function duplicate(Cookie $c, $value)
    {
        return new Cookie($c->getName(), $value, $c->getExpiresTime(), $c->getPath(), $c->getDomain(), $c->isSecure(), $c->isHttpOnly());
    }
}
namespace Royalcms\Component\Cookie;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
class Queue implements HttpKernelInterface
{
    protected $royalcms;
    protected $cookies;
    public function __construct(HttpKernelInterface $royalcms, CookieJar $cookies)
    {
        $this->royalcms = $royalcms;
        $this->cookies = $cookies;
    }
    public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        $response = $this->royalcms->handle($request, $type, $catch);
        foreach ($this->cookies->getQueuedCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }
        return $response;
    }
}
namespace Royalcms\Component\Encryption;

use Symfony\Component\Security\Core\Util\StringUtils;
use Symfony\Component\Security\Core\Util\SecureRandom;
class DecryptException extends \RuntimeException
{
    
}
class Encrypter
{
    protected $key;
    protected $cipher = 'rijndael-256';
    protected $mode = 'cbc';
    protected $block = 32;
    public function __construct($key)
    {
        $this->key = $key;
    }
    public function encrypt($value)
    {
        $iv = mcrypt_create_iv($this->getIvSize(), $this->getRandomizer());
        $value = base64_encode($this->padAndMcrypt($value, $iv));
        $mac = $this->hash($iv = base64_encode($iv), $value);
        return base64_encode(json_encode(compact('iv', 'value', 'mac')));
    }
    protected function padAndMcrypt($value, $iv)
    {
        $value = $this->addPadding(serialize($value));
        return mcrypt_encrypt($this->cipher, $this->key, $value, $this->mode, $iv);
    }
    public function decrypt($payload)
    {
        $payload = $this->getJsonPayload($payload);
        $value = base64_decode($payload['value']);
        $iv = base64_decode($payload['iv']);
        return unserialize($this->stripPadding($this->mcryptDecrypt($value, $iv)));
    }
    protected function mcryptDecrypt($value, $iv)
    {
        return mcrypt_decrypt($this->cipher, $this->key, $value, $this->mode, $iv);
    }
    protected function getJsonPayload($payload)
    {
        $payload = json_decode(base64_decode($payload), true);
        if (!$payload || $this->invalidPayload($payload)) {
            throw new DecryptException('Invalid data.');
        }
        if (!$this->validMac($payload)) {
            throw new DecryptException('MAC is invalid.');
        }
        return $payload;
    }
    protected function validMac(array $payload)
    {
        $bytes = with(new SecureRandom())->nextBytes(16);
        $calcMac = hash_hmac('sha256', $this->hash($payload['iv'], $payload['value']), $bytes, true);
        return StringUtils::equals(hash_hmac('sha256', $payload['mac'], $bytes, true), $calcMac);
    }
    protected function hash($iv, $value)
    {
        return hash_hmac('sha256', $iv . $value, $this->key);
    }
    protected function addPadding($value)
    {
        $pad = $this->block - strlen($value) % $this->block;
        return $value . str_repeat(chr($pad), $pad);
    }
    protected function stripPadding($value)
    {
        $pad = ord($value[($len = strlen($value)) - 1]);
        return $this->paddingIsValid($pad, $value) ? substr($value, 0, $len - $pad) : $value;
    }
    protected function paddingIsValid($pad, $value)
    {
        $beforePad = strlen($value) - $pad;
        return substr($value, $beforePad) == str_repeat(substr($value, -1), $pad);
    }
    protected function invalidPayload($data)
    {
        return !is_array($data) || !isset($data['iv']) || !isset($data['value']) || !isset($data['mac']);
    }
    protected function getIvSize()
    {
        return mcrypt_get_iv_size($this->cipher, $this->mode);
    }
    protected function getRandomizer()
    {
        if (defined('MCRYPT_DEV_URANDOM')) {
            return MCRYPT_DEV_URANDOM;
        }
        if (defined('MCRYPT_DEV_RANDOM')) {
            return MCRYPT_DEV_RANDOM;
        }
        mt_srand();
        return MCRYPT_RAND;
    }
    public function setKey($key)
    {
        $this->key = $key;
    }
    public function setCipher($cipher)
    {
        $this->cipher = $cipher;
    }
    public function setMode($mode)
    {
        $this->mode = $mode;
    }
}
namespace Royalcms\Component\Support\Facades;

class Log extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'log';
    }
}
namespace Royalcms\Component\Log;

use Monolog\Logger;
use Royalcms\Component\Support\ServiceProvider;
class LogServiceProvider extends ServiceProvider
{
    protected $defer = true;
    public function register()
    {
        $logger = new Writer(new Logger($this->royalcms['env']), $this->royalcms['events']);
        $this->royalcms->instance('log', $logger);
        $store = new FileStore($this->royalcms['files'], storage_path() . '/logs/');
        $this->royalcms->instance('log.store', $store);
        if (isset($this->royalcms['log.setup'])) {
            call_user_func($this->royalcms['log.setup'], $logger);
        }
    }
    public function provides()
    {
        return array('log', 'log.store');
    }
}
namespace Royalcms\Component\Log;

use Closure;
use Royalcms\Component\Event\Dispatcher;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
class Writer
{
    protected $monolog;
    protected $levels = array('debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency');
    protected $dispatcher;
    public function __construct(MonologLogger $monolog, Dispatcher $dispatcher = null)
    {
        $this->monolog = $monolog;
        if (isset($dispatcher)) {
            $this->dispatcher = $dispatcher;
        }
    }
    protected function callMonolog($method, $parameters)
    {
        if (is_array($parameters[0])) {
            $parameters[0] = json_encode($parameters[0]);
        }
        return call_user_func_array(array($this->monolog, $method), $parameters);
    }
    public function useFiles($path, $level = 'debug')
    {
        $level = $this->parseLevel($level);
        $this->monolog->pushHandler($handler = new StreamHandler($path, $level));
        $handler->setFormatter(new LineFormatter(null, null, true));
    }
    public function useDailyFiles($path, $days = 0, $level = 'debug')
    {
        $level = $this->parseLevel($level);
        $this->monolog->pushHandler($handler = new RotatingFileHandler($path, $days, $level));
        $handler->setFormatter(new LineFormatter(null, null, true));
    }
    protected function parseLevel($level)
    {
        switch ($level) {
            case 'debug':
                return MonologLogger::DEBUG;
            case 'info':
                return MonologLogger::INFO;
            case 'notice':
                return MonologLogger::NOTICE;
            case 'warning':
                return MonologLogger::WARNING;
            case 'error':
                return MonologLogger::ERROR;
            case 'critical':
                return MonologLogger::CRITICAL;
            case 'alert':
                return MonologLogger::ALERT;
            case 'emergency':
                return MonologLogger::EMERGENCY;
            default:
                throw new \InvalidArgumentException('Invalid log level.');
        }
    }
    public function listen(Closure $callback)
    {
        if (!isset($this->dispatcher)) {
            throw new \RuntimeException('Events dispatcher has not been set.');
        }
        $this->dispatcher->listen('royalcms.log', $callback);
    }
    public function getMonolog()
    {
        return $this->monolog;
    }
    public function getEventDispatcher()
    {
        return $this->dispatcher;
    }
    public function setEventDispatcher(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }
    protected function fireLogEvent($level, $message, array $context = array())
    {
        if (isset($this->dispatcher)) {
            $this->dispatcher->fire('royalcms.log', compact('level', 'message', 'context'));
        }
    }
    public function write()
    {
        $level = head(func_get_args());
        return call_user_func_array(array($this, $level), array_slice(func_get_args(), 1));
    }
    public function __call($method, $parameters)
    {
        if (in_array($method, $this->levels)) {
            call_user_func_array(array($this, 'fireLogEvent'), array_merge(array($method), $parameters));
            $method = 'add' . ucfirst($method);
            return $this->callMonolog($method, $parameters);
        }
        throw new \BadMethodCallException("Method [{$method}] does not exist.");
    }
}
namespace Royalcms\Component\Log;

use Royalcms\Component\Filesystem\Filesystem;
use Monolog\Logger as MonologLogger;
class FileStore
{
    protected $files;
    protected $directory;
    public function __construct(Filesystem $files, $directory)
    {
        $this->files = $files;
        $this->directory = $directory;
        if (!$this->files->isDirectory($this->directory)) {
            $this->createCacheDirectory($this->directory);
        }
    }
    protected function createCacheDirectory($path)
    {
        $bool = $this->files->makeDirectory($path, 493, true, true);
        if ($bool === false) {
            $path = str_replace(SITE_ROOT, '/', storage_path());
            rc_die(sprintf(__('目录%s没有读写权限，请设置权限为777。'), $path));
        }
    }
    private $loggers = array();
    public function getLogger($type = 'royalcms', $day = 30)
    {
        if (empty($this->loggers[$type])) {
            $this->loggers[$type] = new Writer(new MonologLogger($type));
            $this->loggers[$type]->useDailyFiles($this->directory . $type . '.log', $day);
        }
        $log = $this->loggers[$type];
        return $log;
    }
}
namespace Royalcms\Component\Timer;

class Timer
{
    protected $timers = array();
    public function __construct($startTime = null)
    {
        $this->start('royalcms', $startTime, 'Timer since ROYALCMS_START.');
    }
    public function start($name, $startTime = null, $description = null)
    {
        $start = $startTime ?: microtime(true);
        $this->timers[$name] = array('description' => $description, 'start' => $start, 'end' => null, 'time' => null, 'checkpoints' => array());
    }
    public function stop($name, $endTime = null, $decimals = 6)
    {
        $end = $endTime ?: microtime(true);
        $this->timers[$name] = $this->getTimer($name);
        $this->timers[$name]['end'] = $end;
        $this->timers[$name]['time'] = round(($end - $this->timers[$name]['start']) * 1000, $decimals);
        return $this->timers[$name];
    }
    public function checkpoint($name, $description = null, $decimals = 6)
    {
        $end = microtime(true);
        $this->timers[$name] = $this->getTimer($name);
        $count = count($this->timers[$name]['checkpoints']);
        $this->timers[$name]['checkpoints'][$count] = array('description' => $description, 'end' => $end);
        $this->timers[$name]['checkpoints'][$count]['timeFromStart'] = round($end - $this->timers[$name]['start'], $decimals);
        if ($count > 0) {
            $this->timers[$name]['checkpoints'][$count]['timeFromLastCheckpoint'] = round($end - $this->timers[$name]['checkpoints'][$count - 1]['end'], $decimals);
        }
    }
    public function getTimer($name)
    {
        if (!empty($this->timers[$name])) {
            return $this->timers[$name];
        }
        return array('description' => 'Timer since ROYALCMS_START.', 'start' => defined('ROYALCMS_START') ? ROYALCMS_START : microtime(true), 'end' => null, 'time' => null, 'checkpoints' => array());
    }
    public function getTimers()
    {
        $end = microtime(true);
        $decimals = 6;
        foreach ($this->timers as $name => $timer) {
            if ($this->timers[$name]['end'] == null) {
                $this->timers[$name]['end'] = $end;
                $this->timers[$name]['time'] = round($end - $this->timers[$name]['start'], $decimals);
            }
        }
        return $this->timers;
    }
    public function getLoadTime()
    {
        return $this->stop('royalcms');
    }
    public function formatTimer($timer)
    {
        return number_format($timer['time'] / 1000, 6);
    }
    public function dump($toFile = false)
    {
        if ($toFile) {
            $this->write();
        }
        return $this->getTimers();
    }
    public function write()
    {
        $json = $this->dump();
        $json = json_encode($json);
        $result = '';
        $pos = 0;
        $strLen = strlen($json);
        $indentStr = '	';
        $newLine = '
';
        $prevChar = '';
        $outOfQuotes = true;
        for ($i = 0; $i <= $strLen; $i++) {
            $char = substr($json, $i, 1);
            if ($char == '"' && $prevChar != '\\') {
                $outOfQuotes = !$outOfQuotes;
            } else {
                if (($char == '}' || $char == ']') && $outOfQuotes) {
                    $result .= $newLine;
                    $pos--;
                    for ($j = 0; $j < $pos; $j++) {
                        $result .= $indentStr;
                    }
                }
            }
            $result .= $char;
            if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
                $result .= $newLine;
                if ($char == '{' || $char == '[') {
                    $pos++;
                }
                for ($j = 0; $j < $pos; $j++) {
                    $result .= $indentStr;
                }
            }
            $prevChar = $char;
        }
        \RC_Logger::getLogger('timer')->info($result);
    }
    public function clear()
    {
        $this->timers = array();
    }
}
namespace Royalcms\Component\Timer\Facades;

use Royalcms\Component\Support\Facades\Facade;
class Timer extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'timer';
    }
}
namespace Royalcms\Component\Whoops;

use InvalidArgumentException;
use Royalcms\Component\Whoops\Exception\ErrorException;
use Royalcms\Component\Whoops\Exception\Inspector;
use Royalcms\Component\Whoops\Handler\CallbackHandler;
use Royalcms\Component\Whoops\Handler\Handler;
use Royalcms\Component\Whoops\Handler\HandlerInterface;
use Royalcms\Component\Whoops\Util\Misc;
use Royalcms\Component\Whoops\Util\SystemFacade;
final class Run implements RunInterface
{
    private $isRegistered;
    private $allowQuit = true;
    private $sendOutput = true;
    private $sendHttpCode = 500;
    private $handlerStack = array();
    private $silencedPatterns = array();
    private $system;
    public function __construct(SystemFacade $system = null)
    {
        $this->system = $system ?: new SystemFacade();
    }
    public function pushHandler($handler)
    {
        if (is_callable($handler)) {
            $handler = new CallbackHandler($handler);
        }
        if (!$handler instanceof HandlerInterface) {
            throw new InvalidArgumentException('Argument to ' . __METHOD__ . ' must be a callable, or instance of ' . 'Royalcms\\Component\\Whoops\\Handler\\HandlerInterface');
        }
        $this->handlerStack[] = $handler;
        return $this;
    }
    public function popHandler()
    {
        return array_pop($this->handlerStack);
    }
    public function getHandlers()
    {
        return $this->handlerStack;
    }
    public function clearHandlers()
    {
        $this->handlerStack = array();
        return $this;
    }
    private function getInspector($exception)
    {
        return new Inspector($exception);
    }
    public function register()
    {
        if (!$this->isRegistered) {
            class_exists('\\Royalcms\\Component\\Whoops\\Exception\\ErrorException');
            class_exists('\\Royalcms\\Component\\Whoops\\Exception\\FrameCollection');
            class_exists('\\Royalcms\\Component\\Whoops\\Exception\\Frame');
            class_exists('\\Royalcms\\Component\\Whoops\\Exception\\Inspector');
            $this->system->setErrorHandler(array($this, self::ERROR_HANDLER));
            $this->system->setExceptionHandler(array($this, self::EXCEPTION_HANDLER));
            $this->system->registerShutdownFunction(array($this, self::SHUTDOWN_HANDLER));
            $this->isRegistered = true;
        }
        return $this;
    }
    public function unregister()
    {
        if ($this->isRegistered) {
            $this->system->restoreExceptionHandler();
            $this->system->restoreErrorHandler();
            $this->isRegistered = false;
        }
        return $this;
    }
    public function allowQuit($exit = null)
    {
        if (func_num_args() == 0) {
            return $this->allowQuit;
        }
        return $this->allowQuit = (bool) $exit;
    }
    public function silenceErrorsInPaths($patterns, $levels = 10240)
    {
        $this->silencedPatterns = array_merge($this->silencedPatterns, array_map(function ($pattern) use($levels) {
            return array('pattern' => $pattern, 'levels' => $levels);
        }, (array) $patterns));
        return $this;
    }
    public function getSilenceErrorsInPaths()
    {
        return $this->silencedPatterns;
    }
    public function sendHttpCode($code = null)
    {
        if (func_num_args() == 0) {
            return $this->sendHttpCode;
        }
        if (!$code) {
            return $this->sendHttpCode = false;
        }
        if ($code === true) {
            $code = 500;
        }
        if ($code < 400 || 600 <= $code) {
            throw new InvalidArgumentException("Invalid status code '{$code}', must be 4xx or 5xx");
        }
        return $this->sendHttpCode = $code;
    }
    public function writeToOutput($send = null)
    {
        if (func_num_args() == 0) {
            return $this->sendOutput;
        }
        return $this->sendOutput = (bool) $send;
    }
    public function handleException($exception)
    {
        $inspector = $this->getInspector($exception);
        $this->system->startOutputBuffering();
        $handlerResponse = null;
        $handlerContentType = null;
        foreach (array_reverse($this->handlerStack) as $handler) {
            $handler->setRun($this);
            $handler->setInspector($inspector);
            $handler->setException($exception);
            $handlerResponse = $handler->handle($exception);
            $handlerContentType = method_exists($handler, 'contentType') ? $handler->contentType() : null;
            if (in_array($handlerResponse, array(Handler::LAST_HANDLER, Handler::QUIT))) {
                break;
            }
        }
        $willQuit = $handlerResponse == Handler::QUIT && $this->allowQuit();
        $output = $this->system->cleanOutputBuffer();
        if ($this->writeToOutput()) {
            if ($willQuit) {
                while ($this->system->getOutputBufferLevel() > 0) {
                    $this->system->endOutputBuffering();
                }
                if (Misc::canSendHeaders() && $handlerContentType) {
                    header("Content-Type: {$handlerContentType}");
                }
            }
            $this->writeToOutputNow($output);
        }
        if ($willQuit) {
            $this->system->flushOutputBuffer();
            $this->system->stopExecution(1);
        }
        return $output;
    }
    public function handleError($level, $message, $file = null, $line = null)
    {
        if ($level & $this->system->getErrorReportingLevel()) {
            foreach ($this->silencedPatterns as $entry) {
                $pathMatches = (bool) preg_match($entry['pattern'], $file);
                $levelMatches = $level & $entry['levels'];
                if ($pathMatches && $levelMatches) {
                    return true;
                }
            }
            $exception = new ErrorException($message, $level, $level, $file, $line);
            if ($this->canThrowExceptions) {
                throw $exception;
            } else {
                $this->handleException($exception);
            }
            return true;
        }
        return false;
    }
    public function handleShutdown()
    {
        $this->canThrowExceptions = false;
        $error = $this->system->getLastError();
        if ($error && Misc::isLevelFatal($error['type'])) {
            $this->handleError($error['type'], $error['message'], $error['file'], $error['line']);
        }
    }
    private $canThrowExceptions = true;
    private function writeToOutputNow($output)
    {
        if ($this->sendHttpCode() && \Royalcms\Component\Whoops\Util\Misc::canSendHeaders()) {
            $this->system->setHttpResponseCode($this->sendHttpCode());
        }
        echo $output;
        return $this;
    }
}
namespace Royalcms\Component\Whoops\Handler;

use Royalcms\Component\Whoops\Exception\Inspector;
use Royalcms\Component\Whoops\RunInterface;
interface HandlerInterface
{
    public function handle();
    public function setRun(RunInterface $run);
    public function setException($exception);
    public function setInspector(Inspector $inspector);
}
namespace Royalcms\Component\Whoops\Handler;

use Royalcms\Component\Whoops\Exception\Inspector;
use Royalcms\Component\Whoops\RunInterface;
abstract class Handler implements HandlerInterface
{
    const DONE = 16;
    const LAST_HANDLER = 32;
    const QUIT = 48;
    private $run;
    private $inspector;
    private $exception;
    public function setRun(RunInterface $run)
    {
        $this->run = $run;
    }
    protected function getRun()
    {
        return $this->run;
    }
    public function setInspector(Inspector $inspector)
    {
        $this->inspector = $inspector;
    }
    protected function getInspector()
    {
        return $this->inspector;
    }
    public function setException($exception)
    {
        $this->exception = $exception;
    }
    protected function getException()
    {
        return $this->exception;
    }
}
namespace Royalcms\Component\Whoops\Handler;

use Royalcms\Component\Whoops\Exception\Formatter;
class JsonResponseHandler extends Handler
{
    private $returnFrames = false;
    private $jsonApi = false;
    public function setJsonApi($jsonApi = false)
    {
        $this->jsonApi = (bool) $jsonApi;
        return $this;
    }
    public function addTraceToOutput($returnFrames = null)
    {
        if (func_num_args() == 0) {
            return $this->returnFrames;
        }
        $this->returnFrames = (bool) $returnFrames;
        return $this;
    }
    public function handle()
    {
        if ($this->jsonApi === true) {
            $response = array('errors' => array(Formatter::formatExceptionAsDataArray($this->getInspector(), $this->addTraceToOutput())));
        } else {
            $response = array('error' => Formatter::formatExceptionAsDataArray($this->getInspector(), $this->addTraceToOutput()));
        }
        echo json_encode($response, defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? JSON_PARTIAL_OUTPUT_ON_ERROR : 0);
        return Handler::QUIT;
    }
    public function contentType()
    {
        return 'application/json';
    }
}
namespace Psr\Log;

interface LoggerInterface
{
    public function emergency($message, array $context = array());
    public function alert($message, array $context = array());
    public function critical($message, array $context = array());
    public function error($message, array $context = array());
    public function warning($message, array $context = array());
    public function notice($message, array $context = array());
    public function info($message, array $context = array());
    public function debug($message, array $context = array());
    public function log($level, $message, array $context = array());
}
namespace Monolog;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
class Logger
{
    const DEBUG = 100;
    const INFO = 200;
    const NOTICE = 250;
    const WARNING = 300;
    const ERROR = 400;
    const CRITICAL = 500;
    const ALERT = 550;
    const EMERGENCY = 600;
    protected static $levels = array(100 => 'DEBUG', 200 => 'INFO', 250 => 'NOTICE', 300 => 'WARNING', 400 => 'ERROR', 500 => 'CRITICAL', 550 => 'ALERT', 600 => 'EMERGENCY');
    protected static $timezone;
    protected $name;
    protected $handlers = array();
    protected $processors = array();
    public function __construct($name)
    {
        $this->name = $name;
        if (!self::$timezone) {
            self::$timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
        }
    }
    public function getName()
    {
        return $this->name;
    }
    public function pushHandler(HandlerInterface $handler)
    {
        array_unshift($this->handlers, $handler);
    }
    public function popHandler()
    {
        if (!$this->handlers) {
            throw new \LogicException('You tried to pop from an empty handler stack.');
        }
        return array_shift($this->handlers);
    }
    public function pushProcessor($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('Processors must be valid callables (callback or object with an __invoke method), ' . var_export($callback, true) . ' given');
        }
        array_unshift($this->processors, $callback);
    }
    public function popProcessor()
    {
        if (!$this->processors) {
            throw new \LogicException('You tried to pop from an empty processor stack.');
        }
        return array_shift($this->processors);
    }
    public function addRecord($level, $message, array $context = array())
    {
        if (!$this->handlers) {
            $this->pushHandler(new StreamHandler('php://stderr', self::DEBUG));
        }
        $record = array('message' => (string) $message, 'context' => $context, 'level' => $level, 'level_name' => self::getLevelName($level), 'channel' => $this->name, 'datetime' => \DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)))->setTimeZone(self::$timezone), 'extra' => array());
        $handlerKey = null;
        foreach ($this->handlers as $key => $handler) {
            if ($handler->isHandling($record)) {
                $handlerKey = $key;
                break;
            }
        }
        if (null === $handlerKey) {
            return false;
        }
        foreach ($this->processors as $processor) {
            $record = call_user_func($processor, $record);
        }
        while (isset($this->handlers[$handlerKey]) && false === $this->handlers[$handlerKey]->handle($record)) {
            $handlerKey++;
        }
        return true;
    }
    public function addDebug($message, array $context = array())
    {
        return $this->addRecord(self::DEBUG, $message, $context);
    }
    public function addInfo($message, array $context = array())
    {
        return $this->addRecord(self::INFO, $message, $context);
    }
    public function addNotice($message, array $context = array())
    {
        return $this->addRecord(self::NOTICE, $message, $context);
    }
    public function addWarning($message, array $context = array())
    {
        return $this->addRecord(self::WARNING, $message, $context);
    }
    public function addError($message, array $context = array())
    {
        return $this->addRecord(self::ERROR, $message, $context);
    }
    public function addCritical($message, array $context = array())
    {
        return $this->addRecord(self::CRITICAL, $message, $context);
    }
    public function addAlert($message, array $context = array())
    {
        return $this->addRecord(self::ALERT, $message, $context);
    }
    public function addEmergency($message, array $context = array())
    {
        return $this->addRecord(self::EMERGENCY, $message, $context);
    }
    public static function getLevelName($level)
    {
        return self::$levels[$level];
    }
    public function isHandling($level)
    {
        $record = array('message' => '', 'context' => array(), 'level' => $level, 'level_name' => self::getLevelName($level), 'channel' => $this->name, 'datetime' => new \DateTime(), 'extra' => array());
        foreach ($this->handlers as $key => $handler) {
            if ($handler->isHandling($record)) {
                return true;
            }
        }
        return false;
    }
    public function debug($message, array $context = array())
    {
        return $this->addRecord(self::DEBUG, $message, $context);
    }
    public function info($message, array $context = array())
    {
        return $this->addRecord(self::INFO, $message, $context);
    }
    public function notice($message, array $context = array())
    {
        return $this->addRecord(self::NOTICE, $message, $context);
    }
    public function warn($message, array $context = array())
    {
        return $this->addRecord(self::WARNING, $message, $context);
    }
    public function err($message, array $context = array())
    {
        return $this->addRecord(self::ERROR, $message, $context);
    }
    public function crit($message, array $context = array())
    {
        return $this->addRecord(self::CRITICAL, $message, $context);
    }
    public function alert($message, array $context = array())
    {
        return $this->addRecord(self::ALERT, $message, $context);
    }
    public function emerg($message, array $context = array())
    {
        return $this->addRecord(self::EMERGENCY, $message, $context);
    }
}
namespace Monolog\Handler;

use Monolog\Logger;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
abstract class AbstractHandler implements HandlerInterface
{
    protected $level = Logger::DEBUG;
    protected $bubble = false;
    protected $formatter;
    protected $processors = array();
    public function __construct($level = Logger::DEBUG, $bubble = true)
    {
        $this->level = $level;
        $this->bubble = $bubble;
    }
    public function isHandling(array $record)
    {
        return $record['level'] >= $this->level;
    }
    public function handleBatch(array $records)
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }
    public function close()
    {
        
    }
    public function pushProcessor($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('Processors must be valid callables (callback or object with an __invoke method), ' . var_export($callback, true) . ' given');
        }
        array_unshift($this->processors, $callback);
    }
    public function popProcessor()
    {
        if (!$this->processors) {
            throw new \LogicException('You tried to pop from an empty processor stack.');
        }
        return array_shift($this->processors);
    }
    public function setFormatter(FormatterInterface $formatter)
    {
        $this->formatter = $formatter;
    }
    public function getFormatter()
    {
        if (!$this->formatter) {
            $this->formatter = $this->getDefaultFormatter();
        }
        return $this->formatter;
    }
    public function setLevel($level)
    {
        $this->level = $level;
    }
    public function getLevel()
    {
        return $this->level;
    }
    public function setBubble($bubble)
    {
        $this->bubble = $bubble;
    }
    public function getBubble()
    {
        return $this->bubble;
    }
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Exception $e) {
            
        }
    }
    protected function getDefaultFormatter()
    {
        return new LineFormatter();
    }
}
namespace Monolog\Handler;

abstract class AbstractProcessingHandler extends AbstractHandler
{
    public function handle(array $record)
    {
        if ($record['level'] < $this->level) {
            return false;
        }
        $record = $this->processRecord($record);
        $record['formatted'] = $this->getFormatter()->format($record);
        $this->write($record);
        return false === $this->bubble;
    }
    protected abstract function write(array $record);
    protected function processRecord(array $record)
    {
        if ($this->processors) {
            foreach ($this->processors as $processor) {
                $record = call_user_func($processor, $record);
            }
        }
        return $record;
    }
}
namespace Monolog\Handler;

use Monolog\Logger;
class StreamHandler extends AbstractProcessingHandler
{
    protected $stream;
    protected $url;
    public function __construct($stream, $level = Logger::DEBUG, $bubble = true)
    {
        parent::__construct($level, $bubble);
        if (is_resource($stream)) {
            $this->stream = $stream;
        } else {
            $this->url = $stream;
        }
    }
    public function close()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }
    protected function write(array $record)
    {
        if (null === $this->stream) {
            if (!$this->url) {
                throw new \LogicException('Missing stream url, the stream can not be opened. This may be caused by a premature call to close().');
            }
            $errorMessage = null;
            set_error_handler(function ($code, $msg) use(&$errorMessage) {
                $errorMessage = preg_replace('{^fopen\\(.*?\\): }', '', $msg);
            });
            $this->stream = fopen($this->url, 'a');
            restore_error_handler();
            if (!is_resource($this->stream)) {
                $this->stream = null;
                throw new \UnexpectedValueException(sprintf('The stream or file "%s" could not be opened: ' . $errorMessage, $this->url));
            }
        }
        fwrite($this->stream, (string) $record['formatted']);
    }
}
namespace Monolog\Handler;

use Monolog\Logger;
class RotatingFileHandler extends StreamHandler
{
    protected $filename;
    protected $maxFiles;
    protected $mustRotate;
    public function __construct($filename, $maxFiles = 0, $level = Logger::DEBUG, $bubble = true)
    {
        $this->filename = $filename;
        $this->maxFiles = (int) $maxFiles;
        $fileInfo = pathinfo($this->filename);
        $timedFilename = $fileInfo['dirname'] . '/' . $fileInfo['filename'] . '-' . date('Y-m-d');
        if (!empty($fileInfo['extension'])) {
            $timedFilename .= '.' . $fileInfo['extension'];
        }
        if (0 === $this->maxFiles) {
            $this->mustRotate = false;
        }
        parent::__construct($timedFilename, $level, $bubble);
    }
    public function close()
    {
        parent::close();
        if (true === $this->mustRotate) {
            $this->rotate();
        }
    }
    protected function write(array $record)
    {
        if (null === $this->mustRotate) {
            $this->mustRotate = !file_exists($this->url);
        }
        parent::write($record);
    }
    protected function rotate()
    {
        $fileInfo = pathinfo($this->filename);
        $glob = $fileInfo['dirname'] . '/' . $fileInfo['filename'] . '-*';
        if (!empty($fileInfo['extension'])) {
            $glob .= '.' . $fileInfo['extension'];
        }
        $iterator = new \GlobIterator($glob);
        $count = $iterator->count();
        if ($this->maxFiles >= $count) {
            return;
        }
        $array = iterator_to_array($iterator);
        usort($array, function ($a, $b) {
            return strcmp($b->getFilename(), $a->getFilename());
        });
        foreach (array_slice($array, $this->maxFiles) as $file) {
            if ($file->isWritable()) {
                unlink($file->getRealPath());
            }
        }
    }
}
namespace Monolog\Handler;

use Monolog\Formatter\FormatterInterface;
interface HandlerInterface
{
    public function isHandling(array $record);
    public function handle(array $record);
    public function handleBatch(array $records);
    public function pushProcessor($callback);
    public function popProcessor();
    public function setFormatter(FormatterInterface $formatter);
    public function getFormatter();
}
namespace Royalcms\Component\Support\Facades;

class Royalcms extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'royalcms';
    }
}
namespace Royalcms\Component\Support\Facades;

class View extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'view';
    }
}
namespace Royalcms\Component\Support\Facades;

class DB extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'db';
    }
}
namespace Royalcms\Component\Support\Facades;

class Request extends Facade
{
    public static function get($key = null, $default = null)
    {
        return static::$royalcms['request']->input($key, $default);
    }
    protected static function getFacadeAccessor()
    {
        return 'request';
    }
}
namespace Royalcms\Component\Support\Facades;

use Royalcms\Component\Support\Str;
use Royalcms\Component\HttpKernel\JsonResponse;
use Royalcms\Component\HttpKernel\Response as RoyalcmsResponse;
use Royalcms\Component\Support\Contracts\ArrayableInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
class Response extends Facade
{
    protected static $macros = array();
    public static function make($content = '', $status = 200, array $headers = array())
    {
        return new RoyalcmsResponse($content, $status, $headers);
    }
    public static function view($view, $data = array(), $status = 200, array $headers = array())
    {
        $royalcms = Facade::getFacadeRoyalcms();
        return static::make($royalcms['view']->make($view, $data), $status, $headers);
    }
    public static function json($data = array(), $status = 200, array $headers = array(), $options = 0)
    {
        if ($data instanceof ArrayableInterface) {
            $data = $data->toArray();
        }
        return new JsonResponse($data, $status, $headers, $options);
    }
    public static function stream($callback, $status = 200, array $headers = array())
    {
        return new StreamedResponse($callback, $status, $headers);
    }
    public static function download($file, $name = null, array $headers = array(), $disposition = 'attachment')
    {
        $response = new BinaryFileResponse($file, 200, $headers, true, $disposition);
        if (!is_null($name)) {
            return $response->setContentDisposition($disposition, $name, Str::ascii($name));
        }
        return $response;
    }
    public static function macro($name, $callback)
    {
        static::$macros[$name] = $callback;
    }
    public static function __callStatic($method, $parameters)
    {
        if (isset(static::$macros[$method])) {
            return call_user_func_array(static::$macros[$method], $parameters);
        } else {
            return parent::__callStatic($method, $parameters);
        }
        throw new \BadMethodCallException("Call to undefined method {$method}");
    }
    protected static function getFacadeAccessor()
    {
        return 'response';
    }
}
namespace Royalcms\Component\Support\Facades;

class Environment extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'phpinfo';
    }
    public static function gd_version()
    {
        static $version = -1;
        if ($version >= 0) {
            return $version;
        }
        if (!extension_loaded('gd')) {
            $version = 0;
        } else {
            if (PHP_VERSION >= '4.3') {
                if (function_exists('gd_info')) {
                    $ver_info = gd_info();
                    preg_match('/\\d/', $ver_info['GD Version'], $match);
                    $version = $match[0];
                } else {
                    if (function_exists('imagecreatetruecolor')) {
                        $version = 2;
                    } elseif (function_exists('imagecreate')) {
                        $version = 1;
                    }
                }
            } else {
                if (preg_match('/phpinfo/', ini_get('disable_functions'))) {
                    $version = 1;
                } else {
                    ob_start();
                    phpinfo(8);
                    $info = ob_get_contents();
                    ob_end_clean();
                    $info = stristr($info, 'gd version');
                    preg_match('/\\d/', $info, $match);
                    $version = $match[0];
                }
            }
        }
        return $version;
    }
    public static function get_os()
    {
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return 'Unknown';
        }
        $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
        $os = '';
        if (strpos($agent, 'win') !== false) {
            if (strpos($agent, 'nt 5.1') !== false) {
                $os = 'Windows XP';
            } elseif (strpos($agent, 'nt 5.2') !== false) {
                $os = 'Windows 2003';
            } elseif (strpos($agent, 'nt 5.0') !== false) {
                $os = 'Windows 2000';
            } elseif (strpos($agent, 'nt 6.0') !== false) {
                $os = 'Windows Vista';
            } elseif (strpos($agent, 'nt') !== false) {
                $os = 'Windows NT';
            } elseif (strpos($agent, 'win 9x') !== false && strpos($agent, '4.90') !== false) {
                $os = 'Windows ME';
            } elseif (strpos($agent, '98') !== false) {
                $os = 'Windows 98';
            } elseif (strpos($agent, '95') !== false) {
                $os = 'Windows 95';
            } elseif (strpos($agent, '32') !== false) {
                $os = 'Windows 32';
            } elseif (strpos($agent, 'ce') !== false) {
                $os = 'Windows CE';
            }
        } elseif (strpos($agent, 'linux') !== false) {
            $os = 'Linux';
        } elseif (strpos($agent, 'unix') !== false) {
            $os = 'Unix';
        } elseif (strpos($agent, 'sun') !== false && strpos($agent, 'os') !== false) {
            $os = 'SunOS';
        } elseif (strpos($agent, 'ibm') !== false && strpos($agent, 'os') !== false) {
            $os = 'IBM OS/2';
        } elseif (strpos($agent, 'mac') !== false && strpos($agent, 'pc') !== false) {
            $os = 'Macintosh';
        } elseif (strpos($agent, 'powerpc') !== false) {
            $os = 'PowerPC';
        } elseif (strpos($agent, 'aix') !== false) {
            $os = 'AIX';
        } elseif (strpos($agent, 'hpux') !== false) {
            $os = 'HPUX';
        } elseif (strpos($agent, 'netbsd') !== false) {
            $os = 'NetBSD';
        } elseif (strpos($agent, 'bsd') !== false) {
            $os = 'BSD';
        } elseif (strpos($agent, 'osf1') !== false) {
            $os = 'OSF1';
        } elseif (strpos($agent, 'irix') !== false) {
            $os = 'IRIX';
        } elseif (strpos($agent, 'freebsd') !== false) {
            $os = 'FreeBSD';
        } elseif (strpos($agent, 'teleport') !== false) {
            $os = 'teleport';
        } elseif (strpos($agent, 'flashget') !== false) {
            $os = 'flashget';
        } elseif (strpos($agent, 'webzip') !== false) {
            $os = 'webzip';
        } elseif (strpos($agent, 'offline') !== false) {
            $os = 'offline';
        } else {
            $os = 'Unknown';
        }
        return $os;
    }
    public static function gzip_enabled()
    {
        static $enabled_gzip = null;
        if ($enabled_gzip === null) {
            $enabled_gzip = function_exists('ob_gzhandler');
        }
        return Hook::apply_filters('gzip_enabled', $enabled_gzip);
    }
}
namespace Royalcms\Component\Support\Facades;

class Config extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'config';
    }
}
namespace Royalcms\Component\Support\Facades;

class Package extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'package';
    }
}
namespace Royalcms\Component\Support\Facades;

class Session extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'session';
    }
    public static function start()
    {
        return royalcms('session.start')->start(royalcms('request'));
    }
    public static function close()
    {
        return royalcms('session.start')->close();
    }
}
namespace Royalcms\Component\Support\Facades;

class Hook extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'hook';
    }
}
namespace Royalcms\Component\Support\Facades;

use Royalcms\Component\Foundation\Loader;
class Lang extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'translator';
    }
    private static $lang = array();
    private static $packname = array();
    public static function init()
    {
        self::$lang = array();
        self::$packname = array();
    }
    public static function load($file)
    {
        $lang_files = array();
        if (is_array($file)) {
            $lang_files = $file;
        } elseif (is_string($file)) {
            $lang_files = array($file);
        } else {
            return false;
        }
        foreach ($lang_files as $lang_file) {
            $file_arr = array();
            $lang_file_name = $lang_file;
            if (strpos($lang_file, '/')) {
                $file_arr = explode('/', $lang_file);
            }
            if (!empty($file_arr[0])) {
                $m = $file_arr[0];
            }
            if (!empty($file_arr[1])) {
                $lang_file_name = $file_arr[1];
            }
            $m = empty($m) ? ROUTE_M : $m;
            if (empty($m)) {
                continue;
            }
            if ($m != Config::get('system.admin_entrance') && $m != 'system') {
                self::$lang = array_merge(self::$lang, Loader::load_app_lang($lang_file_name, $m));
            } else {
                self::$lang = array_merge(self::$lang, Loader::load_sys_lang($lang_file_name));
            }
            self::$packname[] = $lang_file;
        }
        return self::$lang;
    }
    public static function load_plugin($file)
    {
        $lang_files = array();
        if (is_array($file)) {
            $lang_files = $file;
        } elseif (is_string($file)) {
            $lang_files = array($file);
        } else {
            return false;
        }
        foreach ($lang_files as $lang_file) {
            $file_arr = array();
            $lang_file_name = '';
            if (strpos($lang_file, '/')) {
                $file_arr = explode('/', $lang_file);
            } else {
                $plugin_dir = $lang_file;
            }
            if (!empty($file_arr[0])) {
                $plugin_dir = $file_arr[0];
            }
            if (!empty($file_arr[1])) {
                $lang_file_name = $file_arr[1];
            }
            $lang_file_name = empty($lang_file_name) ? 'plugin' : $lang_file_name;
            if (empty($plugin_dir)) {
                continue;
            }
            self::$lang = array_merge(self::$lang, Loader::load_plugin_lang($lang_file_name, $plugin_dir));
            self::$packname[] = $lang_file;
        }
        return self::$lang;
    }
    public static function load_widget($file)
    {
        $lang_files = array();
        if (is_array($file)) {
            $lang_files = $file;
        } elseif (is_string($file)) {
            $lang_files = array($file);
        } else {
            return false;
        }
        foreach ($lang_files as $lang_file) {
            $file_arr = array();
            $lang_file_name = '';
            if (strpos($lang_file, '/')) {
                $file_arr = explode('/', $lang_file);
            } else {
                $plugin_dir = $lang_file;
            }
            if (!empty($file_arr[0])) {
                $plugin_dir = $file_arr[0];
            }
            if (!empty($file_arr[1])) {
                $lang_file_name = $file_arr[1];
            }
            $lang_file_name = empty($lang_file_name) ? 'widget' : $lang_file_name;
            if (empty($plugin_dir)) {
                continue;
            }
            self::$lang = array_merge(self::$lang, Loader::load_widget_lang($lang_file_name, $plugin_dir));
            self::$packname[] = $lang_file;
        }
        return self::$lang;
    }
    public static function load_theme($file)
    {
        $lang_files = array();
        if (is_array($file)) {
            $lang_files = $file;
        } elseif (is_string($file)) {
            $lang_files = array($file);
        } else {
            return false;
        }
        foreach ($lang_files as $lang_file) {
            $lang_file_name = $lang_file;
            $lang_file_name = empty($lang_file_name) ? 'theme' : $lang_file_name;
            self::$lang = array_merge(self::$lang, Loader::load_theme_lang($lang_file_name));
            self::$packname[] = $lang_file;
        }
        return self::$lang;
    }
    public static function lang($name = null)
    {
        if (is_null($name)) {
            return self::$lang;
        }
        $arr = explode('/', $name);
        $value = '';
        if (count($arr) == 1 && isset(self::$lang[$arr[0]])) {
            $value = self::$lang[$arr[0]];
        } elseif (count($arr) == 2 && is_array(self::$lang[$arr[0]]) && isset(self::$lang[$arr[0]][$arr[1]])) {
            $value = self::$lang[$arr[0]][$arr[1]];
        } elseif (count($arr) == 3 && is_array(self::$lang[$arr[0]][$arr[1]]) && isset(self::$lang[$arr[0]][$arr[1]][$arr[2]])) {
            $value = self::$lang[$arr[0]][$arr[1]][$arr[2]];
        } elseif (count($arr) == 4 && is_array(self::$lang[$arr[0]][$arr[1]][$arr[2]]) && isset(self::$lang[$arr[0]][$arr[1]][$arr[2]][$arr[3]])) {
            $value = self::$lang[$arr[0]][$arr[1]][$arr[2]][$arr[3]];
        } elseif (count($arr) == 5 && is_array(self::$lang[$arr[0]][$arr[1]][$arr[2]][$arr[3]]) && isset(self::$lang[$arr[0]][$arr[1]][$arr[2]][$arr[3]])) {
            $value = self::$lang[$arr[0]][$arr[1]][$arr[2]][$arr[3]];
        }
        return $value;
    }
    public static function has($name)
    {
        $arr = explode('/', $name);
        $bool = false;
        if (count($arr) == 1 && isset(self::$lang[$arr[0]])) {
            $bool = true;
        } elseif (count($arr) == 2 && is_array(self::$lang[$arr[0]]) && isset(self::$lang[$arr[0]][$arr[1]])) {
            $bool = true;
        } elseif (count($arr) == 3 && is_array(self::$lang[$arr[0]][$arr[1]]) && isset(self::$lang[$arr[0]][$arr[1]][$arr[2]])) {
            $bool = true;
        } elseif (count($arr) == 4 && is_array(self::$lang[$arr[0]][$arr[1]][$arr[2]]) && isset(self::$lang[$arr[0]][$arr[1]][$arr[2]][$arr[3]])) {
            $bool = true;
        } elseif (count($arr) == 5 && is_array(self::$lang[$arr[0]][$arr[1]][$arr[2]][$arr[3]]) && isset(self::$lang[$arr[0]][$arr[1]][$arr[2]][$arr[3]])) {
            $bool = true;
        }
        return $bool;
    }
}
namespace Royalcms\Component\Support\Facades;

class Variable extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'variable';
    }
}
namespace Royalcms\Component\Support\Facades;

class Cache extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'cache';
    }
}
namespace Royalcms\Component\Support\Facades;

class Event extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'events';
    }
}
namespace Royalcms\Component\Cache;

use Royalcms\Component\Support\ServiceProvider;
class CacheServiceProvider extends ServiceProvider
{
    protected $defer = true;
    public function register()
    {
        $this->royalcms->bindShared('cache', function ($royalcms) {
            return new CacheManager($royalcms);
        });
        $this->royalcms->bindShared('cache.store', function ($royalcms) {
            return $royalcms['cache']->driver();
        });
        $this->royalcms->bindShared('memcached.connector', function () {
            return new MemcachedConnector();
        });
        $this->registerCommands();
    }
    public function registerCommands()
    {
        $this->royalcms->bindShared('command.cache.clear', function ($royalcms) {
            return new Console\ClearCommand($royalcms['cache'], $royalcms['files']);
        });
        $this->commands('command.cache.clear');
    }
    public function provides()
    {
        return array('cache', 'cache.store', 'memcached.connector', 'command.cache.clear');
    }
}
namespace Royalcms\Component\Cache;

use Royalcms\Component\Support\Manager;
class CacheManager extends Manager
{
    public function drive($name = null)
    {
        $name = $name ?: $this->getDefaultDriver();
        return $this->drivers[$name] = $this->get($name);
    }
    protected function get($name)
    {
        return isset($this->drivers[$name]) ? $this->drivers[$name] : $this->resolve($name);
    }
    protected function resolve($name)
    {
        $config = $this->getConfig($name);
        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }
        return $this->{'create' . ucfirst($config['driver']) . 'Driver'}($config);
    }
    protected function createApcDriver(array $config)
    {
        return $this->repository(new ApcStore(new ApcWrapper(), $this->getPrefix()));
    }
    protected function createArrayDriver(array $config)
    {
        return $this->repository(new ArrayStore());
    }
    protected function createFileDriver(array $config)
    {
        $path = $config['path'];
        return $this->repository(new FileStore($this->royalcms['files'], $path));
    }
    protected function createMemcachedDriver(array $config)
    {
        $servers = $this->royalcms['config']['cache.memcached'];
        $memcached = $this->royalcms['memcached.connector']->connect($servers);
        return $this->repository(new MemcachedStore($memcached, $this->getPrefix()));
    }
    protected function createWincacheDriver(array $config)
    {
        return $this->repository(new WinCacheStore($this->getPrefix()));
    }
    protected function createXcacheDriver(array $config)
    {
        return $this->repository(new XCacheStore($this->getPrefix()));
    }
    protected function createRedisDriver(array $config)
    {
        $redis = $this->royalcms['redis'];
        return $this->repository(new RedisStore($redis, $this->getPrefix()));
    }
    protected function createDatabaseDriver(array $config)
    {
        $connection = $this->getDatabaseConnection();
        $encrypter = $this->royalcms['encrypter'];
        $table = $this->royalcms['config']['cache.table'];
        $prefix = $this->getPrefix();
        return $this->repository(new DatabaseStore($connection, $encrypter, $table, $prefix));
    }
    protected function getDatabaseConnection()
    {
        $connection = $this->royalcms['config']['cache.connection'];
        return $this->royalcms['db']->connection($connection);
    }
    public function getPrefix()
    {
        return $this->royalcms['config']['cache.prefix'];
    }
    public function setPrefix($name)
    {
        $this->royalcms['config']['cache.prefix'] = $name;
    }
    protected function repository(StoreInterface $store)
    {
        return new Repository($store);
    }
    protected function getConfig($name)
    {
        return $this->royalcms['config']["cache.drivers.{$name}"];
    }
    public function getDefaultDriver()
    {
        return $this->royalcms['config']['cache.default'];
    }
    public function setDefaultDriver($name)
    {
        $this->royalcms['config']['cache.default'] = $name;
    }
    public function __call($method, $parameters)
    {
        return call_user_func_array(array($this->drive(), $method), $parameters);
    }
}
namespace Royalcms\Component\Cache;

use Royalcms\Component\Filesystem\Filesystem;
class FileStore implements StoreInterface
{
    protected $files;
    protected $directory;
    public function __construct(Filesystem $files, $directory)
    {
        $this->files = $files;
        $this->directory = $directory;
    }
    public function get($key)
    {
        $path = $this->path($key);
        if (!$this->files->exists($path)) {
            return null;
        }
        try {
            $expire = substr($contents = $this->files->get($path), 0, 10);
        } catch (\Exception $e) {
            return null;
        }
        if (time() >= $expire) {
            return $this->forget($key);
        }
        return unserialize(substr($contents, 10));
    }
    public function put($key, $value, $minutes)
    {
        $value = $this->expiration($minutes) . serialize($value);
        $this->createCacheDirectory($path = $this->path($key));
        $this->files->put($path, $value);
    }
    protected function createCacheDirectory($path)
    {
        try {
            $this->files->makeDirectory(dirname($path), 511, true, true);
        } catch (\Exception $e) {
            
        }
    }
    public function increment($key, $value = 1)
    {
        throw new \LogicException('Increment operations not supported by this driver.');
    }
    public function decrement($key, $value = 1)
    {
        throw new \LogicException('Decrement operations not supported by this driver.');
    }
    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }
    public function forget($key)
    {
        $file = $this->path($key);
        if ($this->files->exists($file)) {
            $this->files->delete($file);
        }
    }
    public function flush()
    {
        foreach ($this->files->directories($this->directory) as $directory) {
            $this->files->deleteDirectory($directory);
        }
    }
    protected function path($key)
    {
        $parts = array_slice(str_split($hash = md5($key), 2), 0, 2);
        return $this->directory . '/' . join('/', $parts) . '/' . $hash;
    }
    protected function expiration($minutes)
    {
        if ($minutes === 0) {
            return 9999999999;
        }
        return time() + $minutes * 60;
    }
    public function getFilesystem()
    {
        return $this->files;
    }
    public function getDirectory()
    {
        return $this->directory;
    }
    public function getPrefix()
    {
        return '';
    }
}
namespace Royalcms\Component\Cache;

interface StoreInterface
{
    public function get($key);
    public function put($key, $value, $minutes);
    public function increment($key, $value = 1);
    public function decrement($key, $value = 1);
    public function forever($key, $value);
    public function forget($key);
    public function flush();
    public function getPrefix();
}
namespace Royalcms\Component\Cache;

use Closure;
use DateTime;
use ArrayAccess;
use Royalcms\Component\DateTime\Carbon;
class Repository implements ArrayAccess
{
    protected $store;
    protected $default = 60;
    protected $macros = array();
    public function __construct(StoreInterface $store)
    {
        $this->store = $store;
    }
    public function has($key)
    {
        return !is_null($this->get($key));
    }
    public function get($key, $default = null)
    {
        $value = $this->store->get($key);
        return !is_null($value) ? $value : value($default);
    }
    public function put($key, $value, $minutes)
    {
        $minutes = $this->getMinutes($minutes);
        $this->store->put($key, $value, $minutes);
    }
    public function add($key, $value, $minutes)
    {
        if (is_null($this->get($key))) {
            $this->put($key, $value, $minutes);
            return true;
        }
        return false;
    }
    public function remember($key, $minutes, Closure $callback)
    {
        if (!is_null($value = $this->get($key))) {
            return $value;
        }
        $this->put($key, $value = $callback(), $minutes);
        return $value;
    }
    public function sear($key, Closure $callback)
    {
        return $this->rememberForever($key, $callback);
    }
    public function rememberForever($key, Closure $callback)
    {
        if (!is_null($value = $this->get($key))) {
            return $value;
        }
        $this->forever($key, $value = $callback());
        return $value;
    }
    public function getDefaultCacheTime()
    {
        return $this->default;
    }
    public function setDefaultCacheTime($minutes)
    {
        $this->default = $minutes;
    }
    public function getStore()
    {
        return $this->store;
    }
    public function offsetExists($key)
    {
        return $this->has($key);
    }
    public function offsetGet($key)
    {
        return $this->get($key);
    }
    public function offsetSet($key, $value)
    {
        $this->put($key, $value, $this->default);
    }
    public function offsetUnset($key)
    {
        return $this->forget($key);
    }
    protected function getMinutes($duration)
    {
        if ($duration instanceof DateTime) {
            $duration = Carbon::instance($duration);
            return max(0, Carbon::now()->diffInMinutes($duration, false));
        } else {
            return is_string($duration) ? intval($duration) : $duration;
        }
    }
    public function macro($name, $callback)
    {
        $this->macros[$name] = $callback;
    }
    public function __call($method, $parameters)
    {
        if (isset($this->macros[$method])) {
            return call_user_func_array($this->macros[$method], $parameters);
        } else {
            return call_user_func_array(array($this->store, $method), $parameters);
        }
    }
}
namespace Royalcms\Component\Cache;

class Memory
{
    private $cache = array();
    private $cache_hits = 0;
    public $cache_misses = 0;
    protected $global_groups = array();
    private $site_prefix;
    public function __get($name)
    {
        return $this->{$name};
    }
    public function __set($name, $value)
    {
        return $this->{$name} = $value;
    }
    public function __isset($name)
    {
        return isset($this->{$name});
    }
    public function __unset($name)
    {
        unset($this->{$name});
    }
    public function add($key, $data, $group = 'default', $expire = 0)
    {
        if (rc_suspend_cache_addition()) {
            return false;
        }
        if (empty($group)) {
            $group = 'default';
        }
        $id = $key;
        if ($this->multisite && !isset($this->global_groups[$group])) {
            $id = $this->site_prefix . $key;
        }
        if ($this->_exists($id, $group)) {
            return false;
        }
        return $this->set($key, $data, $group, (int) $expire);
    }
    public function add_global_groups($groups)
    {
        $groups = (array) $groups;
        $groups = array_fill_keys($groups, true);
        $this->global_groups = array_merge($this->global_groups, $groups);
    }
    public function decr($key, $offset = 1, $group = 'default')
    {
        if (empty($group)) {
            $group = 'default';
        }
        if ($this->multisite && !isset($this->global_groups[$group])) {
            $key = $this->site_prefix . $key;
        }
        if (!$this->_exists($key, $group)) {
            return false;
        }
        if (!is_numeric($this->cache[$group][$key])) {
            $this->cache[$group][$key] = 0;
        }
        $offset = (int) $offset;
        $this->cache[$group][$key] -= $offset;
        if ($this->cache[$group][$key] < 0) {
            $this->cache[$group][$key] = 0;
        }
        return $this->cache[$group][$key];
    }
    public function delete($key, $group = 'default', $deprecated = false)
    {
        if (empty($group)) {
            $group = 'default';
        }
        if ($this->multisite && !isset($this->global_groups[$group])) {
            $key = $this->site_prefix . $key;
        }
        if (!$this->_exists($key, $group)) {
            return false;
        }
        unset($this->cache[$group][$key]);
        return true;
    }
    public function flush()
    {
        $this->cache = array();
        return true;
    }
    public function get($key, $group = 'default', $force = false, &$found = null)
    {
        if (empty($group)) {
            $group = 'default';
        }
        if ($this->multisite && !isset($this->global_groups[$group])) {
            $key = $this->site_prefix . $key;
        }
        if ($this->_exists($key, $group)) {
            $found = true;
            $this->cache_hits += 1;
            if (is_object($this->cache[$group][$key])) {
                return clone $this->cache[$group][$key];
            } else {
                return $this->cache[$group][$key];
            }
        }
        $found = false;
        $this->cache_misses += 1;
        return false;
    }
    public function incr($key, $offset = 1, $group = 'default')
    {
        if (empty($group)) {
            $group = 'default';
        }
        if ($this->multisite && !isset($this->global_groups[$group])) {
            $key = $this->site_prefix . $key;
        }
        if (!$this->_exists($key, $group)) {
            return false;
        }
        if (!is_numeric($this->cache[$group][$key])) {
            $this->cache[$group][$key] = 0;
        }
        $offset = (int) $offset;
        $this->cache[$group][$key] += $offset;
        if ($this->cache[$group][$key] < 0) {
            $this->cache[$group][$key] = 0;
        }
        return $this->cache[$group][$key];
    }
    public function replace($key, $data, $group = 'default', $expire = 0)
    {
        if (empty($group)) {
            $group = 'default';
        }
        $id = $key;
        if ($this->multisite && !isset($this->global_groups[$group])) {
            $id = $this->site_prefix . $key;
        }
        if (!$this->_exists($id, $group)) {
            return false;
        }
        return $this->set($key, $data, $group, (int) $expire);
    }
    public function reset()
    {
        foreach (array_keys($this->cache) as $group) {
            if (!isset($this->global_groups[$group])) {
                unset($this->cache[$group]);
            }
        }
    }
    public function set($key, $data, $group = 'default', $expire = 0)
    {
        if (empty($group)) {
            $group = 'default';
        }
        if ($this->multisite && !isset($this->global_groups[$group])) {
            $key = $this->site_prefix . $key;
        }
        if (is_object($data)) {
            $data = clone $data;
        }
        $this->cache[$group][$key] = $data;
        return true;
    }
    public function stats()
    {
        echo '<p>';
        echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
        echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
        echo '</p>';
        echo '<ul>';
        foreach ($this->cache as $group => $cache) {
            echo "<li><strong>Group:</strong> {$group} - ( " . number_format(strlen(serialize($cache)) / 1024, 2) . 'k )</li>';
        }
        echo '</ul>';
    }
    public function switch_to_site($site_name)
    {
        $site_name = (string) $site_name;
        $this->site_prefix = $this->multisite ? $site_name . ':' : '';
    }
    protected function _exists($key, $group)
    {
        return isset($this->cache[$group]) && (isset($this->cache[$group][$key]) || array_key_exists($key, $this->cache[$group]));
    }
    public function __construct()
    {
        $this->multisite = defined('RC_SITE');
        $this->site_prefix = $this->multisite ? RC_SITE . ':' : '';
        register_shutdown_function(array($this, '__destruct'));
    }
    public function __destruct()
    {
        return true;
    }
}
namespace Royalcms\Component\Exception;

use Royalcms\Component\Whoops\Run;
use Royalcms\Component\Whoops\Handler\JsonResponseHandler;
use Royalcms\Component\Support\ServiceProvider;
class ExceptionServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->registerDisplayers();
        $this->registerHandler();
    }
    protected function registerDisplayers()
    {
        $this->registerPlainDisplayer();
        $this->registerDebugDisplayer();
    }
    protected function registerHandler()
    {
        $this->royalcms['exception'] = $this->royalcms->share(function ($royalcms) {
            return new Handler($royalcms, $royalcms['exception.plain'], $royalcms['exception.debug']);
        });
    }
    protected function registerPlainDisplayer()
    {
        $this->royalcms['exception.plain'] = $this->royalcms->share(function ($royalcms) {
            if ($royalcms->runningInConsole()) {
                return $royalcms['exception.debug'];
            } else {
                return new PlainDisplayer();
            }
        });
    }
    protected function registerDebugDisplayer()
    {
        $this->registerWhoops();
        $this->royalcms['exception.debug'] = $this->royalcms->share(function ($royalcms) {
            return new WhoopsDisplayer($royalcms['whoops'], $royalcms->runningInConsole());
        });
    }
    protected function registerWhoops()
    {
        $this->registerWhoopsHandler();
        $this->royalcms['whoops'] = $this->royalcms->share(function ($royalcms) {
            with($whoops = new Run())->allowQuit(false);
            $whoops->writeToOutput(false);
            return $whoops->pushHandler($royalcms['whoops.handler']);
        });
    }
    protected function registerWhoopsHandler()
    {
        if ($this->shouldReturnJson()) {
            $this->royalcms['whoops.handler'] = $this->royalcms->share(function () {
                return new JsonResponseHandler();
            });
        } else {
            $this->registerPrettyWhoopsHandler();
        }
    }
    protected function shouldReturnJson()
    {
        return $this->royalcms->runningInConsole() || $this->requestWantsJson();
    }
    protected function requestWantsJson()
    {
        return $this->royalcms['request']->ajax() || $this->royalcms['request']->wantsJson();
    }
    protected function registerPrettyWhoopsHandler()
    {
        $me = $this;
        $this->royalcms['whoops.handler'] = $this->royalcms->share(function () use($me) {
            with($handler = new PrettyPageHandler())->setEditor('sublime');
            if (!is_null($path = $me->resourcePath())) {
                $handler->setResourcesPath($path);
            }
            return $handler;
        });
    }
    public function resourcePath()
    {
        if (is_dir($path = $this->getResourcePath())) {
            return $path;
        }
    }
    protected function getResourcePath()
    {
        return SITE_ROOT . 'vendor/royalcms/framework/Royalcms/Component/Exception' . '/resources';
    }
}
namespace Royalcms\Component\Exception;

use Royalcms\Component\Whoops\Handler\Handler;
use InvalidArgumentException;
class PrettyPageHandler extends Handler
{
    private $resourcesPath;
    private $extraTables = array();
    private $pageTitle = 'Whoops! There was an error.';
    protected $editor;
    protected $editors = array('sublime' => 'subl://open?url=file://%file&line=%line', 'textmate' => 'txmt://open?url=file://%file&line=%line', 'emacs' => 'emacs://open?url=file://%file&line=%line', 'macvim' => 'mvim://open/?url=file://%file&line=%line');
    public function __construct()
    {
        if (ini_get('xdebug.file_link_format') || extension_loaded('xdebug')) {
            $this->editors['xdebug'] = function ($file, $line) {
                return str_replace(array('%f', '%l'), array($file, $line), ini_get('xdebug.file_link_format'));
            };
        }
    }
    public function handle()
    {
        if (php_sapi_name() === 'cli' && !isset($_ENV['whoops-test'])) {
            return Handler::DONE;
        }
        if (!($resources = $this->getResourcesPath())) {
            $resources = SITE_ROOT . 'vendor/royalcms/framework/Royalcms/Component/Exception' . '/../Resources';
        }
        $templateFile = "{$resources}/pretty-template.php";
        $cssFile = "{$resources}/pretty-page.css";
        $inspector = $this->getInspector();
        $frames = $inspector->getFrames();
        $v = (object) array('title' => $this->getPageTitle(), 'name' => explode('\\', $inspector->getExceptionName()), 'message' => $inspector->getException()->getMessage(), 'frames' => $frames, 'hasFrames' => !!count($frames), 'handler' => $this, 'handlers' => $this->getRun()->getHandlers(), 'pageStyle' => file_get_contents($cssFile), 'tables' => array('Server/Request Data' => $_SERVER, 'GET Data' => $_GET, 'POST Data' => $_POST, 'Files' => $_FILES, 'Cookies' => $_COOKIE, 'Session' => isset($_SESSION) ? $_SESSION : array(), 'Environment Variables' => $_ENV));
        $extraTables = array_map(function ($table) {
            return $table instanceof \Closure ? $table() : $table;
        }, $this->getDataTables());
        $v->tables = array_merge($extraTables, $v->tables);
        call_user_func(function () use($templateFile, $v) {
            $e = function ($_, $allowLinks = false) {
                $escaped = htmlspecialchars($_, ENT_QUOTES, 'UTF-8');
                if ($allowLinks) {
                    $escaped = preg_replace('@([A-z]+?://([-\\w\\.]+[-\\w])+(:\\d+)?(/([\\w/_\\.#-]*(\\?\\S+)?[^\\.\\s])?)?)@', '<a href="$1" target="_blank">$1</a>', $escaped);
                }
                return $escaped;
            };
            $slug = function ($_) {
                $_ = str_replace(' ', '-', $_);
                $_ = preg_replace('/[^\\w\\d\\-\\_]/i', '', $_);
                return strtolower($_);
            };
            require $templateFile;
        });
        return Handler::QUIT;
    }
    public function addDataTable($label, array $data)
    {
        $this->extraTables[$label] = $data;
    }
    public function addDataTableCallback($label, $callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Expecting callback argument to be callable');
        }
        $this->extraTables[$label] = function () use($callback) {
            try {
                $result = call_user_func($callback);
                return is_array($result) || $result instanceof \Traversable ? $result : array();
            } catch (\Exception $e) {
                return array();
            }
        };
    }
    public function getDataTables($label = null)
    {
        if ($label !== null) {
            return isset($this->extraTables[$label]) ? $this->extraTables[$label] : array();
        }
        return $this->extraTables;
    }
    public function addEditor($identifier, $resolver)
    {
        $this->editors[$identifier] = $resolver;
    }
    public function setEditor($editor)
    {
        if (!is_callable($editor) && !isset($this->editors[$editor])) {
            throw new InvalidArgumentException("Unknown editor identifier: {$editor}. Known editors:" . implode(',', array_keys($this->editors)));
        }
        $this->editor = $editor;
    }
    public function getEditorHref($filePath, $line)
    {
        if ($this->editor === null) {
            return false;
        }
        $editor = $this->editor;
        if (is_string($editor)) {
            $editor = $this->editors[$editor];
        }
        if (is_callable($editor)) {
            $editor = call_user_func($editor, $filePath, $line);
        }
        if (!is_string($editor)) {
            throw new InvalidArgumentException(__METHOD__ . ' should always resolve to a string; got something else instead');
        }
        $editor = str_replace('%line', rawurlencode($line), $editor);
        $editor = str_replace('%file', rawurlencode($filePath), $editor);
        return $editor;
    }
    public function setPageTitle($title)
    {
        $this->pageTitle = (string) $title;
    }
    public function getPageTitle()
    {
        return $this->pageTitle;
    }
    public function getResourcesPath()
    {
        return $this->resourcesPath;
    }
    public function setResourcesPath($resourcesPath)
    {
        if (!is_dir($resourcesPath)) {
            throw new InvalidArgumentException("{$resourcesPath} is not a valid directory");
        }
        $this->resourcesPath = $resourcesPath;
    }
}
namespace Royalcms\Component\Exception;

use Exception;
interface ExceptionDisplayerInterface
{
    public function display($exception);
}
namespace Royalcms\Component\Exception;

use Exception;
use Symfony\Component\Debug\ExceptionHandler;
class SymfonyDisplayer implements ExceptionDisplayerInterface
{
    protected $symfony;
    public function __construct(ExceptionHandler $symfony)
    {
        $this->symfony = $symfony;
    }
    public function display($exception)
    {
        $this->symfony->handle($exception);
    }
}
namespace Royalcms\Component\Exception;

use Exception;
use Royalcms\Component\Whoops\Run;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class WhoopsDisplayer implements ExceptionDisplayerInterface
{
    protected $whoops;
    protected $runningInConsole;
    public function __construct(Run $whoops, $runningInConsole)
    {
        $this->whoops = $whoops;
        $this->runningInConsole = $runningInConsole;
    }
    public function display($exception)
    {
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : array();
        return new Response($this->whoops->handleException($exception), $status, $headers);
    }
}
namespace Royalcms\Component\Exception;

use Exception;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class PlainDisplayer implements ExceptionDisplayerInterface
{
    public function display($exception)
    {
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : array();
        include SITE_ROOT . 'vendor/royalcms/framework/Royalcms/Component/Exception' . '/resources/plain.php';
        return new Response(null, $status, $headers);
    }
}
namespace Royalcms\Component\Exception;

use Closure;
use ErrorException;
use ReflectionFunction;
use Royalcms\Component\Support\Contracts\ResponsePreparerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Debug\Exception\FatalErrorException as FatalError;
use Royalcms\Component\Support\Facades\Hook;
class Handler
{
    protected $responsePreparer;
    protected $plainDisplayer;
    protected $debugDisplayer;
    protected $debug;
    protected $handlers = array();
    protected $handled = array();
    public function __construct(ResponsePreparerInterface $responsePreparer, ExceptionDisplayerInterface $plainDisplayer, ExceptionDisplayerInterface $debugDisplayer, $debug = true)
    {
        $this->debug = $debug;
        $this->plainDisplayer = $plainDisplayer;
        $this->debugDisplayer = $debugDisplayer;
        $this->responsePreparer = $responsePreparer;
    }
    public function register($environment)
    {
        $this->registerErrorHandler();
        $this->registerExceptionHandler();
        if ($environment != 'testing') {
            $this->registerShutdownHandler();
        }
    }
    protected function registerErrorHandler()
    {
        set_error_handler(array($this, 'handleError'));
    }
    protected function registerExceptionHandler()
    {
        set_exception_handler(array($this, 'handleUncaughtException'));
    }
    protected function registerShutdownHandler()
    {
        register_shutdown_function(array($this, 'handleShutdown'));
    }
    public function handleError($level, $message, $file = '', $line = 0, $context = array())
    {
        if (error_reporting() & $level) {
            $exception = null;
            switch ($level) {
                case E_ERROR:
                    throw new ErrorException($message, 0, $level, $file, $line);
                    break;
                case E_USER_ERROR:
                    throw new UserErrorException($message, 0, $level, $file, $line);
                    break;
                case E_PARSE:
                    throw new ParseException($message, 0, $level, $file, $line);
                    break;
                case E_CORE_ERROR:
                    throw new CoreErrorException($message, 0, $level, $file, $line);
                    break;
                case E_CORE_WARNING:
                    throw new CoreWarningException($message, 0, $level, $file, $line);
                    break;
                case E_COMPILE_ERROR:
                    throw new CompileErrorException($message, 0, $level, $file, $line);
                    break;
                case E_COMPILE_WARNING:
                    throw new CoreWarningException($message, 0, $level, $file, $line);
                    break;
                case E_STRICT:
                    throw new StrictException($message, 0, $level, $file, $line);
                    break;
                case E_RECOVERABLE_ERROR:
                    throw new RecoverableErrorException($message, 0, $level, $file, $line);
                    break;
                case E_WARNING:
                    $exception = new WarningException($message, 0, $level, $file, $line);
                    break;
                case E_USER_WARNING:
                    $exception = new UserWarningException($message, 0, $level, $file, $line);
                    break;
                case E_NOTICE:
                    $exception = new NoticeException($message, 0, $level, $file, $line);
                    break;
                case E_USER_NOTICE:
                    $exception = new UserNoticeException($message, 0, $level, $file, $line);
                    break;
                case E_DEPRECATED:
                    $exception = new DeprecatedException($message, 0, $level, $file, $line);
                    break;
                case E_USER_DEPRECATED:
                    $exception = new UserDeprecatedException($message, 0, $level, $file, $line);
                    break;
                default:
                    $exception = new UnknownException($message, 0, $level, $file, $line);
                    break;
            }
            if ($exception instanceof ErrorException) {
                royalcms('events')->fire('royalcms.warning.exception', array($exception));
            }
        }
    }
    public function handleException($exception)
    {
        $response = $this->callCustomHandlers($exception);
        if (!is_null($response)) {
            return $this->prepareResponse($response);
        }
        return $this->displayException($exception);
    }
    public function handleUncaughtException($exception)
    {
        $this->handleException($exception)->send();
    }
    public function handleShutdown()
    {
        $error = error_get_last();
        if (!is_null($error)) {
            $message = $type = $file = $line = null;
            extract($error);
            if (!$this->isFatal($type)) {
                return;
            }
            $this->handleException(new FatalError($message, $type, 0, $file, $line))->send();
        }
        Hook::do_action('shutdown');
    }
    protected function isFatal($type)
    {
        return in_array($type, array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE));
    }
    public function handleConsole($exception)
    {
        return $this->callCustomHandlers($exception, true);
    }
    protected function callCustomHandlers($exception, $fromConsole = false)
    {
        foreach ($this->handlers as $handler) {
            if (!$this->handlesException($handler, $exception)) {
                continue;
            } elseif ($exception instanceof HttpExceptionInterface) {
                $code = $exception->getStatusCode();
            } else {
                $code = 500;
            }
            try {
                $response = $handler($exception, $code, $fromConsole);
            } catch (\Exception $e) {
                $response = $this->formatException($e);
            }
            if (isset($response) && !is_null($response)) {
                return $response;
            }
        }
    }
    protected function displayException($exception)
    {
        $displayer = $this->debug ? $this->debugDisplayer : $this->plainDisplayer;
        return $displayer->display($exception);
    }
    protected function handlesException(Closure $handler, $exception)
    {
        $reflection = new ReflectionFunction($handler);
        return $reflection->getNumberOfParameters() == 0 || $this->hints($reflection, $exception);
    }
    protected function hints(ReflectionFunction $reflection, $exception)
    {
        $parameters = $reflection->getParameters();
        $expected = $parameters[0];
        return !$expected->getClass() || $expected->getClass()->isInstance($exception);
    }
    protected function formatException(\Exception $e)
    {
        if ($this->debug) {
            $location = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            return rc_die('Error in exception handler: ' . $location);
        }
        return rc_die('Error in exception handler.');
    }
    public function error(Closure $callback)
    {
        array_unshift($this->handlers, $callback);
    }
    public function pushError(Closure $callback)
    {
        $this->handlers[] = $callback;
    }
    protected function prepareResponse($response)
    {
        return $this->responsePreparer->prepareResponse($response);
    }
    public function runningInConsole()
    {
        return php_sapi_name() == 'cli';
    }
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }
}
class CompileErrorException extends ErrorException
{
    
}
class CompileWarningException extends ErrorException
{
    
}
class CoreErrorException extends ErrorException
{
    
}
class CoreWarningException extends ErrorException
{
    
}
class DeprecatedException extends ErrorException
{
    
}
class NoticeException extends ErrorException
{
    
}
class ParseException extends ErrorException
{
    
}
class RecoverableErrorException extends ErrorException
{
    
}
class StrictException extends ErrorException
{
    
}
class UserDeprecatedException extends ErrorException
{
    
}
class UserErrorException extends ErrorException
{
    
}
class UserNoticeException extends ErrorException
{
    
}
class UserWarningException extends ErrorException
{
    
}
class WarningException extends ErrorException
{
    
}
class UnknownException extends ErrorException
{
    
}
namespace Royalcms\Component\Support\Facades;

class Route extends Facade
{
    public static function is($name)
    {
        return static::$royalcms['router']->currentRouteNamed($name);
    }
    public static function uses($action)
    {
        return static::$royalcms['router']->currentRouteUses($action);
    }
    protected static function getFacadeAccessor()
    {
        return 'router';
    }
}
namespace Royalcms\Component\View\Engines;

use Closure;
class EngineResolver
{
    protected $resolvers = array();
    protected $resolved = array();
    public function register($engine, Closure $resolver)
    {
        $this->resolvers[$engine] = $resolver;
    }
    public function resolve($engine)
    {
        if (!isset($this->resolved[$engine])) {
            $this->resolved[$engine] = call_user_func($this->resolvers[$engine]);
        }
        return $this->resolved[$engine];
    }
}
namespace Royalcms\Component\View;

interface ViewFinderInterface
{
    public function find($view);
    public function addLocation($location);
    public function addNamespace($namespace, $hint);
    public function addExtension($extension);
}
namespace Royalcms\Component\View;

use Royalcms\Component\Filesystem\Filesystem;
class FileViewFinder implements ViewFinderInterface
{
    protected $files;
    protected $paths;
    protected $views = array();
    protected $hints = array();
    protected $extensions = array('php');
    public function __construct(Filesystem $files, array $paths, array $extensions = null)
    {
        $this->files = $files;
        $this->paths = $paths;
        if (isset($extensions)) {
            $this->extensions = $extensions;
        }
    }
    public function find($name)
    {
        if (isset($this->views[$name])) {
            return $this->views[$name];
        }
        if (strpos($name, '::') !== false) {
            return $this->views[$name] = $this->findNamedPathView($name);
        }
        return $this->views[$name] = $this->findInPaths($name, $this->paths);
    }
    protected function findNamedPathView($name)
    {
        list($namespace, $view) = $this->getNamespaceSegments($name);
        return $this->findInPaths($view, $this->hints[$namespace]);
    }
    protected function getNamespaceSegments($name)
    {
        $segments = explode('::', $name);
        if (count($segments) != 2) {
            throw new \InvalidArgumentException("View [{$name}] has an invalid name.");
        }
        if (!isset($this->hints[$segments[0]])) {
            throw new \InvalidArgumentException("No hint path defined for [{$segments[0]}].");
        }
        return $segments;
    }
    protected function findInPaths($name, $paths)
    {
        foreach ((array) $paths as $path) {
            foreach ($this->getPossibleViewFiles($name) as $file) {
                if ($this->files->exists($viewPath = $path . '/' . $file)) {
                    return $viewPath;
                }
            }
        }
        throw new \InvalidArgumentException("View [{$name}] not found.");
    }
    protected function getPossibleViewFiles($name)
    {
        return array_map(function ($extension) use($name) {
            return str_replace('.', '/', $name) . '.' . $extension;
        }, $this->extensions);
    }
    public function addLocation($location)
    {
        $this->paths[] = $location;
    }
    public function addNamespace($namespace, $hints)
    {
        $hints = (array) $hints;
        if (isset($this->hints[$namespace])) {
            $hints = array_merge($this->hints[$namespace], $hints);
        }
        $this->hints[$namespace] = $hints;
    }
    public function prependNamespace($namespace, $hints)
    {
        $hints = (array) $hints;
        if (isset($this->hints[$namespace])) {
            $hints = array_merge($hints, $this->hints[$namespace]);
        }
        $this->hints[$namespace] = $hints;
    }
    public function addExtension($extension)
    {
        if (($index = array_search($extension, $this->extensions)) !== false) {
            unset($this->extensions[$index]);
        }
        array_unshift($this->extensions, $extension);
    }
    public function getFilesystem()
    {
        return $this->files;
    }
    public function getPaths()
    {
        return $this->paths;
    }
    public function getHints()
    {
        return $this->hints;
    }
    public function getExtensions()
    {
        return $this->extensions;
    }
}
namespace Royalcms\Component\View;

use Closure;
use Royalcms\Component\Event\Dispatcher;
use Royalcms\Component\Container\Container;
use Royalcms\Component\View\Engines\EngineResolver;
use Royalcms\Component\Support\Contracts\ArrayableInterface as Arrayable;
class Environment
{
    protected $engines;
    protected $finder;
    protected $events;
    protected $container;
    protected $shared = array();
    protected $names = array();
    protected $extensions = array('blade.php' => 'blade', 'php' => 'php');
    protected $composers = array();
    protected $sections = array();
    protected $sectionStack = array();
    protected $renderCount = 0;
    public function __construct(EngineResolver $engines, ViewFinderInterface $finder, Dispatcher $events)
    {
        $this->finder = $finder;
        $this->events = $events;
        $this->engines = $engines;
        $this->share('__env', $this);
    }
    public function make($view, $data = array(), $mergeData = array())
    {
        $path = $this->finder->find($view);
        $data = array_merge($mergeData, $this->parseData($data));
        $view = new View($this, $this->getEngineFromPath($path), $view, $path, $data);
        $this->callCreator($view);
        return $view;
    }
    protected function parseData($data)
    {
        return $data instanceof Arrayable ? $data->toArray() : $data;
    }
    public function of($view, $data = array())
    {
        return $this->make($this->names[$view], $data);
    }
    public function name($view, $name)
    {
        $this->names[$name] = $view;
    }
    public function exists($view)
    {
        try {
            $this->finder->find($view);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
        return true;
    }
    public function renderEach($view, $data, $iterator, $empty = 'raw|')
    {
        $result = '';
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                $data = array('key' => $key, $iterator => $value);
                $result .= $this->make($view, $data)->render();
            }
        } else {
            if (starts_with($empty, 'raw|')) {
                $result = substr($empty, 4);
            } else {
                $result = $this->make($empty)->render();
            }
        }
        return $result;
    }
    protected function getEngineFromPath($path)
    {
        $engine = $this->extensions[$this->getExtension($path)];
        return $this->engines->resolve($engine);
    }
    protected function getExtension($path)
    {
        $extensions = array_keys($this->extensions);
        return array_first($extensions, function ($key, $value) use($path) {
            return ends_with($path, $value);
        });
    }
    public function share($key, $value = null)
    {
        if (!is_array($key)) {
            return $this->shared[$key] = $value;
        }
        foreach ($key as $innerKey => $innerValue) {
            $this->share($innerKey, $innerValue);
        }
    }
    public function creator($views, $callback)
    {
        $creators = array();
        foreach ((array) $views as $view) {
            $creators[] = $this->addViewEvent($view, $callback, 'creating: ');
        }
        return $creators;
    }
    public function composers(array $composers)
    {
        $registered = array();
        foreach ($composers as $callback => $views) {
            $registered += $this->composer($views, $callback);
        }
        return $registered;
    }
    public function composer($views, $callback, $priority = null)
    {
        $composers = array();
        foreach ((array) $views as $view) {
            $composers[] = $this->addViewEvent($view, $callback, 'composing: ', $priority);
        }
        return $composers;
    }
    protected function addViewEvent($view, $callback, $prefix = 'composing: ', $priority = null)
    {
        if ($callback instanceof Closure) {
            $this->addEventListener($prefix . $view, $callback, $priority);
            return $callback;
        } elseif (is_string($callback)) {
            return $this->addClassEvent($view, $callback, $prefix, $priority);
        }
    }
    protected function addClassEvent($view, $class, $prefix, $priority = null)
    {
        $name = $prefix . $view;
        $callback = $this->buildClassEventCallback($class, $prefix);
        $this->addEventListener($name, $callback, $priority);
        return $callback;
    }
    protected function addEventListener($name, $callback, $priority = null)
    {
        if (is_null($priority)) {
            $this->events->listen($name, $callback);
        } else {
            $this->events->listen($name, $callback, $priority);
        }
    }
    protected function buildClassEventCallback($class, $prefix)
    {
        $container = $this->container;
        list($class, $method) = $this->parseClassEvent($class, $prefix);
        return function () use($class, $method, $container) {
            $callable = array($container->make($class), $method);
            return call_user_func_array($callable, func_get_args());
        };
    }
    protected function parseClassEvent($class, $prefix)
    {
        if (str_contains($class, '@')) {
            return explode('@', $class);
        } else {
            $method = str_contains($prefix, 'composing') ? 'compose' : 'create';
            return array($class, $method);
        }
    }
    public function callComposer(View $view)
    {
        $this->events->fire('composing: ' . $view->getName(), array($view));
    }
    public function callCreator(View $view)
    {
        $this->events->fire('creating: ' . $view->getName(), array($view));
    }
    public function startSection($section, $content = '')
    {
        if ($content === '') {
            ob_start() && ($this->sectionStack[] = $section);
        } else {
            $this->extendSection($section, $content);
        }
    }
    public function inject($section, $content)
    {
        return $this->startSection($section, $content);
    }
    public function yieldSection()
    {
        return $this->yieldContent($this->stopSection());
    }
    public function stopSection($overwrite = false)
    {
        $last = array_pop($this->sectionStack);
        if ($overwrite) {
            $this->sections[$last] = ob_get_clean();
        } else {
            $this->extendSection($last, ob_get_clean());
        }
        return $last;
    }
    public function appendSection()
    {
        $last = array_pop($this->sectionStack);
        if (isset($this->sections[$last])) {
            $this->sections[$last] .= ob_get_clean();
        } else {
            $this->sections[$last] = ob_get_clean();
        }
        return $last;
    }
    protected function extendSection($section, $content)
    {
        if (isset($this->sections[$section])) {
            $content = str_replace('@parent', $content, $this->sections[$section]);
            $this->sections[$section] = $content;
        } else {
            $this->sections[$section] = $content;
        }
    }
    public function yieldContent($section, $default = '')
    {
        return isset($this->sections[$section]) ? $this->sections[$section] : $default;
    }
    public function flushSections()
    {
        $this->sections = array();
        $this->sectionStack = array();
    }
    public function flushSectionsIfDoneRendering()
    {
        if ($this->doneRendering()) {
            $this->flushSections();
        }
    }
    public function incrementRender()
    {
        $this->renderCount++;
    }
    public function decrementRender()
    {
        $this->renderCount--;
    }
    public function doneRendering()
    {
        return $this->renderCount == 0;
    }
    public function addLocation($location)
    {
        $this->finder->addLocation($location);
    }
    public function addNamespace($namespace, $hints)
    {
        $this->finder->addNamespace($namespace, $hints);
    }
    public function prependNamespace($namespace, $hints)
    {
        $this->finder->prependNamespace($namespace, $hints);
    }
    public function addExtension($extension, $engine, $resolver = null)
    {
        $this->finder->addExtension($extension);
        if (isset($resolver)) {
            $this->engines->register($engine, $resolver);
        }
        unset($this->extensions[$extension]);
        $this->extensions = array_merge(array($extension => $engine), $this->extensions);
    }
    public function getExtensions()
    {
        return $this->extensions;
    }
    public function getEngineResolver()
    {
        return $this->engines;
    }
    public function getFinder()
    {
        return $this->finder;
    }
    public function setFinder(ViewFinderInterface $finder)
    {
        $this->finder = $finder;
    }
    public function getDispatcher()
    {
        return $this->events;
    }
    public function setDispatcher(Dispatcher $events)
    {
        $this->events = $events;
    }
    public function getContainer()
    {
        return $this->container;
    }
    public function setContainer(Container $container)
    {
        $this->container = $container;
    }
    public function shared($key, $default = null)
    {
        return array_get($this->shared, $key, $default);
    }
    public function getShared()
    {
        return $this->shared;
    }
    public function getSections()
    {
        return $this->sections;
    }
    public function getNames()
    {
        return $this->names;
    }
}
namespace Royalcms\Component\Support\Contracts;

interface MessageProviderInterface
{
    public function getMessageBag();
}
namespace Royalcms\Component\Support;

use Countable;
use Royalcms\Component\Support\Contracts\JsonableInterface;
use Royalcms\Component\Support\Contracts\ArrayableInterface;
use Royalcms\Component\Support\Contracts\MessageProviderInterface;
class MessageBag implements ArrayableInterface, Countable, JsonableInterface, MessageProviderInterface
{
    protected $messages = array();
    protected $format = ':message';
    public function __construct(array $messages = array())
    {
        foreach ($messages as $key => $value) {
            $this->messages[$key] = (array) $value;
        }
    }
    public function add($key, $message)
    {
        if ($this->isUnique($key, $message)) {
            $this->messages[$key][] = $message;
        }
        return $this;
    }
    public function merge($messages)
    {
        if ($messages instanceof MessageProviderInterface) {
            $messages = $messages->getMessageBag()->getMessages();
        }
        $this->messages = array_merge_recursive($this->messages, $messages);
        return $this;
    }
    protected function isUnique($key, $message)
    {
        $messages = (array) $this->messages;
        return !isset($messages[$key]) || !in_array($message, $messages[$key]);
    }
    public function has($key = null)
    {
        return $this->first($key) !== '';
    }
    public function first($key = null, $format = null)
    {
        $messages = is_null($key) ? $this->all($format) : $this->get($key, $format);
        return count($messages) > 0 ? $messages[0] : '';
    }
    public function get($key, $format = null)
    {
        $format = $this->checkFormat($format);
        if (array_key_exists($key, $this->messages)) {
            return $this->transform($this->messages[$key], $format, $key);
        }
        return array();
    }
    public function all($format = null)
    {
        $format = $this->checkFormat($format);
        $all = array();
        foreach ($this->messages as $key => $messages) {
            $all = array_merge($all, $this->transform($messages, $format, $key));
        }
        return $all;
    }
    protected function transform($messages, $format, $messageKey)
    {
        $messages = (array) $messages;
        foreach ($messages as $key => &$message) {
            $replace = array(':message', ':key');
            $message = str_replace($replace, array($message, $messageKey), $format);
        }
        return $messages;
    }
    protected function checkFormat($format)
    {
        return $format === null ? $this->format : $format;
    }
    public function getMessages()
    {
        return $this->messages;
    }
    public function getMessageBag()
    {
        return $this;
    }
    public function getFormat()
    {
        return $this->format;
    }
    public function setFormat($format = ':message')
    {
        $this->format = $format;
        return $this;
    }
    public function isEmpty()
    {
        return !$this->any();
    }
    public function any()
    {
        return $this->count() > 0;
    }
    public function count()
    {
        return count($this->messages, COUNT_RECURSIVE) - count($this->messages);
    }
    public function toArray()
    {
        return $this->getMessages();
    }
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }
    public function __toString()
    {
        return $this->toJson();
    }
}
namespace Royalcms\Component\Support\Contracts;

interface RenderableInterface
{
    public function render();
}
namespace Royalcms\Component\View;

use ArrayAccess;
use Closure;
use Royalcms\Component\Support\MessageBag;
use Royalcms\Component\View\Engines\EngineInterface;
use Royalcms\Component\Support\Contracts\MessageProviderInterface;
use Royalcms\Component\Support\Contracts\ArrayableInterface as Arrayable;
use Royalcms\Component\Support\Contracts\RenderableInterface as Renderable;
class View implements ArrayAccess, Renderable
{
    protected $environment;
    protected $engine;
    protected $view;
    protected $data;
    protected $path;
    public function __construct(Environment $environment, EngineInterface $engine, $view, $path, $data = array())
    {
        $this->view = $view;
        $this->path = $path;
        $this->engine = $engine;
        $this->environment = $environment;
        $this->data = $data instanceof Arrayable ? $data->toArray() : (array) $data;
    }
    public function render(Closure $callback = null)
    {
        $contents = $this->renderContents();
        $response = isset($callback) ? $callback($this, $contents) : null;
        $this->environment->flushSectionsIfDoneRendering();
        return $response ?: $contents;
    }
    protected function renderContents()
    {
        $this->environment->incrementRender();
        $this->environment->callComposer($this);
        $contents = $this->getContents();
        $this->environment->decrementRender();
        return $contents;
    }
    public function renderSections()
    {
        $env = $this->environment;
        return $this->render(function ($view) use($env) {
            return $env->getSections();
        });
    }
    protected function getContents()
    {
        return $this->engine->get($this->path, $this->gatherData());
    }
    protected function gatherData()
    {
        $data = array_merge($this->environment->getShared(), $this->data);
        foreach ($data as $key => $value) {
            if ($value instanceof Renderable) {
                $data[$key] = $value->render();
            }
        }
        return $data;
    }
    public function with($key, $value = null)
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }
        return $this;
    }
    public function nest($key, $view, array $data = array())
    {
        return $this->with($key, $this->environment->make($view, $data));
    }
    public function withErrors($provider)
    {
        if ($provider instanceof MessageProviderInterface) {
            $this->with('errors', $provider->getMessageBag());
        } else {
            $this->with('errors', new MessageBag((array) $provider));
        }
        return $this;
    }
    public function getEnvironment()
    {
        return $this->environment;
    }
    public function getEngine()
    {
        return $this->engine;
    }
    public function getName()
    {
        return $this->view;
    }
    public function getData()
    {
        return $this->data;
    }
    public function getPath()
    {
        return $this->path;
    }
    public function setPath($path)
    {
        $this->path = $path;
    }
    public function offsetExists($key)
    {
        return array_key_exists($key, $this->data);
    }
    public function offsetGet($key)
    {
        return $this->data[$key];
    }
    public function offsetSet($key, $value)
    {
        $this->with($key, $value);
    }
    public function offsetUnset($key)
    {
        unset($this->data[$key]);
    }
    public function &__get($key)
    {
        return $this->data[$key];
    }
    public function __set($key, $value)
    {
        $this->with($key, $value);
    }
    public function __isset($key)
    {
        return isset($this->data[$key]);
    }
    public function __unset($key)
    {
        unset($this->data[$key]);
    }
    public function __call($method, $parameters)
    {
        if (starts_with($method, 'with')) {
            return $this->with(snake_case(substr($method, 4)), $parameters[0]);
        }
        throw new \BadMethodCallException("Method [{$method}] does not exist on view.");
    }
    public function __toString()
    {
        return $this->render();
    }
}
namespace Royalcms\Component\View\Engines;

interface EngineInterface
{
    public function get($path, array $data = array());
}
namespace Royalcms\Component\View\Engines;

class PhpEngine implements EngineInterface
{
    public function get($path, array $data = array())
    {
        return $this->evaluatePath($path, $data);
    }
    protected function evaluatePath($__path, $__data)
    {
        ob_start();
        extract($__data);
        try {
            include $__path;
        } catch (\Exception $e) {
            $this->handleViewException($e);
        }
        return ltrim(ob_get_clean());
    }
    protected function handleViewException($e)
    {
        ob_get_clean();
        throw $e;
    }
}
namespace Royalcms\Component\Translation;

use Royalcms\Component\Support\ServiceProvider;
class TranslationServiceProvider extends ServiceProvider
{
    protected $defer = true;
    public function register()
    {
        $this->registerLoader();
        $this->royalcms->bindShared('translator', function ($royalcms) {
            $loader = $royalcms['translation.loader'];
            $locale = $royalcms['config']['system.locale'];
            $trans = new Translator($loader, $locale);
            $trans->setFallback($royalcms['config']['system.fallback_locale']);
            return $trans;
        });
    }
    protected function registerLoader()
    {
        $this->royalcms->bindShared('translation.loader', function ($royalcms) {
            return new FileLoader($royalcms['files'], $royalcms['path.system'] . '/languages');
        });
    }
    public function provides()
    {
        return array('translator', 'translation.loader');
    }
}
namespace Royalcms\Component\Translation;

use Royalcms\Component\Filesystem\Filesystem;
class FileLoader implements LoaderInterface
{
    protected $files;
    protected $path;
    protected $hints = array();
    public function __construct(Filesystem $files, $path)
    {
        $this->path = $path;
        $this->files = $files;
    }
    public function load($locale, $group, $namespace = null)
    {
        if (is_null($namespace) || $namespace == '*') {
            return $this->loadPath($this->path, $locale, $group);
        } else {
            return $this->loadNamespaced($locale, $group, $namespace);
        }
    }
    protected function loadNamespaced($locale, $group, $namespace)
    {
        if (isset($this->hints[$namespace])) {
            $lines = $this->loadPath($this->hints[$namespace], $locale, $group);
            return $this->loadNamespaceOverrides($lines, $locale, $group, $namespace);
        }
        return array();
    }
    protected function loadNamespaceOverrides(array $lines, $locale, $group, $namespace)
    {
        $file = "{$this->path}/packages/{$locale}/{$namespace}/{$group}.lang.php";
        if ($this->files->exists($file)) {
            return array_replace_recursive($lines, $this->files->getRequire($file));
        }
        return $lines;
    }
    protected function loadPath($path, $locale, $group)
    {
        if ($this->files->exists($full = "{$path}/{$locale}/{$group}.lang.php")) {
            return $this->files->getRequire($full);
        }
        return array();
    }
    public function addNamespace($namespace, $hint)
    {
        $this->hints[$namespace] = $hint;
    }
}
namespace Royalcms\Component\Translation;

interface LoaderInterface
{
    public function load($locale, $group, $namespace = null);
    public function addNamespace($namespace, $hint);
}
namespace Royalcms\Component\Translation;

use Royalcms\Component\Support\Collection;
use Royalcms\Component\Support\NamespacedItemResolver;
use Symfony\Component\Translation\MessageSelector;
use Symfony\Component\Translation\TranslatorInterface;
class Translator extends NamespacedItemResolver implements TranslatorInterface
{
    protected $loader;
    protected $locale;
    protected $fallback;
    protected $loaded = array();
    public function __construct(LoaderInterface $loader, $locale)
    {
        $this->loader = $loader;
        $this->locale = $locale;
    }
    public function has($key, $locale = null)
    {
        return $this->get($key, array(), $locale) !== $key;
    }
    public function get($key, array $replace = array(), $locale = null)
    {
        list($namespace, $group, $item) = $this->parseKey($key);
        foreach ($this->parseLocale($locale) as $locale) {
            $this->load($namespace, $group, $locale);
            $line = $this->getLine($namespace, $group, $locale, $item, $replace);
            if (!is_null($line)) {
                break;
            }
        }
        if (!isset($line)) {
            return $key;
        }
        return $line;
    }
    protected function getLine($namespace, $group, $locale, $item, array $replace)
    {
        $line = array_get($this->loaded[$namespace][$group][$locale], $item);
        if (is_string($line)) {
            return $this->makeReplacements($line, $replace);
        } elseif (is_array($line) && count($line) > 0) {
            return $line;
        }
    }
    protected function makeReplacements($line, array $replace)
    {
        $replace = $this->sortReplacements($replace);
        foreach ($replace as $key => $value) {
            $line = str_replace(':' . $key, $value, $line);
        }
        return $line;
    }
    protected function sortReplacements(array $replace)
    {
        return with(new Collection($replace))->sortBy(function ($r) {
            return mb_strlen($r) * -1;
        });
    }
    public function choice($key, $number, array $replace = array(), $locale = null)
    {
        $line = $this->get($key, $replace, $locale = $locale ?: $this->locale);
        $replace['count'] = $number;
        return $this->makeReplacements($this->getSelector()->choose($line, $number, $locale), $replace);
    }
    public function trans($id, array $parameters = array(), $domain = 'messages', $locale = null)
    {
        return $this->get($id, $parameters, $locale);
    }
    public function transChoice($id, $number, array $parameters = array(), $domain = 'messages', $locale = null)
    {
        return $this->choice($id, $number, $parameters, $locale);
    }
    public function load($namespace, $group, $locale)
    {
        if ($this->isLoaded($namespace, $group, $locale)) {
            return;
        }
        $lines = $this->loader->load($locale, $group, $namespace);
        $this->loaded[$namespace][$group][$locale] = $lines;
    }
    protected function isLoaded($namespace, $group, $locale)
    {
        return isset($this->loaded[$namespace][$group][$locale]);
    }
    public function addNamespace($namespace, $hint)
    {
        $this->loader->addNamespace($namespace, $hint);
    }
    public function parseKey($key)
    {
        $segments = parent::parseKey($key);
        if (is_null($segments[0])) {
            $segments[0] = '*';
        }
        return $segments;
    }
    protected function parseLocale($locale)
    {
        if (!is_null($locale)) {
            return array_filter(array($locale, $this->fallback));
        } else {
            return array_filter(array($this->locale, $this->fallback));
        }
    }
    public function getSelector()
    {
        if (!isset($this->selector)) {
            $this->selector = new MessageSelector();
        }
        return $this->selector;
    }
    public function setSelector(MessageSelector $selector)
    {
        $this->selector = $selector;
    }
    public function getLoader()
    {
        return $this->loader;
    }
    public function locale()
    {
        return $this->getLocale();
    }
    public function getLocale()
    {
        return $this->locale;
    }
    public function setLocale($locale)
    {
        $this->locale = $locale;
    }
    public function getFallback()
    {
        return $this->fallback;
    }
    public function setFallback($fallback)
    {
        $this->fallback = $fallback;
    }
}
namespace Royalcms\Component\Hook;

class Hooks
{
    public $filters = array();
    public $merged_filters = array();
    public $actions = array();
    public $current_filter = array();
    public function __construct($args = null)
    {
        $this->filters = array();
        $this->merged_filters = array();
        $this->actions = array();
        $this->current_filter = array();
    }
    public function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1)
    {
        $idx = $this->_filter_build_unique_id($tag, $function_to_add, $priority);
        $this->filters[$tag][$priority][$idx] = array('function' => $function_to_add, 'accepted_args' => $accepted_args);
        unset($this->merged_filters[$tag]);
        return true;
    }
    public function remove_filter($tag, $function_to_remove, $priority = 10)
    {
        $function_to_remove = $this->_filter_build_unique_id($tag, $function_to_remove, $priority);
        $r = isset($this->filters[$tag][$priority][$function_to_remove]);
        if (true === $r) {
            unset($this->filters[$tag][$priority][$function_to_remove]);
            if (empty($this->filters[$tag][$priority])) {
                unset($this->filters[$tag][$priority]);
            }
            unset($this->merged_filters[$tag]);
        }
        return $r;
    }
    public function remove_all_filters($tag, $priority = false)
    {
        if (isset($this->filters[$tag])) {
            if (false !== $priority && isset($this->filters[$tag][$priority])) {
                unset($this->filters[$tag][$priority]);
            } else {
                unset($this->filters[$tag]);
            }
        }
        if (isset($this->merged_filters[$tag])) {
            unset($this->merged_filters[$tag]);
        }
        return true;
    }
    public function has_filter($tag, $function_to_check = false)
    {
        $has = !empty($this->filters[$tag]);
        if (false === $function_to_check || false == $has) {
            return $has;
        }
        if (!($idx = $this->_filter_build_unique_id($tag, $function_to_check, false))) {
            return false;
        }
        foreach ((array) array_keys($this->filters[$tag]) as $priority) {
            if (isset($this->filters[$tag][$priority][$idx])) {
                return $priority;
            }
        }
        return false;
    }
    public function apply_filters($tag, $value)
    {
        $args = array();
        if (isset($this->filters['all'])) {
            $this->current_filter[] = $tag;
            $args = func_get_args();
            $this->_call_all_hook($args);
        }
        if (!isset($this->filters[$tag])) {
            if (isset($this->filters['all'])) {
                array_pop($this->current_filter);
            }
            return $value;
        }
        if (!isset($this->filters['all'])) {
            $this->current_filter[] = $tag;
        }
        if (!isset($this->merged_filters[$tag])) {
            ksort($this->filters[$tag]);
            $this->merged_filters[$tag] = true;
        }
        reset($this->filters[$tag]);
        if (empty($args)) {
            $args = func_get_args();
        }
        do {
            foreach ((array) current($this->filters[$tag]) as $the_) {
                if (!is_null($the_['function'])) {
                    $args[1] = $value;
                    $value = call_user_func_array($the_['function'], array_slice($args, 1, (int) $the_['accepted_args']));
                }
            }
        } while (next($this->filters[$tag]) !== false);
        array_pop($this->current_filter);
        return $value;
    }
    public function apply_filters_ref_array($tag, $args)
    {
        if (isset($this->filters['all'])) {
            $this->current_filter[] = $tag;
            $all_args = func_get_args();
            $this->_call_all_hook($all_args);
        }
        if (!isset($this->filters[$tag])) {
            if (isset($this->filters['all'])) {
                array_pop($this->current_filter);
            }
            return $args[0];
        }
        if (!isset($this->filters['all'])) {
            $this->current_filter[] = $tag;
        }
        if (!isset($this->merged_filters[$tag])) {
            ksort($this->filters[$tag]);
            $this->merged_filters[$tag] = true;
        }
        reset($this->filters[$tag]);
        do {
            foreach ((array) current($this->filters[$tag]) as $the_) {
                if (!is_null($the_['function'])) {
                    $args[0] = call_user_func_array($the_['function'], array_slice($args, 0, (int) $the_['accepted_args']));
                }
            }
        } while (next($this->filters[$tag]) !== false);
        array_pop($this->current_filter);
        return $args[0];
    }
    public function add_action($tag, $function_to_add, $priority = 10, $accepted_args = 1)
    {
        return $this->add_filter($tag, $function_to_add, $priority, $accepted_args);
    }
    public function has_action($tag, $function_to_check = false)
    {
        return $this->has_filter($tag, $function_to_check);
    }
    public function remove_action($tag, $function_to_remove, $priority = 10)
    {
        return $this->remove_filter($tag, $function_to_remove, $priority);
    }
    public function remove_all_actions($tag, $priority = false)
    {
        return $this->remove_all_filters($tag, $priority);
    }
    public function do_action($tag, $arg = '')
    {
        if (!isset($this->actions)) {
            $this->actions = array();
        }
        if (!isset($this->actions[$tag])) {
            $this->actions[$tag] = 1;
        } else {
            ++$this->actions[$tag];
        }
        if (isset($this->filters['all'])) {
            $this->current_filter[] = $tag;
            $all_args = func_get_args();
            $this->_call_all_hook($all_args);
        }
        if (!isset($this->filters[$tag])) {
            if (isset($this->filters['all'])) {
                array_pop($this->current_filter);
            }
            return;
        }
        if (!isset($this->filters['all'])) {
            $this->current_filter[] = $tag;
        }
        $args = array();
        if (is_array($arg) && 1 == count($arg) && isset($arg[0]) && is_object($arg[0])) {
            $args[] =& $arg[0];
        } else {
            $args[] = $arg;
        }
        for ($a = 2; $a < func_num_args(); $a++) {
            $args[] = func_get_arg($a);
        }
        if (!isset($this->merged_filters[$tag])) {
            ksort($this->filters[$tag]);
            $this->merged_filters[$tag] = true;
        }
        reset($this->filters[$tag]);
        do {
            foreach ((array) current($this->filters[$tag]) as $the_) {
                if (!is_null($the_['function'])) {
                    call_user_func_array($the_['function'], array_slice($args, 0, (int) $the_['accepted_args']));
                }
            }
        } while (next($this->filters[$tag]) !== false);
        array_pop($this->current_filter);
    }
    public function do_action_ref_array($tag, $args)
    {
        if (!isset($this->actions)) {
            $this->actions = array();
        }
        if (!isset($this->actions[$tag])) {
            $this->actions[$tag] = 1;
        } else {
            ++$this->actions[$tag];
        }
        if (isset($this->filters['all'])) {
            $this->current_filter[] = $tag;
            $all_args = func_get_args();
            $this->_call_all_hook($all_args);
        }
        if (!isset($this->filters[$tag])) {
            if (isset($this->filters['all'])) {
                array_pop($this->current_filter);
            }
            return;
        }
        if (!isset($this->filters['all'])) {
            $this->current_filter[] = $tag;
        }
        if (!isset($merged_filters[$tag])) {
            ksort($this->filters[$tag]);
            $merged_filters[$tag] = true;
        }
        reset($this->filters[$tag]);
        do {
            foreach ((array) current($this->filters[$tag]) as $the_) {
                if (!is_null($the_['function'])) {
                    call_user_func_array($the_['function'], array_slice($args, 0, (int) $the_['accepted_args']));
                }
            }
        } while (next($this->filters[$tag]) !== false);
        array_pop($this->current_filter);
    }
    public function did_action($tag)
    {
        if (!isset($this->actions) || !isset($this->actions[$tag])) {
            return 0;
        }
        return $this->actions[$tag];
    }
    public function current_filter()
    {
        return end($this->current_filter);
    }
    public function current_action()
    {
        return $this->current_filter();
    }
    public function doing_filter($filter = null)
    {
        if (null === $filter) {
            return !empty($this->current_filter);
        }
        return in_array($filter, $this->current_filter);
    }
    public function doing_action($action = null)
    {
        return $this->doing_filter($action);
    }
    private function _filter_build_unique_id($tag, $function, $priority)
    {
        static $filter_id_count = 0;
        if (is_string($function)) {
            return $function;
        }
        if (is_object($function)) {
            $function = array($function, '');
        } else {
            $function = (array) $function;
        }
        if (is_object($function[0])) {
            if (function_exists('spl_object_hash')) {
                return spl_object_hash($function[0]) . $function[1];
            } else {
                $obj_idx = get_class($function[0]) . $function[1];
                if (!isset($function[0]->filter_id)) {
                    if (false === $priority) {
                        return false;
                    }
                    $obj_idx .= isset($this->filters[$tag][$priority]) ? count((array) ${$this}->filters[$tag][$priority]) : $filter_id_count;
                    $function[0]->filter_id = $filter_id_count;
                    ++$filter_id_count;
                } else {
                    $obj_idx .= $function[0]->filter_id;
                }
                return $obj_idx;
            }
        } else {
            if (is_string($function[0])) {
                return $function[0] . $function[1];
            }
        }
    }
    public function __call_all_hook($args)
    {
        reset($this->filters['all']);
        do {
            foreach ((array) current($this->filters['all']) as $the_) {
                if (!is_null($the_['function'])) {
                    call_user_func_array($the_['function'], $args);
                }
            }
        } while (next($this->filters['all']) !== false);
    }
}
namespace Symfony\Component\HttpFoundation;

class Response
{
    const HTTP_CONTINUE = 100;
    const HTTP_SWITCHING_PROTOCOLS = 101;
    const HTTP_PROCESSING = 102;
    const HTTP_OK = 200;
    const HTTP_CREATED = 201;
    const HTTP_ACCEPTED = 202;
    const HTTP_NON_AUTHORITATIVE_INFORMATION = 203;
    const HTTP_NO_CONTENT = 204;
    const HTTP_RESET_CONTENT = 205;
    const HTTP_PARTIAL_CONTENT = 206;
    const HTTP_MULTI_STATUS = 207;
    const HTTP_ALREADY_REPORTED = 208;
    const HTTP_IM_USED = 226;
    const HTTP_MULTIPLE_CHOICES = 300;
    const HTTP_MOVED_PERMANENTLY = 301;
    const HTTP_FOUND = 302;
    const HTTP_SEE_OTHER = 303;
    const HTTP_NOT_MODIFIED = 304;
    const HTTP_USE_PROXY = 305;
    const HTTP_RESERVED = 306;
    const HTTP_TEMPORARY_REDIRECT = 307;
    const HTTP_PERMANENTLY_REDIRECT = 308;
    const HTTP_BAD_REQUEST = 400;
    const HTTP_UNAUTHORIZED = 401;
    const HTTP_PAYMENT_REQUIRED = 402;
    const HTTP_FORBIDDEN = 403;
    const HTTP_NOT_FOUND = 404;
    const HTTP_METHOD_NOT_ALLOWED = 405;
    const HTTP_NOT_ACCEPTABLE = 406;
    const HTTP_PROXY_AUTHENTICATION_REQUIRED = 407;
    const HTTP_REQUEST_TIMEOUT = 408;
    const HTTP_CONFLICT = 409;
    const HTTP_GONE = 410;
    const HTTP_LENGTH_REQUIRED = 411;
    const HTTP_PRECONDITION_FAILED = 412;
    const HTTP_REQUEST_ENTITY_TOO_LARGE = 413;
    const HTTP_REQUEST_URI_TOO_LONG = 414;
    const HTTP_UNSUPPORTED_MEDIA_TYPE = 415;
    const HTTP_REQUESTED_RANGE_NOT_SATISFIABLE = 416;
    const HTTP_EXPECTATION_FAILED = 417;
    const HTTP_I_AM_A_TEAPOT = 418;
    const HTTP_UNPROCESSABLE_ENTITY = 422;
    const HTTP_LOCKED = 423;
    const HTTP_FAILED_DEPENDENCY = 424;
    const HTTP_RESERVED_FOR_WEBDAV_ADVANCED_COLLECTIONS_EXPIRED_PROPOSAL = 425;
    const HTTP_UPGRADE_REQUIRED = 426;
    const HTTP_PRECONDITION_REQUIRED = 428;
    const HTTP_TOO_MANY_REQUESTS = 429;
    const HTTP_REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
    const HTTP_INTERNAL_SERVER_ERROR = 500;
    const HTTP_NOT_IMPLEMENTED = 501;
    const HTTP_BAD_GATEWAY = 502;
    const HTTP_SERVICE_UNAVAILABLE = 503;
    const HTTP_GATEWAY_TIMEOUT = 504;
    const HTTP_VERSION_NOT_SUPPORTED = 505;
    const HTTP_VARIANT_ALSO_NEGOTIATES_EXPERIMENTAL = 506;
    const HTTP_INSUFFICIENT_STORAGE = 507;
    const HTTP_LOOP_DETECTED = 508;
    const HTTP_NOT_EXTENDED = 510;
    const HTTP_NETWORK_AUTHENTICATION_REQUIRED = 511;
    public $headers;
    protected $content;
    protected $version;
    protected $statusCode;
    protected $statusText;
    protected $charset;
    public static $statusTexts = array(100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing', 200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-Status', 208 => 'Already Reported', 226 => 'IM Used', 300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 306 => 'Reserved', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect', 400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Request Entity Too Large', 414 => 'Request-URI Too Long', 415 => 'Unsupported Media Type', 416 => 'Requested Range Not Satisfiable', 417 => 'Expectation Failed', 418 => 'I\'m a teapot', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Reserved for WebDAV advanced collections expired proposal', 426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large', 500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates (Experimental)', 507 => 'Insufficient Storage', 508 => 'Loop Detected', 510 => 'Not Extended', 511 => 'Network Authentication Required');
    public function __construct($content = '', $status = 200, $headers = array())
    {
        $this->headers = new ResponseHeaderBag($headers);
        $this->setContent($content);
        $this->setStatusCode($status);
        $this->setProtocolVersion('1.0');
        if (!$this->headers->has('Date')) {
            $this->setDate(new \DateTime(null, new \DateTimeZone('UTC')));
        }
    }
    public static function create($content = '', $status = 200, $headers = array())
    {
        return new static($content, $status, $headers);
    }
    public function __toString()
    {
        return sprintf('HTTP/%s %s %s', $this->version, $this->statusCode, $this->statusText) . '
' . $this->headers . '
' . $this->getContent();
    }
    public function __clone()
    {
        $this->headers = clone $this->headers;
    }
    public function prepare(Request $request)
    {
        $headers = $this->headers;
        if ($this->isInformational() || in_array($this->statusCode, array(204, 304))) {
            $this->setContent(null);
            $headers->remove('Content-Type');
            $headers->remove('Content-Length');
        } else {
            if (!$headers->has('Content-Type')) {
                $format = $request->getRequestFormat();
                if (null !== $format && ($mimeType = $request->getMimeType($format))) {
                    $headers->set('Content-Type', $mimeType);
                }
            }
            $charset = $this->charset ?: 'UTF-8';
            if (!$headers->has('Content-Type')) {
                $headers->set('Content-Type', 'text/html; charset=' . $charset);
            } elseif (0 === stripos($headers->get('Content-Type'), 'text/') && false === stripos($headers->get('Content-Type'), 'charset')) {
                $headers->set('Content-Type', $headers->get('Content-Type') . '; charset=' . $charset);
            }
            if ($headers->has('Transfer-Encoding')) {
                $headers->remove('Content-Length');
            }
            if ($request->isMethod('HEAD')) {
                $length = $headers->get('Content-Length');
                $this->setContent(null);
                if ($length) {
                    $headers->set('Content-Length', $length);
                }
            }
        }
        if ('HTTP/1.0' != $request->server->get('SERVER_PROTOCOL')) {
            $this->setProtocolVersion('1.1');
        }
        if ('1.0' == $this->getProtocolVersion() && 'no-cache' == $this->headers->get('Cache-Control')) {
            $this->headers->set('pragma', 'no-cache');
            $this->headers->set('expires', -1);
        }
        $this->ensureIEOverSSLCompatibility($request);
        return $this;
    }
    public function sendHeaders()
    {
        if (headers_sent()) {
            return $this;
        }
        header(sprintf('HTTP/%s %s %s', $this->version, $this->statusCode, $this->statusText), true, $this->statusCode);
        foreach ($this->headers->allPreserveCase() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false, $this->statusCode);
            }
        }
        foreach ($this->headers->getCookies() as $cookie) {
            setcookie($cookie->getName(), $cookie->getValue(), $cookie->getExpiresTime(), $cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly());
        }
        return $this;
    }
    public function sendContent()
    {
        echo $this->content;
        return $this;
    }
    public function send()
    {
        $this->sendHeaders();
        $this->sendContent();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif ('cli' !== PHP_SAPI) {
            $previous = null;
            $obStatus = ob_get_status(1);
            while (($level = ob_get_level()) > 0 && $level !== $previous) {
                $previous = $level;
                if ($obStatus[$level - 1]) {
                    if (version_compare(PHP_VERSION, '5.4', '>=')) {
                        if (isset($obStatus[$level - 1]['flags']) && $obStatus[$level - 1]['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE) {
                            ob_end_flush();
                        }
                    } else {
                        if (isset($obStatus[$level - 1]['del']) && $obStatus[$level - 1]['del']) {
                            ob_end_flush();
                        }
                    }
                }
            }
            flush();
        }
        return $this;
    }
    public function setContent($content)
    {
        if (null !== $content && !is_string($content) && !is_numeric($content) && !is_callable(array($content, '__toString'))) {
            throw new \UnexpectedValueException(sprintf('The Response content must be a string or object implementing __toString(), "%s" given.', gettype($content)));
        }
        $this->content = (string) $content;
        return $this;
    }
    public function getContent()
    {
        return $this->content;
    }
    public function setProtocolVersion($version)
    {
        $this->version = $version;
        return $this;
    }
    public function getProtocolVersion()
    {
        return $this->version;
    }
    public function setStatusCode($code, $text = null)
    {
        $this->statusCode = $code = (int) $code;
        if ($this->isInvalid()) {
            throw new \InvalidArgumentException(sprintf('The HTTP status code "%s" is not valid.', $code));
        }
        if (null === $text) {
            $this->statusText = isset(self::$statusTexts[$code]) ? self::$statusTexts[$code] : '';
            return $this;
        }
        if (false === $text) {
            $this->statusText = '';
            return $this;
        }
        $this->statusText = $text;
        return $this;
    }
    public function getStatusCode()
    {
        return $this->statusCode;
    }
    public function setCharset($charset)
    {
        $this->charset = $charset;
        return $this;
    }
    public function getCharset()
    {
        return $this->charset;
    }
    public function isCacheable()
    {
        if (!in_array($this->statusCode, array(200, 203, 300, 301, 302, 404, 410))) {
            return false;
        }
        if ($this->headers->hasCacheControlDirective('no-store') || $this->headers->getCacheControlDirective('private')) {
            return false;
        }
        return $this->isValidateable() || $this->isFresh();
    }
    public function isFresh()
    {
        return $this->getTtl() > 0;
    }
    public function isValidateable()
    {
        return $this->headers->has('Last-Modified') || $this->headers->has('ETag');
    }
    public function setPrivate()
    {
        $this->headers->removeCacheControlDirective('public');
        $this->headers->addCacheControlDirective('private');
        return $this;
    }
    public function setPublic()
    {
        $this->headers->addCacheControlDirective('public');
        $this->headers->removeCacheControlDirective('private');
        return $this;
    }
    public function mustRevalidate()
    {
        return $this->headers->hasCacheControlDirective('must-revalidate') || $this->headers->has('proxy-revalidate');
    }
    public function getDate()
    {
        return $this->headers->getDate('Date', new \DateTime());
    }
    public function setDate(\DateTime $date)
    {
        $date->setTimezone(new \DateTimeZone('UTC'));
        $this->headers->set('Date', $date->format('D, d M Y H:i:s') . ' GMT');
        return $this;
    }
    public function getAge()
    {
        if (null !== ($age = $this->headers->get('Age'))) {
            return (int) $age;
        }
        return max(time() - $this->getDate()->format('U'), 0);
    }
    public function expire()
    {
        if ($this->isFresh()) {
            $this->headers->set('Age', $this->getMaxAge());
        }
        return $this;
    }
    public function getExpires()
    {
        try {
            return $this->headers->getDate('Expires');
        } catch (\RuntimeException $e) {
            return \DateTime::createFromFormat(DATE_RFC2822, 'Sat, 01 Jan 00 00:00:00 +0000');
        }
    }
    public function setExpires(\DateTime $date = null)
    {
        if (null === $date) {
            $this->headers->remove('Expires');
        } else {
            $date = clone $date;
            $date->setTimezone(new \DateTimeZone('UTC'));
            $this->headers->set('Expires', $date->format('D, d M Y H:i:s') . ' GMT');
        }
        return $this;
    }
    public function getMaxAge()
    {
        if ($this->headers->hasCacheControlDirective('s-maxage')) {
            return (int) $this->headers->getCacheControlDirective('s-maxage');
        }
        if ($this->headers->hasCacheControlDirective('max-age')) {
            return (int) $this->headers->getCacheControlDirective('max-age');
        }
        if (null !== $this->getExpires()) {
            return $this->getExpires()->format('U') - $this->getDate()->format('U');
        }
    }
    public function setMaxAge($value)
    {
        $this->headers->addCacheControlDirective('max-age', $value);
        return $this;
    }
    public function setSharedMaxAge($value)
    {
        $this->setPublic();
        $this->headers->addCacheControlDirective('s-maxage', $value);
        return $this;
    }
    public function getTtl()
    {
        if (null !== ($maxAge = $this->getMaxAge())) {
            return $maxAge - $this->getAge();
        }
    }
    public function setTtl($seconds)
    {
        $this->setSharedMaxAge($this->getAge() + $seconds);
        return $this;
    }
    public function setClientTtl($seconds)
    {
        $this->setMaxAge($this->getAge() + $seconds);
        return $this;
    }
    public function getLastModified()
    {
        return $this->headers->getDate('Last-Modified');
    }
    public function setLastModified(\DateTime $date = null)
    {
        if (null === $date) {
            $this->headers->remove('Last-Modified');
        } else {
            $date = clone $date;
            $date->setTimezone(new \DateTimeZone('UTC'));
            $this->headers->set('Last-Modified', $date->format('D, d M Y H:i:s') . ' GMT');
        }
        return $this;
    }
    public function getEtag()
    {
        return $this->headers->get('ETag');
    }
    public function setEtag($etag = null, $weak = false)
    {
        if (null === $etag) {
            $this->headers->remove('Etag');
        } else {
            if (0 !== strpos($etag, '"')) {
                $etag = '"' . $etag . '"';
            }
            $this->headers->set('ETag', (true === $weak ? 'W/' : '') . $etag);
        }
        return $this;
    }
    public function setCache(array $options)
    {
        if ($diff = array_diff(array_keys($options), array('etag', 'last_modified', 'max_age', 's_maxage', 'private', 'public'))) {
            throw new \InvalidArgumentException(sprintf('Response does not support the following options: "%s".', implode('", "', array_values($diff))));
        }
        if (isset($options['etag'])) {
            $this->setEtag($options['etag']);
        }
        if (isset($options['last_modified'])) {
            $this->setLastModified($options['last_modified']);
        }
        if (isset($options['max_age'])) {
            $this->setMaxAge($options['max_age']);
        }
        if (isset($options['s_maxage'])) {
            $this->setSharedMaxAge($options['s_maxage']);
        }
        if (isset($options['public'])) {
            if ($options['public']) {
                $this->setPublic();
            } else {
                $this->setPrivate();
            }
        }
        if (isset($options['private'])) {
            if ($options['private']) {
                $this->setPrivate();
            } else {
                $this->setPublic();
            }
        }
        return $this;
    }
    public function setNotModified()
    {
        $this->setStatusCode(304);
        $this->setContent(null);
        foreach (array('Allow', 'Content-Encoding', 'Content-Language', 'Content-Length', 'Content-MD5', 'Content-Type', 'Last-Modified') as $header) {
            $this->headers->remove($header);
        }
        return $this;
    }
    public function hasVary()
    {
        return null !== $this->headers->get('Vary');
    }
    public function getVary()
    {
        if (!($vary = $this->headers->get('Vary', null, false))) {
            return array();
        }
        $ret = array();
        foreach ($vary as $item) {
            $ret = array_merge($ret, preg_split('/[\\s,]+/', $item));
        }
        return $ret;
    }
    public function setVary($headers, $replace = true)
    {
        $this->headers->set('Vary', $headers, $replace);
        return $this;
    }
    public function isNotModified(Request $request)
    {
        if (!$request->isMethodSafe()) {
            return false;
        }
        $notModified = false;
        $lastModified = $this->headers->get('Last-Modified');
        $modifiedSince = $request->headers->get('If-Modified-Since');
        if ($etags = $request->getEtags()) {
            $notModified = in_array($this->getEtag(), $etags) || in_array('*', $etags);
        }
        if ($modifiedSince && $lastModified) {
            $notModified = strtotime($modifiedSince) >= strtotime($lastModified) && (!$etags || $notModified);
        }
        if ($notModified) {
            $this->setNotModified();
        }
        return $notModified;
    }
    public function isInvalid()
    {
        return $this->statusCode < 100 || $this->statusCode >= 600;
    }
    public function isInformational()
    {
        return $this->statusCode >= 100 && $this->statusCode < 200;
    }
    public function isSuccessful()
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
    public function isRedirection()
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }
    public function isClientError()
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }
    public function isServerError()
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }
    public function isOk()
    {
        return 200 === $this->statusCode;
    }
    public function isForbidden()
    {
        return 403 === $this->statusCode;
    }
    public function isNotFound()
    {
        return 404 === $this->statusCode;
    }
    public function isRedirect($location = null)
    {
        return in_array($this->statusCode, array(201, 301, 302, 303, 307, 308)) && (null === $location ?: $location == $this->headers->get('Location'));
    }
    public function isEmpty()
    {
        return in_array($this->statusCode, array(204, 304));
    }
    protected function ensureIEOverSSLCompatibility(Request $request)
    {
        if (false !== stripos($this->headers->get('Content-Disposition'), 'attachment') && preg_match('/MSIE (.*?);/i', $request->server->get('HTTP_USER_AGENT'), $match) == 1 && true === $request->isSecure()) {
            if (intval(preg_replace('/(MSIE )(.*?);/', '$2', $match[0])) < 9) {
                $this->headers->remove('Cache-Control');
            }
        }
    }
}
namespace Royalcms\Component\HttpKernel;

use ArrayObject;
use Symfony\Component\HttpFoundation\Cookie;
use Royalcms\Component\Support\Contracts\JsonableInterface;
use Royalcms\Component\Support\Contracts\RenderableInterface;
class Response extends \Symfony\Component\HttpFoundation\Response
{
    public $original;
    public function header($key, $value, $replace = true)
    {
        $this->headers->set($key, $value, $replace);
        return $this;
    }
    public function withCookie(Cookie $cookie)
    {
        $this->headers->setCookie($cookie);
        return $this;
    }
    public function setContent($content)
    {
        $this->original = $content;
        if ($this->shouldBeJson($content)) {
            $this->headers->set('Content-Type', 'application/json');
            $content = $this->morphToJson($content);
        } elseif ($content instanceof RenderableInterface) {
            $content = $content->render();
        }
        return parent::setContent($content);
    }
    protected function morphToJson($content)
    {
        if ($content instanceof JsonableInterface) {
            return $content->toJson();
        }
        return json_encode($content);
    }
    protected function shouldBeJson($content)
    {
        return $content instanceof JsonableInterface || $content instanceof ArrayObject || is_array($content);
    }
    public function getOriginalContent()
    {
        return $this->original;
    }
}
namespace Symfony\Component\HttpFoundation;

class ResponseHeaderBag extends HeaderBag
{
    const COOKIES_FLAT = 'flat';
    const COOKIES_ARRAY = 'array';
    const DISPOSITION_ATTACHMENT = 'attachment';
    const DISPOSITION_INLINE = 'inline';
    protected $computedCacheControl = array();
    protected $cookies = array();
    protected $headerNames = array();
    public function __construct(array $headers = array())
    {
        parent::__construct($headers);
        if (!isset($this->headers['cache-control'])) {
            $this->set('Cache-Control', '');
        }
    }
    public function __toString()
    {
        $cookies = '';
        foreach ($this->getCookies() as $cookie) {
            $cookies .= 'Set-Cookie: ' . $cookie . '
';
        }
        ksort($this->headerNames);
        return parent::__toString() . $cookies;
    }
    public function allPreserveCase()
    {
        return array_combine($this->headerNames, $this->headers);
    }
    public function replace(array $headers = array())
    {
        $this->headerNames = array();
        parent::replace($headers);
        if (!isset($this->headers['cache-control'])) {
            $this->set('Cache-Control', '');
        }
    }
    public function set($key, $values, $replace = true)
    {
        parent::set($key, $values, $replace);
        $uniqueKey = strtr(strtolower($key), '_', '-');
        $this->headerNames[$uniqueKey] = $key;
        if (in_array($uniqueKey, array('cache-control', 'etag', 'last-modified', 'expires'))) {
            $computed = $this->computeCacheControlValue();
            $this->headers['cache-control'] = array($computed);
            $this->headerNames['cache-control'] = 'Cache-Control';
            $this->computedCacheControl = $this->parseCacheControl($computed);
        }
    }
    public function remove($key)
    {
        parent::remove($key);
        $uniqueKey = strtr(strtolower($key), '_', '-');
        unset($this->headerNames[$uniqueKey]);
        if ('cache-control' === $uniqueKey) {
            $this->computedCacheControl = array();
        }
    }
    public function hasCacheControlDirective($key)
    {
        return array_key_exists($key, $this->computedCacheControl);
    }
    public function getCacheControlDirective($key)
    {
        return array_key_exists($key, $this->computedCacheControl) ? $this->computedCacheControl[$key] : null;
    }
    public function setCookie(Cookie $cookie)
    {
        $this->cookies[$cookie->getDomain()][$cookie->getPath()][$cookie->getName()] = $cookie;
    }
    public function removeCookie($name, $path = '/', $domain = null)
    {
        if (null === $path) {
            $path = '/';
        }
        unset($this->cookies[$domain][$path][$name]);
        if (empty($this->cookies[$domain][$path])) {
            unset($this->cookies[$domain][$path]);
            if (empty($this->cookies[$domain])) {
                unset($this->cookies[$domain]);
            }
        }
    }
    public function getCookies($format = self::COOKIES_FLAT)
    {
        if (!in_array($format, array(self::COOKIES_FLAT, self::COOKIES_ARRAY))) {
            throw new \InvalidArgumentException(sprintf('Format "%s" invalid (%s).', $format, implode(', ', array(self::COOKIES_FLAT, self::COOKIES_ARRAY))));
        }
        if (self::COOKIES_ARRAY === $format) {
            return $this->cookies;
        }
        $flattenedCookies = array();
        foreach ($this->cookies as $path) {
            foreach ($path as $cookies) {
                foreach ($cookies as $cookie) {
                    $flattenedCookies[] = $cookie;
                }
            }
        }
        return $flattenedCookies;
    }
    public function clearCookie($name, $path = '/', $domain = null)
    {
        $this->setCookie(new Cookie($name, null, 1, $path, $domain));
    }
    public function makeDisposition($disposition, $filename, $filenameFallback = '')
    {
        if (!in_array($disposition, array(self::DISPOSITION_ATTACHMENT, self::DISPOSITION_INLINE))) {
            throw new \InvalidArgumentException(sprintf('The disposition must be either "%s" or "%s".', self::DISPOSITION_ATTACHMENT, self::DISPOSITION_INLINE));
        }
        if ('' == $filenameFallback) {
            $filenameFallback = $filename;
        }
        if (!preg_match('/^[\\x20-\\x7e]*$/', $filenameFallback)) {
            throw new \InvalidArgumentException('The filename fallback must only contain ASCII characters.');
        }
        if (false !== strpos($filenameFallback, '%')) {
            throw new \InvalidArgumentException('The filename fallback cannot contain the "%" character.');
        }
        if (false !== strpos($filename, '/') || false !== strpos($filename, '\\') || false !== strpos($filenameFallback, '/') || false !== strpos($filenameFallback, '\\')) {
            throw new \InvalidArgumentException('The filename and the fallback cannot contain the "/" and "\\" characters.');
        }
        $output = sprintf('%s; filename="%s"', $disposition, str_replace('"', '\\"', $filenameFallback));
        if ($filename !== $filenameFallback) {
            $output .= sprintf('; filename*=utf-8\'\'%s', rawurlencode($filename));
        }
        return $output;
    }
    protected function computeCacheControlValue()
    {
        if (!$this->cacheControl && !$this->has('ETag') && !$this->has('Last-Modified') && !$this->has('Expires')) {
            return 'no-cache';
        }
        if (!$this->cacheControl) {
            return 'private, must-revalidate';
        }
        $header = $this->getCacheControlHeader();
        if (isset($this->cacheControl['public']) || isset($this->cacheControl['private'])) {
            return $header;
        }
        if (!isset($this->cacheControl['s-maxage'])) {
            return $header . ', private';
        }
        return $header;
    }
}
namespace Symfony\Component\HttpFoundation;

class Cookie
{
    protected $name;
    protected $value;
    protected $domain;
    protected $expire;
    protected $path;
    protected $secure;
    protected $httpOnly;
    public function __construct($name, $value = null, $expire = 0, $path = '/', $domain = null, $secure = false, $httpOnly = true)
    {
        if (preg_match('/[=,; 	
]/', $name)) {
            throw new \InvalidArgumentException(sprintf('The cookie name "%s" contains invalid characters.', $name));
        }
        if (empty($name)) {
            throw new \InvalidArgumentException('The cookie name cannot be empty.');
        }
        if ($expire instanceof \DateTime) {
            $expire = $expire->format('U');
        } elseif (!is_numeric($expire)) {
            $expire = strtotime($expire);
            if (false === $expire || -1 === $expire) {
                throw new \InvalidArgumentException('The cookie expiration time is not valid.');
            }
        }
        $this->name = $name;
        $this->value = $value;
        $this->domain = $domain;
        $this->expire = $expire;
        $this->path = empty($path) ? '/' : $path;
        $this->secure = (bool) $secure;
        $this->httpOnly = (bool) $httpOnly;
    }
    public function __toString()
    {
        $str = urlencode($this->getName()) . '=';
        if ('' === (string) $this->getValue()) {
            $str .= 'deleted; expires=' . gmdate('D, d-M-Y H:i:s T', time() - 31536001);
        } else {
            $str .= urlencode($this->getValue());
            if ($this->getExpiresTime() !== 0) {
                $str .= '; expires=' . gmdate('D, d-M-Y H:i:s T', $this->getExpiresTime());
            }
        }
        if ($this->path) {
            $str .= '; path=' . $this->path;
        }
        if ($this->getDomain()) {
            $str .= '; domain=' . $this->getDomain();
        }
        if (true === $this->isSecure()) {
            $str .= '; secure';
        }
        if (true === $this->isHttpOnly()) {
            $str .= '; httponly';
        }
        return $str;
    }
    public function getName()
    {
        return $this->name;
    }
    public function getValue()
    {
        return $this->value;
    }
    public function getDomain()
    {
        return $this->domain;
    }
    public function getExpiresTime()
    {
        return $this->expire;
    }
    public function getPath()
    {
        return $this->path;
    }
    public function isSecure()
    {
        return $this->secure;
    }
    public function isHttpOnly()
    {
        return $this->httpOnly;
    }
    public function isCleared()
    {
        return $this->expire < time();
    }
}
namespace Royalcms\Component\Stack;

use Symfony\Component\HttpKernel\HttpKernelInterface;
class Builder
{
    private $specs;
    public function __construct()
    {
        $this->specs = new \SplStack();
    }
    public function unshift()
    {
        if (func_num_args() === 0) {
            throw new \InvalidArgumentException('Missing argument(s) when calling unshift');
        }
        $spec = func_get_args();
        $this->specs->unshift($spec);
        return $this;
    }
    public function push()
    {
        if (func_num_args() === 0) {
            throw new \InvalidArgumentException('Missing argument(s) when calling push');
        }
        $spec = func_get_args();
        $this->specs->push($spec);
        return $this;
    }
    public function resolve(HttpKernelInterface $royalcms)
    {
        $middlewares = array($royalcms);
        foreach ($this->specs as $spec) {
            $args = $spec;
            $firstArg = array_shift($args);
            if (is_callable($firstArg)) {
                $royalcms = $firstArg($royalcms);
            } else {
                $kernelClass = $firstArg;
                array_unshift($args, $royalcms);
                $reflection = new \ReflectionClass($kernelClass);
                $app = $reflection->newInstanceArgs($args);
            }
            array_unshift($middlewares, $royalcms);
        }
        return new StackedHttpKernel($royalcms, $middlewares);
    }
}
namespace Royalcms\Component\Stack;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
class StackedHttpKernel implements HttpKernelInterface, TerminableInterface
{
    private $royalcms;
    private $middlewares = array();
    public function __construct(HttpKernelInterface $royalcms, array $middlewares)
    {
        $this->royalcms = $royalcms;
        $this->middlewares = $middlewares;
    }
    public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        return $this->royalcms->handle($request, $type, $catch);
    }
    public function terminate(Request $request, Response $response)
    {
        $prevKernel = null;
        foreach ($this->middlewares as $kernel) {
            if (!$prevKernel instanceof TerminableInterface && $kernel instanceof TerminableInterface) {
                $kernel->terminate($request, $response);
            }
            $prevKernel = $kernel;
        }
    }
}