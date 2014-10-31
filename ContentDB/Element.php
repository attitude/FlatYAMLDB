<?php

namespace attitude\FlatYAMLDB;

use \attitude\FlatYAMLDB_Element;

use \attitude\Elements\HTTPException;
use \attitude\Elements\DependencyContainer;

class ContentDB_Element extends FlatYAMLDB_Element
{
    /**
     * @var string $instanceType Please provide custom namespace for your class
     */
    protected $instanceType = 'content';

    protected function addData($data)
    {
        if (isset($data['id']) && isset($data['type'])) {
            $this->data[$data['type'].'.'.$data['id']] = $data;
        }

        return $this;
    }

    protected function createDBIndex($final = false)
    {
        $index_keys = array();

        // Allow to set internal/private keys and behave as defaults
        foreach ((array) $this->index_keys as $k) {
            $index_keys[] = $k;           // Always public
            $index_keys[] = '_'.$k;       // Internal, maybe public (internal loops etc.)
            $index_keys[] = '__'.$k.'__'; // Internal, never public
        }

        foreach ($this->data as $array_index =>& $document) {
            if ($final) {
                if (!isset($document['$$filePath$$'])) {
                    throw new HTTPException(500, 'Unexpected: Script generated $$filePath$$ attribute is missing.');
                }

                $filePath = $document['$$filePath$$'];
                unset($document['$$filePath$$']);

                $this->addIndex('$$filePath$$', $filePath, $array_index);
            }

            $id   = @$document['id']   ? $document['id']   : false;
            $type = @$document['type'] ? $document['type'] : false;

            if (!$id || !$type) {
                continue;
            }

            foreach ((array) $index_keys as $index_key) {
                if (isset($document[$index_key])) {
                    $data =& $document[$index_key];
                    if(is_array($data)) {
                        foreach ($data as &$subdata) {
                            if (! is_array($subdata)) {
                                $this->addIndex(trim($index_key, '_'), $subdata, $type.'.'.$id);
                            }
                        }
                    } else {
                        $this->addIndex(trim($index_key, '_'), $data, $type.'.'.$id);
                    }
                }
            }
        }

        return $this;
    }

    private function linkToData($data)
    {
        if (is_assoc_array($data)) {
            return $this->linkToItem($data);
        }

        $links = array();
        foreach ($data as $subdata) {
            $links[] = $this->linkToItem($subdata);
        }

        return $links;
    }

    private function stripLanguageURI($uri)
    {
        $language = DependencyContainer::get('global::language');

        return preg_replace("|^/{$language['code']}|", '', $uri);
    }

    public function linkHelperIsHome($uri)
    {
        return $this->stripLanguageURI($uri) === '/';
    }

    public function linkHelperIsActive($uri)
    {
        $uri = $this->stripLanguageURI($uri);

        return !$this->linkHelperIsHome($uri) && strstr(rtrim($this->stripLanguageURI($_SERVER['REQUEST_URI']), '/'), rtrim($uri, '/'));
    }

    public function linkHelperIsCurrent($uri)
    {
        return rtrim($this->stripLanguageURI($uri), '/') === rtrim($this->stripLanguageURI($_SERVER['REQUEST_URI']), '/');
    }

    public function linkToItem($data)
    {
        $result = array();

        if (isset($data['navigationTitle'])) {
            $result['text'] = $data['navigationTitle'];
        } elseif (isset($data['title'])) {
            $result['text'] = $data['title'];
        } else {
            $result['text'] = '';
        }

        $result['href'] = $this->hrefToItem($data);

        if (isset($data['title'])) {
            $result['title'] = $data['title'];
        }

        return $result;
    }

    public function hrefToItem($data)
    {
        if (isset($data['href'])) {
            return $data['href'];
        }

        $href = array();

        if (isset($data['route'])) {
            $language = DependencyContainer::get('global::language');

            if ($language) {
                if (is_string($data['route'])) {
                    $href = "/{$language['code']}".$data['route'];
                } else {
                    foreach ($data['route'] as $k => &$v) {
                        $href[$k] = "/{$language['code']}".$v;
                    }
                }
            }

            return $href;
        }

        return '#missingroute';
    }

    public function expanderLink($args)
    {
        try {
            $data = $this->query($args);

            return $this->linkToData($data);
        } catch (HTTPException $e) {
            throw $e;
        }

        throw new HTTPException(404, 'Failed to expand link().');
    }

    /**
     * Parses arguments and returns result of query
     *
     * Use $$meta to return metadata, otherwise nothing starting with '_' such
     * as `_id` or `_type` will be returned.
     *
     * @param array $args Array of query arguments
     * @return mixed
     *
     */
    public function expanderQuery($args)
    {
        // $this->query() expects array of queries
        if (is_assoc_array($args)) {
            $args = array($args);
        }

        $results = array();

        try {
            foreach($args as $subargs) {
                $meta = false;

                if (array_key_exists('$$meta', $subargs)) {
                    $meta = !! $subargs['$$meta'];

                    unset($subargs['$$meta']);
                }

                $subresults = $this->query($subargs, $meta);
                $results = array_merge($results, $subresults);
            }
        } catch (HTTPException $e) {
            throw $e;
        }

        if (!empty($results)) {
            return $results;
        }

        throw new HTTPException(404, 'Failed to expand query().');
    }

    public function expanderHref($args)
    {
        try {
            $data = $this->query($args);

            return $this->hrefToItem($data);
        } catch (HTTPException $e) {
            throw $e;
        }

        throw new HTTPException(404, 'Failed to expand href().');
    }

