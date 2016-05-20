<?php

namespace Mouf\Mvc\Splash\Routers;

use Cache\Adapter\Void\VoidCachePool;
use Interop\Container\ContainerInterface;
use Mouf\Mvc\Splash\Services\ParameterFetcher;
use Mouf\Mvc\Splash\Services\ParameterFetcherRegistry;
use Mouf\Mvc\Splash\Services\SplashRequestParameterFetcher;
use Mouf\Mvc\Splash\Services\UrlProviderInterface;
use Mouf\Mvc\Splash\Utils\SplashException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Mouf\Utils\Cache\CacheInterface;
use Mouf\MoufManager;
use Mouf\Mvc\Splash\Store\SplashUrlNode;
use Psr\Log\LoggerInterface;
use Mouf\Mvc\Splash\Controllers\WebServiceInterface;
use Mouf\Mvc\Splash\Services\SplashRequestContext;
use Mouf\Mvc\Splash\Services\SplashUtils;
use Psr\Log\NullLogger;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Stratigility\MiddlewareInterface;

class SplashDefaultRouter implements MiddlewareInterface
{
    /**
     * The container that will be used to fetch controllers.
     *
     * @var ContainerInterface
     */
    private $container;

    /**
     * List of objects that provide routes.
     *
     * @var UrlProviderInterface[]
     */
    private $routeProviders = [];

    /**
     * The logger used by Splash.
     *
     * @var LoggerInterface
     */
    private $log;

    /**
     * Splash uses the cache service to store the URL mapping (the mapping between a URL and its controller/action).
     *
     * @var CacheItemPoolInterface
     */
    private $cachePool;

    /**
     * The default mode for Splash. Can be one of 'weak' (controllers are allowed to output HTML), or 'strict' (controllers
     * are requested to return a ResponseInterface object).
     *
     * @var string
     */
    private $mode;

    /**
     * In debug mode, Splash will display more accurate messages if output starts (in strict mode)
     *
     * @var bool
     */
    private $debug;

    /**
     * @var ParameterFetcher[]
     */
    private $parameterFetcherRegistry;

    /**
     * The base URL of the application (from which the router will start routing).
     * @var string
     */
    private $rootUrl;

    /**
     * @Important
     *
     * @param ContainerInterface $container The container that will be used to fetch controllers.
     * @param UrlProviderInterface[] $routeProviders
     * @param ParameterFetcherRegistry $parameterFetcherRegistry
     * @param CacheItemPoolInterface $cachePool Splash uses the cache service to store the URL mapping (the mapping between a URL and its controller/action)
     * @param LoggerInterface $log The logger used by Splash
     * @param string $mode The default mode for Splash. Can be one of 'weak' (controllers are allowed to output HTML), or 'strict' (controllers are requested to return a ResponseInterface object).
     * @param bool $debug In debug mode, Splash will display more accurate messages if output starts (in strict mode)
     * @param string $rootUrl
     */
    public function __construct(ContainerInterface $container, array $routeProviders, ParameterFetcherRegistry $parameterFetcherRegistry, CacheItemPoolInterface $cachePool = null, LoggerInterface $log = null, $mode = SplashUtils::MODE_STRICT, $debug = true, $rootUrl = '/')
    {
        $this->container = $container;
        $this->routeProviders = $routeProviders;
        $this->parameterFetcherRegistry = $parameterFetcherRegistry;
        $this->cachePool = $cachePool === null ? new VoidCachePool() : $cachePool;
        $this->log = $log === null ? new NullLogger() : $log;
        $this->mode = $mode;
        $this->debug = $debug;
        $this->rootUrl = $rootUrl;
    }

