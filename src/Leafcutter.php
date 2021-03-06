<?php
namespace Leafcutter;

use Leafcutter\Addons\AddonProvider;
use Leafcutter\Assets\AssetProvider;
use Leafcutter\Cache\CacheProvider;
use Leafcutter\Content\ContentProvider;
use Leafcutter\DOM\DOMProvider;
use Leafcutter\Events\EventProvider;
use Leafcutter\Images\ImageProvider;
use Leafcutter\Indexer\IndexProvider;
use Leafcutter\Pages\PageProvider;
use Leafcutter\Templates\TemplateProvider;
use Leafcutter\Themes\ThemeProvider;
use Monolog\Logger;

class Leafcutter
{
    private static $instances = [];
    private $config;
    private $events;
    private $cache;
    private $content;
    private $pages;
    private $assets;
    private $images;
    private $templates;
    private $theme;
    private $dom;

    private function __construct(Config\Config $config = null, Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger('leafcutter');
        $this->config = $config ?? new Config\Config();
        $this->events = new EventProvider($this);
        $this->cache = new CacheProvider($this);
        $this->content = new ContentProvider($this);
        $this->pages = new PageProvider($this);
        $this->assets = new AssetProvider($this);
        $this->images = new ImageProvider($this);
        $this->templates = new TemplateProvider($this);
        $this->theme = new ThemeProvider($this);
        $this->dom = new DOMProvider($this);
        $this->indexer = new IndexProvider($this);
        $this->addons = new AddonProvider($this);
        $this->events()->dispatchEvent('onLeafcutterConstructed', $this);
    }

    public function indexer(): IndexProvider
    {
        return $this->indexer;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function theme(): ThemeProvider
    {
        return $this->theme;
    }

    public function addons(): AddonProvider
    {
        return $this->addons;
    }

    public function addon(string $name): ?Addons\AddonInterface
    {
        return $this->addons()->get($name);
    }

    public function find(string $path)
    {
        try {
            $url = new URL($path);
            return $this->pages()->get($url) ?? $this->assets()->get($url);
        } catch (\Throwable $th) {
            return null;
        }
    }

    public function buildResponse(URL $url, $normalizationRedirect = true): Response
    {
        // check for responses from events
        $response =
        $this->events()->dispatchFirst('onResponseURL', $url) ??
            ($url->siteNamespace() ? $this->events()->dispatchFirst('onResponseURL_namespace_' . $url->siteNamespace(), $url) : null);
        if ($response) {
            $response->setURL($url);
            return $response;
        }
        // try to build response from page
        $page = null;
        if (!$response) {
            $response = new Response();
            $response->setURL($url);
            $page = $this->pages()->get($url) ?? $this->events()->dispatchFirst('onResponsePageURL', $url) ?? $this->pages()->error($url, 404);
        }
        // normalize URL
        if ($page && $normalizationRedirect) {
            if ($bounce = URLFactory::normalizeCurrent($page->url())) {
                // URLFactory is requesting a URL normalization redirect, so we're done
                $response->redirect($bounce, 308);
                return $response;
            }
        }
        // dispatch final events and return
        $this->events()->dispatchEvent('onResponseContentReady', $response);
        if ($page) {
            $this->events()->dispatchEvent('onResponsePageReady', $page);
            $response->setSource($page);
            $this->events()->dispatchEvent('onResponsePageSet', $response);
        }
        $this->events()->dispatchEvent('onResponseTemplate', $response);
        $this->events()->dispatchEvent('onResponseReady', $response);
        $this->events()->dispatchEvent('onResponseReturn', $response);
        return $response;
    }

    public function cache(): CacheProvider
    {
        return $this->cache;
    }

    public function images(): ImageProvider
    {
        return $this->images;
    }

    public function dom(): DOMProvider
    {
        return $this->dom;
    }

    public function assets(): AssetProvider
    {
        return $this->assets;
    }

    public function templates(): TemplateProvider
    {
        return $this->templates;
    }

    public function config(string $key = null)
    {
        if ($key === null) {
            return $this->config;
        }
        return $this->config[$key];
    }

    public function events(): EventProvider
    {
        return $this->events;
    }

    public function content(): ContentProvider
    {
        return $this->content;
    }

    public function pages(): PageProvider
    {
        return $this->pages;
    }

    public function hash(): string
    {
        return hash('md5', filemtime(__DIR__) . $this->config->hash());
    }

    /**
     * Begin a new context either by optionally providing a Config object
     * or existing Leafcutter object.
     *
     * @param Leafcutter|Config $specified
     * @param Logger $logger
     * @return Leafcutter
     */
    public static function beginContext($specified = null, Logger $logger = null): Leafcutter
    {
        if ($specified instanceof Leafcutter) {
            self::$instances[] = $specified;
        } elseif ($specified instanceof Config\Config) {
            self::$instances[] = new Leafcutter($specified, $logger);
        } else {
            self::$instances[] = new Leafcutter(null, $logger);
        }
        return self::get();
    }

    public static function get(): Leafcutter
    {
        return end(self::$instances);
    }

    public static function endContext()
    {
        array_pop(self::$instances);
    }
}