    public function expanderTitle($args)
    {
        try {
            $data = $this->query($args);

            if (isset($data['title'])) {
                return $data['title'];
            }

            if (isset($data['navigationTitle'])) {
                return $data['navigationTitle'];
            }

            return 'N/A';
        } catch (HTTPException $e) {
            throw $e;
        }

        throw new HTTPException(404, 'Failed to expand href().');
    }

    /**
     * Looks-up the children and appends them to the item
     *
     * @param  array $item The database item
     * @return array       Modified item
     *
     */
    private function queryChildren($item, $metadata = false) {
        $id   = isset($item['id'])   ? $item['id']   : false;
        $type = isset($item['type']) ? $item['type'] : false;

        if (!$id) {
            return array();
        }

        $links = array();

        try {
            $children = $this->query(array('collection' => $id), true);

            foreach ($children as &$child) {
                if ($child['type']) {
                    // Create the key and remember it should not be skipped later
                    if (!array_key_exists($child['type'], $links)) {
                        $links[$child['type']] = array();
                    }

                    // Remove metadata
                    if (!$metadata) {
                        // Remove metadata
                        foreach ($child as $k => &$v) {
                            if ($k[0]==='_') {
                                unset($child[$k]);
                            }
                        }
                    }

                    $links[$child['type']][] = $child;
                } else {
                    trigger_error('Missing `_type` for object '.json_encode($child));
                }
            }
        } catch (HTTPException $e) {/* Silence */}

        return $links;
    }

    /**
     * Returs current resource and it's linked data
     *
     * @see http://jsonapi.org/format/
     *
     * @param string $uri Route of resource
     * @return array      Resource data
     *
     */
    public function getCollection($uri = '/')
    {
        // In most cases we look for a collection: an archive or parent page,
        // listing of related items
        try {
            $data = $this->query(array(
                'type' => "collection",
                'route' => $uri,
                '_limit' => 1
            ), true);
        } catch (HTTPException $e) {
            // But maybe we're about to display one of the items
            $data = $this->query(array(
                'route' => $uri,
                '_limit' => 1
            ), true);
        }

        $id   = @$data['id']   ? $data['id']   : false;
        $type = @$data['type'] ? $data['type'] : false;

        $result = array(
            // The primary resource(s) SHOULD be keyed either by their resource
            // type or the generic key "data".
            'data' =>& $data,

            // Meta-information about a resource, such as pagination.
            'meta'  => array(
                /* Example:
                'website'         => null,
                'shoppingCart'    => null,
                'template'        => null,
                'showCart'        => null,
                'pagination'      => null,
                'calendarView'    => null,
                'websiteSettings' => null
                */
            ),

            // A collection of resource objects, grouped by type, that are linked
            // to the primary resource(s) and/or each other (i.e. "linked resource(s)")
            'linked' => array(
                /* Example:
                'collections' => array(),
                'products'    => array(),
                'authors'     => array(),
                'posts'       => array(),
                'comments'    => array()
                */
            ),

            // URL templates to be used for expanding resources' relationships URLs
            'links' => array(
                /* Example:
                "posts.comments": "http://example.com/comments?posts={posts.id}"
                */
            ),
        );

        // 1/ Fill the website info (homepage)
        // 1a/ Current resource is homepage
        try {
            $website = $this->query(array('_limit' => 1, 'type' => 'website'));
        } catch (HTTPException $e) {
            throw new HTTPException(500, 'There is no website object.');
        }

        $result['meta']['website'] =& $website;

        // 2/ Find all linked resources
        $data['links'] = $this->queryChildren($data);

        // 3/ Let's find out parent collection...
        if (isset($data['collection'])) {
            try {
                $collection = $this->query(array('type' => 'collections', 'id' => $data['collection']));
                $collection['links'] = $this->queryChildren($collection);

                $data['links']['collection'] =& $collection;
            } catch (HTTPException $e) {
                trigger_error('Item has collection defined but is missing: '.json_encode(array('type' => 'collections', 'id' => $data['collection'])));
                throw new HTTPException(404, 'Item has collection defined but is missing.');
            }
        }

        // 4/ Add Breadcrumbs
        if (!isset($result['meta']['breadcrumbs'])) {
            $result['meta']['breadcrumbs'] = $this->generateBreadcrumbs(array('type' => $type, 'id' => $id));
        }

        // 5/ Add Title
        $result['meta']['title'] = array();

        foreach ( array_reverse($result['meta']['breadcrumbs']) as &$breadCrumb) {
            $result['meta']['title'][] = $breadCrumb['title'];
        }

        // 6/ Add languages available
        try {
            $result['meta']['languages'] = $this->query(array('type' => 'language', 'published' => true));
        } catch (HTTPException $e) {/* Silence */}

        // 7/ Add homepage as linked resource
        try {
            $homepage = $this->query(array('route' => '/', '_limit' => 1));

            $result['meta']['homepage'] =& $homepage;

            try {
                $homepage['links'] = $this->queryChildren($homepage);
            } catch(HTTPException $e) {/* Silence */}
        } catch(HTTPException $e) {
            throw HTTPException('There must be a homepage defined under `/` route.');
        }

        return $result;
    }

    public function generateBreadcrumbs($args, $children = false, $levels = 0)
    {
        static $traverse = 0;

        $traverse++;

        $breadcrumbs = array();
        try {
            $item = $this->query($args, true);

            if (isset($item['route'])) {
                $breadcrumbs[] = $this->linkToData($item);

                if (isset($item['collection'])) {
                    $breadcrumbs = array_merge($breadcrumbs, $this->generateBreadcrumbs(array('type' => 'collections', 'id' => $item['collection'])));
                }
            }
        } catch (HTTPException $e) {/* Silence */}

        $traverse--;

        if ($traverse==0) {
            return array_reverse($breadcrumbs);
        }

        return $breadcrumbs;
    }
}
