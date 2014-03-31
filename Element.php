<?php

namespace attitude;

use \Symfony\Component\Yaml\Yaml;
use \attitude\Elements\HTTPException;
use \attitude\Elements\Singleton_Prototype;
use \attitude\Elements\DependencyContainer;

class FlatYAMLDB_Element extends Singleton_Prototype
{
    private $data = array();
    private $indexes = array();

    protected function __construct()
    {
        if (! DependencyContainer::get('yamldb::cache') || $this->cacheNeedsReload()) {
            header('X-Using-DB-Cache: false');

            $this->loadYAML();
        } else {
            header('X-Using-DB-Cache: true');

            $this->loadCache();
        }

        return $this;
    }

    protected function loadYAML()
    {
        $db = explode('...', file_get_contents(DependencyContainer::get('yamldb::source')));

        foreach ($db as $document) {
            $document = trim($document);

            if (strlen($document)===0) {
                continue;
            }

            $document = $document."\n...";
            $data = Yaml::parse($document);
            if (isset($data['_id']) && isset($data['_type'])) {
                $this->data[$data['_type'].'.'.$data['_id']] = $data;
            }
        }

        $this->createDBIndex();
        $this->storeCache();

        return $this;
    }

    protected function createDBIndex()
    {
        foreach ($this->data as $document) {
            if (!isset($document['_id']) || !isset($document['_type'])) {
                continue;
            }

            foreach ((array)DependencyContainer::get('yamldb::indexes', array()) as $index) {
                if ($index==='_id') {
                    continue;
                }

                if (isset($document[$index])) {
                    $data =& $document[$index];
                    if(is_array($data)) {
                        foreach ($data as &$subdata) {
                            if (! is_array($subdata)) {
                                $this->addIndex($index, $subdata, $document['_type'].'.'.$document['_id']);
                            }
                        }
                    } else {
                        $this->addIndex($index, $data, $document['_type'].'.'.$document['_id']);
                    }
                }
            }
        }

        return $this;
    }

    protected function addIndex($index, $key, $value)
    {
        if (!isset($this->indexes[$index])
         || !isset($this->indexes[$index][$key])
         || !in_array($value, $this->indexes[$index][$key])
        ) {
            $this->indexes[$index][$key][] = $value;
        }

        return $this;
    }

    protected function searchIndex($index, $value)
    {
        if ($index==='_id' && isset($this->data[$value])) {
            return (array) $value;
        }

        if (isset($this->indexes[$index][$value])) {
            return $this->indexes[$index][$value];
        }

        return array();
    }

    public function query($query, $keep_metadata = false)
    {
        $limit  = (isset($query['_limit']))  ? $query['_limit']  : 0;
        $offset = (isset($query['_offset'])) ? $query['_offset'] : 0;

        unset($query['_limit'], $query['_offset']);

        if (isset($query['_id'])) {
            if (!isset($query['_type'])) {
                throw new HTTPException(500, 'Querying by id requires passing type');
            }

            $query['_id'] = $query['_type'].'.'.$query['_id'];
        }

        foreach($query as $search => $value) {
            $subsets[] = $this->searchIndex($search, $value);
        }

        $intersection = null;

        foreach ($subsets as $subset) {
            if (empty($subset)) {
                $intersection = array();

                break;
            }

            if ($intersection == null) {
                $intersection = $subset;

                continue;
            }

            $intersection = array_intersect($intersection, $subset);
        }

        $results = array();

        foreach (array($intersection) as $ids) {
            foreach ($ids as $id) {
                $result =& $this->data[$id];

                if (!isset($result['link']) && isset($result['route']) && isset($result['_type']) && isset($result['_id'])) {
                    $result['link'] = array('link()' => array('_type' => $result['_type'], '_id' => $result['_id']));
                }

                if ($keep_metadata) {
                    $results[] = $this->data[$id];
                } else {
                    $data = $this->data[$id];

                    foreach ($data as $k => &$v) {
                        if ($k[0]==='_') {
                            unset($data[$k]);
                        }
                    }

                    $results[] = $data;
                }
            }
        }

        if (empty($results)) {
            throw new HTTPException(404, 'Your query returned zero results');
        }

        if (isset($query['_id']) || $limit===1) {
            return $results[0];
        }

        return $results;
    }