    /**
     * Process an incoming request and/or response.
     *
     * Accepts a server-side request and a response instance, and does
     * something with them.
     *
     * If the response is not complete and/or further processing would not
     * interfere with the work done in the middleware, or if the middleware
     * wants to delegate to another process, it can use the `$out` callable
     * if present.
     *
     * If the middleware does not return a value, execution of the current
     * request is considered complete, and the response instance provided will
     * be considered the response to return.
     *
     * Alternately, the middleware may return a response instance.
     *
     * Often, middleware will `return $out();`, with the assumption that a
     * later middleware will return a response.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @param null|callable          $out
     *
     * @return null|ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $out = null)
    {
        $urlNodesCacheItem = $this->cachePool->getItem('splashUrlNodes');
        if (!$urlNodesCacheItem->isHit()) {
            // No value in cache, let's get the URL nodes
            $urlsList = $this->getSplashActionsList();
            $urlNodes = $this->generateUrlNode($urlsList);
            $urlNodesCacheItem->set($urlNodes);
            $this->cachePool->save($urlNodesCacheItem);
        }

        $urlNodes = $urlNodesCacheItem->get();

        $request_path = $request->getUri()->getPath();

        $pos = strpos($request_path, $this->rootUrl);
        if ($pos === false) {
            throw new SplashException('Error: the prefix of the web application "'.$this->rootUrl.'" was not found in the URL. The application must be misconfigured. Check the ROOT_URL parameter in your config.php file at the root of your project. It should have the same value as the RewriteBase parameter in your .htaccess file. Requested URL : "'.$request_path.'"');
        }

        $tailing_url = substr($request_path, $pos + strlen($this->rootUrl));

        $context = new SplashRequestContext($request);
        $splashRoute = $urlNodes->walk($tailing_url, $request);

        if ($splashRoute === null) {
            // No route found. Let's try variants with or without trailing / if we are in a GET.
            if ($request->getMethod() === 'GET') {
                // If there is a trailing /, let's remove it and retry
                if (strrpos($tailing_url, '/') === strlen($tailing_url)-1) {
                    $url = substr($tailing_url, 0, -1);
                    $splashRoute = $urlNodes->walk($url, $request);
                } else {
                    $url = $tailing_url.'/';
                    $splashRoute = $urlNodes->walk($url, $request);
                }
                
                if ($splashRoute !== null) {
                    // If a route does match, let's make a redirect.
                    return new RedirectResponse($this->rootUrl.$url);
                }
            }

            $this->log->debug('Found no route for URL {url}.', [
                'url' => $request_path,
            ]);

            // No route found, let's pass control to the next middleware.
            return $out($request, $response);
        }

        $controller = $this->container->get($splashRoute->controllerInstanceName);
        $action = $splashRoute->methodName;

        $context->setUrlParameters($splashRoute->filledParameters);

        $this->log->debug('Routing URL {url} to controller instance {controller} and action {action}', [
            'url' => $request_path,
            'controller' => $splashRoute->controllerInstanceName,
            'action' => $action,
        ]);

        // Let's pass everything to the controller:
        $args = $this->parameterFetcherRegistry->toArguments($context, $splashRoute->parameters);

        $filters = $splashRoute->filters;

        // Apply filters
        for ($i = count($filters) - 1; $i >= 0; --$i) {
            $filters[$i]->beforeAction();
        }

        $response = SplashUtils::buildControllerResponse(
            function () use ($controller, $action, $args) {
                return call_user_func_array(array($controller, $action), $args);
            },
            $this->mode,
            $this->debug
        );

        foreach ($filters as $filter) {
            $filter->afterAction();
        }

        return $response;
    }

    /**
     * Returns the list of all SplashActions.
     * This call is LONG and should be cached.
     *
     * @return array<SplashAction>
     */
    public function getSplashActionsList()
    {
        $urls = array();

        foreach ($this->routeProviders as $routeProvider) {
            /* @var $routeProvider UrlProviderInterface */
            $tmpUrlList = $routeProvider->getUrlsList(null);
            $urls = array_merge($urls, $tmpUrlList);
        }

        return $urls;
    }

    /**
     * Generates the URLNodes from the list of URLS.
     * URLNodes are a very efficient way to know whether we can access our page or not.
     *
     * @param array<SplashAction> $urlsList
     *
     * @return SplashUrlNode
     */
    private function generateUrlNode($urlsList)
    {
        $urlNode = new SplashUrlNode();
        foreach ($urlsList as $splashAction) {
            $urlNode->registerCallback($splashAction);
        }

        return $urlNode;
    }

    /**
     * Purges the urls cache.
     */
    public function purgeUrlsCache()
    {
        $this->cachePool->deleteItem('splashUrlNodes');
    }
}
