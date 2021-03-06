<?php
namespace Leafcutter\DOM;

use DOMElement;
use DOMNode;
use Leafcutter\Assets\ImageInterface;
use Leafcutter\Leafcutter;
use Leafcutter\Pages\PageContentEvent;
use Leafcutter\Response;
use Leafcutter\URL;
use Leafcutter\URLFactory;

class DOMProvider
{
    private $leafcutter;

    public function __construct(Leafcutter $leafcutter)
    {
        $this->leafcutter = $leafcutter;
        $this->leafcutter->events()->addSubscriber($this);
    }

    /**
     * This class hooks into the main control flow at the onResponseReady event,
     * which runs as the very last step before outputting HTML responses to the browser.
     *
     * @param Response $response
     * @return void
     */
    public function onResponseReady(Response $response)
    {
        if (substr($response->content(), 0, 9) != '<!doctype') {
            return;
        }
        URLFactory::beginContext($response->url());
        $response->setContent(
            $this->html($response->content())
        );
        URLFactory::endContext();
    }

    /**
     * Also hook into the finalization step of Page content generation, so that
     * links and such in page content can be handled in the correct context,
     * even if the content is going to be embedded in some other context.
     *
     * @param PageContentEvent $event
     * @return void
     */
    public function onPageGenerateContent_finalize(PageContentEvent $event)
    {
        $event->setContent(
            $this->html($event->content(), true)
        );
    }

    /**
     * Attempts to create good/proper links to existing pages/assets
     *
     * @param DOMEvent $event
     * @return void
     */
    public function onDOMElement_a(DOMEvent $event)
    {
        //verify that anchor has an href
        $a = $event->getNode();
        $url = $a->getAttribute('href');
        // if this is a link to an email address, obfuscate it
        if (substr("$url", 0, 7) == 'mailto:') {
            $a->setAttribute('data-email', null);
            $event->setReplacement($this->obfuscate($a->ownerDocument->saveHTML($a)));
            return;
        }
        // put in metadata for styling
        if ($url = new URL($url)) {
            $a->setAttribute('data-host', $url->host());
            if ($url->inSite()) {
                $a->setAttribute('data-insite', 'true');
            } else {
                $a->setAttribute('data-insite', 'false');
            }
        }
        // normalize link
        $this->prepareLinkAttribute($event, 'href');
    }

    /**
     * Add title, extension, size, and MIME attributes for styling
     * and parsing useful tidbits about asset links.
     *
     * @param DOMEvent $event
     * @return void
     */
    public function onDOMElement_a_asset(DOMEvent $event)
    {
        $a = $event->getNode();
        $asset = $event->getSource();
        if (!$a->getAttribute('title')) {
            $a->setAttribute('title', $asset->title());
        }
        $a->setAttribute('data-extension', $asset->extension());
        $a->setAttribute('data-size', $asset->size());
        $a->setAttribute('type', $asset->mime());
    }

    /**
     * Add useful HTML attributes to links to pages.
     *
     * @param DOMEvent $event
     * @return void
     */
    public function onDOMElement_a_page(DOMEvent $event)
    {
        $a = $event->getNode();
        $page = $event->getSource();
        if (!$a->getAttribute('title')) {
            $a->setAttribute('title', $page->title());
        }
    }

    /**
     * Normalize URLs to pages/assets in a given DOM node and attribute,
     * and dispatch sub-events for hooking into page/asset links in DOM.
     *
     * @param DOMEvent $event
     * @param string $urlAttribute
     * @param boolean $includePages
     * @param boolean $includeAssets
     * @return void
     */
    public function prepareLinkAttribute(DOMEvent $event, string $urlAttribute, $includePages = true, $includeAssets = true)
    {
        $node = $event->getNode();
        // try to parse URL
        $url = $node->getAttribute($urlAttribute);
        if (substr($url, 0, 5) == 'data:') {
            return;
        }
        if (!$url || !($url = new URL($url))) {
            return;
        }
        // for pages, set href and dispatch another event for page
        if ($includePages && $page = $this->leafcutter->pages()->get($url)) {
            $event->setSource($page);
            $node->setAttribute($urlAttribute, $page->url());
            $node->setAttribute('data-link-type', 'page');
            if ($ns = $page->url()->siteNamespace()) {
                $node->setAttribute('data-namespace', $ns);
            }
            if ($page->status() != 200) {
                $node->setAttribute('data-page-status', $page->status());
            }
            $this->leafcutter->events()->dispatchEvent(
                'onDOMElement_' . $node->tagName . '_page',
                $event
            );
            return;
        }
        // for assets, set href and dispatch another event for asset
        if ($includeAssets && $asset = $this->leafcutter->assets()->get($url)) {
            // dispatch event for all asset types
            $node->setAttribute('data-link-type', 'asset');
            if ($ns = $asset->url()->siteNamespace()) {
                $node->setAttribute('data-namespace', $ns);
            }
            $event->setSource($asset);
            $this->leafcutter->events()->dispatchEvent(
                'onDOMElement_' . $node->tagName . '_asset',
                $event
            );
            // dispatch an additional event when source is an image
            if ($event->getSource() instanceof ImageInterface) {
                $node->setAttribute('data-link-type', 'image');
                $this->leafcutter->events()->dispatchEvent(
                    'onDOMElement_' . $node->tagName . '_image',
                    $event
                );
            }
            // update link attribute and return
            $node->setAttribute($urlAttribute, $asset->publicUrl());
            return;
        }
    }