    protected function loadCache()
    {
        return $this;
    }

    protected function storeCache()
    {
        file_put_contents(DependencyContainer::get('yamldb::cache.source'), json_encode(array(
            'indexes' => $this->indexes,
            'data'    => $this->data
        ), JSON_PRETTY_PRINT));

        return $this;
    }

    protected function cacheNeedsReload()
    {
        if (! file_exists(DependencyContainer::get('yamldb::cache.source'))) {
            return true;
        }

        if (filemtime(DependencyContainer::get('yamldb::cache.source')) < filemtime(DependencyContainer::get('yamldb::source'))) {
            return true;
        }

        return false;
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
        $language = DependencyContainer::get('global::language', null);

        if (isset($data['navigationTitle'])) {
            $result['text'] = $data['navigationTitle'];
        } elseif (isset($data['title'])) {
            $result['text'] = $data['title'];
        } else {
            $result['text'] = '';
        }

        if (isset($data['route'])) {
            if ($language) {
                if (is_string($data['route'])) {
                    $data['route'] = "/{$language['code']}".$data['route'];
                } else {
                    foreach ($data['route'] as &$v) {
                        $v = "/{$language['code']}".$v;
                    }
                }
            }

            $result['href'] = $data['route'];
        }

        if (isset($data['title'])) {
            $result['title'] = $data['title'];
        }

        return $result;
    }

    public function expanderLink($args)
    {
        $language = DependencyContainer::get('global::language');

        try {
            $data = $this->query($args);

            return $this->linkToData($data);
        } catch (HTTPException $e) {
            throw $e;
        }

        throw new HTTPException(404, 'Failed to expand link().');
    }

    public function expanderQuery($args) {
        if (is_assoc_array($args)) {
            $args = array($args);
        }

        $results = array();

        try {
            foreach($args as $subargs) {
                $subresults = $this->query($subargs);
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

    public function getCollection($uri = '/')
    {
        try {
            $data = $this->query(array(
                '_type' => "collection",
                'route' => $uri,
                '_limit' => 1
            ), true);
        } catch (HTTPException $e) {
            $data = $this->query(array(
                '_type' => "item",
                'route' => $uri,
                '_limit' => 1
            ), true);
        }

        $result = array('collection' => array());

        foreach ($data as $k => &$v) {
            switch($k) {
                case 'content':
                case 'item':
                case 'items':
                case 'pagination':
                case 'shoppingCart':
                case 'template':
                case 'website':
                case 'websiteSettings':
                    $result[$k] = $v;
                    break;
                default:
                    if ($data['_type']==='item') {
                        $result['item'][$k] = $v;

                        break;
                    }

                    $result['collection'][$k] = $v;
                    break;
            }
        }

        if (!isset($result['items'])) {
            $result['items'] = array('query()' => array('_collection' => $data['_id']));
        }

        if (!isset($result['website'])) {
            $result['website'] = array('query()' => array('_limit' => 1, '_type' => 'collection', 'route' => '/'));
        }

        if (empty($result['collection']) && isset($result['item']['_collection'])) {
            try {
                $result['collection'] = $this->query(array('_type' => 'collection', '_id' => $result['item']['_collection']), true);
            } catch(HTTPException $e) {
                throw new HTTPException(404, 'Item has no collection defined');
            }
        }

        if (!isset($result['collection']['breadcrumbs'])) {
            if (isset($result['item'])) {
                $result['collection']['breadcrumbs'] = $this->generateBreadcrumbs(array('_type' => $result['item']['_type'], '_id' => $result['item']['_id']));
            } else {
                $result['collection']['breadcrumbs'] = $this->generateBreadcrumbs(array('_type' => $result['collection']['_type'], '_id' => $result['collection']['_id']));
            }
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

            $breadcrumbs[] = $this->linkToData($item);

            if (isset($item['_collection'])) {
                $breadcrumbs = array_merge($breadcrumbs, $this->generateBreadcrumbs(array('_type' => 'collection', '_id' => $item['_collection'])));
            }
        } catch (HTTPException $e) {
        }
        $traverse--;

        if ($traverse==0) {
            return array_reverse($breadcrumbs);
        }

        return $breadcrumbs;
    }
}
