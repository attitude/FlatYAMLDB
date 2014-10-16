<?php

namespace attitude\FlatYAMLDB;

use \attitude\FlatYAMLDB_Element;

use \attitude\Elements\HTTPException;
use \attitude\Elements\DependencyContainer;

class ContentDB_Element extends FlatYAMLDB_Element
{
    protected function addData($data)
    {
        if (isset($data['_id']) && isset($data['_type'])) {
            $this->data[$data['_type'].'.'.$data['_id']] = $data;
        }

        return $this;
    }

    protected function createDBIndex()
    {
        foreach ($this->data as $document) {
            if (!isset($document['_id']) || !isset($document['_type'])) {
                continue;
            }

            foreach ((array) $this->index_keys as $index_key) {
                if ($index_key==='_id') {
                    continue;
                }

                if (isset($document[$index_key])) {
                    $data =& $document[$index_key];
                    if(is_array($data)) {
                        foreach ($data as &$subdata) {
                            if (! is_array($subdata)) {
                                $this->addIndex($index_key, $subdata, $document['_type'].'.'.$document['_id']);
                            }
                        }
                    } else {
                        $this->addIndex($index_key, $data, $document['_type'].'.'.$document['_id']);
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

    private function linkToItem($data)
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

        foreach ((array) $result['href'] as $url) {
            if ($url===$_SERVER['REQUEST_URI']) {
                $result['current'] = true;

                break;
            }
        }

        if (isset($data['title'])) {
            $result['title'] = $data['title'];
        }

        return $result;
    }

    public function hrefToItem($data)
    {
        if (isset($data['route'])) {
            $language = DependencyContainer::get('global::language');

            if ($language) {
                if (is_string($data['route'])) {
                    $data['route'] = "/{$language['code']}".$data['route'];
                } else {
                    foreach ($data['route'] as &$v) {
                        $v = "/{$language['code']}".$v;
                    }
                }
            }

            return $data['route'];
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

    public function getCollection($uri = '/')
    {
        // In most cases we look for a collection: an archive or parent page,
        // listing of related items
        try {
            $data = $this->query(array(
                '_type' => "collection",
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

        $result = array(
            'website'         => null,
            'collection'      => null,
            'item'            => $data,
            'items'           => null,
            'shoppingCart'    => null,
            'showCart'        => null,
            'template'        => null,
            'pagination'      => null,
            'websiteSettings' => null,
            'calendarView'    => null,
        );

        // Walk item data
        foreach ($data as $k => &$v) {
            // Expect value to be on root:
            if (array_key_exists($k, $result)) {
                $result[$k] = $v;
            } else {
                // Ex: _type: blogpost, _collection: blog
                if ($data['_type']==='item') {
                    $result['item'][$k] = $v;
                } else {
                    // _type: collection
                    $result['item'][$k] = $v;
                    $result['collection'][$k] = $v;
                }
            }
        }

        // Fill the website info with the highest level collection (homepage)
        if (!isset($result['website'])) {
            try {
                $result['website'] = $this->query(array('_limit' => 1, '_type' => 'collection', 'route' => '/'));
            } catch (HTTPException $e) {
                throw new HTTPException(500, 'Homepage is missing. There is no root object.');
            }
        }

        if (isset($result['_type'])) {
            // Has parent collection defined
            if ($result['_type'] === 'homepage') {
                $result['collection'] = $result['item'];
            } elseif ($result['_type'] !== 'collection') {
                // Look up higher level collection
                if (isset($result['item']['_collection'])) {
                    try {
                        $result['collection'] = $this->query(array('_type' => 'collection', '_id' => $result['item']['_collection']), true);
                    } catch (HTTPException $e) {
                        throw new HTTPException(404, 'Item has collection defined but is missing.');
                    }
                } else {
                    // Set empty
                    $result['collection'] = $result['website'];
                }
            } else {
                // Collection is the Item
                $result['collection'] = $result['item'];
            }
        }

        // Try to find sub-items
        if (!isset($result['items'])) {
            try {
                $items = $this->query(array('_collection' => $data['_id']), true);

                foreach ($items as &$item) {
                    if (isset($item['_type'])) {
                        $camelCasePlural = $this->pluralize(lcfirst(ucwords(str_replace('_', ' ', $item['_type']))));

                        if (!array_key_exists($camelCasePlural, $result)) {
                            $result[$camelCasePlural] = array();
                        }

                        $result[$camelCasePlural][] = $item;
                    } else {
                        trigger_error('Missing `_type` for object '.json_encode($item));
                    }
                }

                foreach ($result['items'] as &$item) {
                    $item['link'] = $this->linkToData($item);
                }
            } catch (HTTPException $e) {
                /* No items */
            }
        }

        if (!isset($result['collection']['breadcrumbs'])) {
            if ($result['item']['_type'] === 'collection') {
                $result['collection']['breadcrumbs'] = $this->generateBreadcrumbs(array('_type' => $result['item']['_type'], '_id' => $result['item']['_id']));
            } else {
                $result['collection']['breadcrumbs'] = $this->generateBreadcrumbs(array('_type' => $result['item']['_type'], '_id' => $result['item']['_id']));
            }
        }

        $result['website']['title'] = array();

        foreach ( array_reverse($result['collection']['breadcrumbs']) as &$breadCrumb) {
            $result['website']['title'][] = $breadCrumb['title'];
        }

        return $result;
    }

    /**
    * Pluralizes English nouns.
    *
    * Source: http://www.akelos.com
    *
    * @access public
    * @static
    * @param  string $word English noun to pluralize
    * @return string       Plural noun
    */
    private function pluralize($word)
    {
        $plural = array(
            '/(quiz)$/i' => '1zes',
            '/^(ox)$/i' => '1en',
            '/([m|l])ouse$/i' => '1ice',
            '/(matr|vert|ind)ix|ex$/i' => '1ices',
            '/(x|ch|ss|sh)$/i' => '1es',
            '/([^aeiouy]|qu)ies$/i' => '1y',
            '/([^aeiouy]|qu)y$/i' => '1ies',
            '/(hive)$/i' => '1s',
            '/(?:([^f])fe|([lr])f)$/i' => '12ves',
            '/sis$/i' => 'ses',
            '/([ti])um$/i' => '1a',
            '/(buffal|tomat)o$/i' => '1oes',
            '/(bu)s$/i' => '1ses',
            '/(alias|status)/i'=> '1es',
            '/(octop|vir)us$/i'=> '1i',
            '/(ax|test)is$/i'=> '1es',
            '/s$/i'=> 's',
            '/$/'=> 's'
        );

        $uncountable = array('equipment', 'information', 'rice', 'money', 'species', 'series', 'fish', 'sheep');
        $irregular = array('person' => 'people', 'man' => 'men', 'child' => 'children', 'sex' => 'sexes', 'move' => 'moves');
        $lowercased_word = strtolower($word);

        foreach ($uncountable as $_uncountable){
            if(substr($lowercased_word,(-1*strlen($_uncountable))) == $_uncountable){
                return $word;
            }
        }

        foreach ($irregular as $_plural=> $_singular){
            if (preg_match('/('.$_plural.')$/i', $word, $arr)) {
                return preg_replace('/('.$_plural.')$/i', substr($arr[0],0,1).substr($_singular,1), $word);
            }
        }

        foreach ($plural as $rule => $replacement) {
            if (preg_match($rule, $word)) {
                return preg_replace($rule, $replacement, $word);
            }
        }

        return false;
    }

    public function generateBreadcrumbs($args, $children = false, $levels = 0)
    {
        static $traverse = 0;

        $traverse++;

        $breadcrumbs = array();
        try {
            $item = $this->query($args, true);

            $breadcrumbs[] = $this->linkToData($item);

            if (isset($item['_collection'])) {
                $breadcrumbs = array_merge($breadcrumbs, $this->generateBreadcrumbs(array('_type' => 'collection', '_id' => $item['_collection'])));
            }
        } catch (HTTPException $e) {
        }
        $traverse--;

        if ($traverse==0) {
            $breadcrumbs[(sizeof($breadcrumbs)-1)]['home'] = true;
            $breadcrumbs[0]['current'] = true;

            return array_reverse($breadcrumbs);
        }

        return $breadcrumbs;
    }
}