    /**
     * Convert a given HTML string into an obfuscating Javascript.
     *
     * @param string $html
     * @return string
     */
    protected function obfuscate(string $html): string
    {
        $html = \base64_encode($html);
        return '<script>document.write(atob("' . $html . '"));</script><noscript>[js required]</noscript>';
    }

    /**
     * Turn a given HTML string into one that has been processed.
     *
     * @param string $html
     * @param bool $fragment
     * @return string
     */
    protected function html(string $html, bool $fragment = false): string
    {
        $html = $this->leafcutter->events()->dispatchAll('onDOMProcess', $html);

        // set up DOMDocument
        $dom = new \DOMDocument();
        if (!@$dom->loadHTML($html, \LIBXML_NOERROR & \LIBXML_NOWARNING & \LIBXML_NOBLANKS)) {
            return $html;
        }
        // dispatch events
        $this->dispatchEvents($dom, $fragment ? 'fragment' : 'full');

        //normalize and output to HTML
        $dom->normalizeDocument();
        if (!$fragment) {
            $html = $dom->saveHTML();
        } else {
            $html = $this->bodyOnly($dom);
            if ($html === null) {
                $html = $dom->saveHTML();
            }
        }
        //fix self-closing tags that aren't actually allowed to self-close in HTML
        $html = preg_replace('@(<(a|script|noscript|table|iframe|noframes|canvas|style)[^>]*)/>@ims', '$1></$2>', $html);
        //fix non-self-closing tags that are supposed to self-close
        $html = preg_replace('@(<(source)[^>]*)></\2>@ims', '$1 />', $html);
        // return after passing through another hook
        return $this->leafcutter->events()->dispatchAll('onDOMReady', $html);
    }

    protected function bodyOnly(DOMNode $dom): ?string
    {
        if ($dom instanceof DOMElement) {
            if ($dom->tagName == 'body') {
                $out = '';
                foreach ($dom->childNodes as $c) {
                    $out .= $dom->ownerDocument->saveHTML($c);
                }
                return $out;
            }
        }
        foreach ($dom->childNodes ?? [] as $c) {
            if ($out = $this->bodyOnly($c)) {
                return $out;
            }
        }
        return null;
    }

    public function onDOMComment(DOMEvent $event)
    {
        $node = $event->getNode();
        if (preg_match('/^@beginContext:(.+)$/', trim($node->data), $matches)) {
            URLFactory::beginContext(new URL(trim($matches[1])));
            // $event->setDelete(true);
        }
        if (trim($node->data) == '@endContext') {
            URLFactory::endContext();
            // $event->setDelete(true);
        }
    }

    /**
     * Dispatch events on a given DOM node, recurse into children.
     *
     * @param \DOMNode $node
     * @return void
     */
    protected function dispatchEvents(\DOMNode $node, string $phase)
    {
        $context = null;
        //pick event name if applicable
        $eventNames = [];
        if ($node instanceof \DOMElement) {
            //skip events on elements with data-leafcutter-dom-events="off"
            if ($node->getAttribute('data-leafcutter-dom-events') == 'off') {
                return;
            }
            //set context from HTML
            if ($context = $node->getAttribute('data-url-context')) {
                URLFactory::beginContext(new URL($context));
            }
            //onDOMElement_{tagname} event name
            $eventNames[] = 'onDOMElement_' . $node->tagName;
            $eventNames[] = 'onDOMElement_' . $node->tagName . '_' . $phase;
        } elseif ($node instanceof \DOMComment) {
            //onDOMComment event name
            $eventNames[] = 'onDOMComment';
            $eventNames[] = 'onDOMComment_' . $phase;
        } elseif ($node instanceof \DOMText) {
            $eventNames[] = 'onDOMText';
            $eventNames[] = 'onDOMText_' . $phase;
        }
        //dispatch event if necessary
        foreach ($eventNames as $eventName) {
            $event = $this->leafcutter->events()->dispatchEvent($eventName, new DOMEvent($node));
            //do deletion if event calls for it
            if ($event->getDelete()) {
                $node->parentNode->removeChild($node);
            }
            //else do replacement if event calls for it
            elseif ($html = $event->getReplacement()) {
                $newNode = $node->ownerDocument->createDocumentFragment();
                @$newNode->appendXML($html);
                $node->parentNode->replaceChild($newNode, $node);
                $node = $newNode;
                $this->dispatchEvents($newNode, $phase);
            }
        }
        //recurse into children if found
        if ($node && $node->hasChildNodes()) {
            //build an array of children, disconnected from childNodes object
            //we need to do this so we can replace them without breaking the
            //order and total coverage of looping through them
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            //loop through new array of child nodes
            foreach ($children as $child) {
                $this->dispatchEvents($child, $phase);
            }
        }
        //end context from HTML
        if ($context) {
            URLFactory::endContext();
        }
    }
}
