<?php

namespace attitude;

use \Symfony\Component\Yaml\Yaml;
use \attitude\Elements\HTTPException;
use \attitude\Elements\DependencyContainer;

class FlatYAMLDB_Element
{
    protected $filepath;
    protected $cache_filepath = null;

    protected $data = array();
    protected $indexes = array();
    protected $index_keys = array();

    public function __construct($filepath, $index_keys = array(), $nocache = false)
    {
        if (!is_string($filepath) || strlen(trim($filepath))===0 || !realpath($filepath)) {
            throw new HTTPException(500, 'Path to YAML source does not exit or value is invalid.');
        }

        $this->filepath = $filepath;

        // Store cache as hidden `/path/to/.db_name.yaml.json` file next to the `/path/to/db_name.yaml`
        $this->cache_filepath = dirname($filepath).'/.'.trim(basename($filepath), '.').DependencyContainer::get('yamldb::cacheAdd', '.json');

        foreach ($index_keys as $index_key) {
            if (is_string($index_key) && strlen(trim($index_key)) > 0) {
                $this->index_keys[] = $index_key;
            }
        }

        if ($nocache || $this->cacheNeedsReload()) {
            header('X-Using-DB-Cache: false');

            $this->loadYAML();
        } else {
            header('X-Using-DB-Cache: true');

            try {
                $this->loadCache();
            } catch (HTTPException $e) {
                header('X-Using-DB-Cache: false');
            }
        }

        // Something might get wrong last time
        if (empty($this->data)) {
            header('X-Using-DB-Cache: false');
            $this->loadYAML();
        }

        return $this;
    }

    protected function loadYAML()
    {
        $db = preg_split("/^[ \t]*...[ \t]*\n/m", trim(file_get_contents($this->filepath)));

        foreach ($db as $document) {
            if (strlen($document)===0) {
                continue;
            }

            $padding = strlen($document) - strlen(ltrim($document));
            $document = trim(preg_replace('/^.{'.$padding.'}/m', '', $document));

            $document = substr($document, -3) === '...' ? "---\n".$document : "---\n".$document."\n...";

            try {
                $this->addData(Yaml::parse($document));
            } catch (\Exception $e) {
                trigger_error($e->getMessage()." in document:\n".$document);
            }
        }

        $this->createDBIndex();

        // Walk data and resolve any replacements
        foreach ($this->data as &$result) {
            // Resolve relative paths
            $replacementNeeded = false;

            if (isset($result['collection']) && isset($result['route'])) {
                if (is_array($result['route'])) {
                    foreach ($result['route'] as &$resultRoute) {
                        if (!strstr('^@starts@^'.$resultRoute, '^@starts@^'.'/')) {
                            $replacementNeeded = true;
                        }
                    }
                } elseif (is_string($result['route'])) {
                    if (!strstr('^@starts@^'.$result['route'], '^@starts@^'.'/')) {
                        $replacementNeeded = true;
                    }
                }
            }

            if ($replacementNeeded) {
                try {
                    $parent = $this->query(array('type' => 'collection', 'id' => $result['collection']), true);

                    if ($parent['route']) {
                        $result['route'] = $this->expandRelativeRoutes($parent['route'], $result['route']);
                    }
                } catch (HTTPException $e) {
                    trigger_error('Failed to find parent collection with `id` '.$result['collection']);
                }
            }

            // Dynamic data
            foreach ($result as $k => &$v) {
                if (is_string($v)) {
                    if (preg_match('/\{\{([^\}]+?)\}\}/', $v, $matches)) {
                        if (array_key_exists($matches[1], $result)) {
                            $v = str_replace($matches[0], $result[$matches[1]], $v);
                        }
                    }
                } elseif (is_array($v)) {
                    foreach ($v as &$vv) {
                        if (is_string($vv)) {
                            if (preg_match('/\{\{([^\}]+?)\}\}/', $vv, $matches)) {
                                if (array_key_exists($matches[1], $result)) {
                                    $vv = str_replace($matches[0], $result[$matches[1]], $vv);
                                }
                            }
                        }
                    }
                }
            }
        }

        // Flush indexes
        $this->indexes = array();

        // Recreate indexes and store cache
        $this->createDBIndex();
        $this->storeCache();

        return $this;
    }

    protected function addData($data)
    {
        $this->data[] = $data;

        return $this;
    }

    protected function createDBIndex()
    {
        $index_keys = array();

        foreach ((array) $this->index_keys as $k) {
            $index_keys[] = $k;           // Always public
            $index_keys[] = '_'.$k;       // Internal, maybe public (internal loops etc.)
            $index_keys[] = '__'.$k.'__'; // Internal, never public
        }

        foreach ($this->data as $array_index => $document) {
            foreach ((array) $index_keys as $index_key) {
                if (isset($document[$index_key])) {
                    $data =& $document[$index_key];
                    if(is_array($data)) {
                        foreach ($data as &$subdata) {
                            if (! is_array($subdata)) {
                                $this->addIndex(trim($index_key, '_'), $subdata, $array_index);
                            }
                        }
                    } else {
                        $this->addIndex(trim($index_key, '_'), $data, $array_index);
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
        if (isset($this->indexes[$index][$value])) {
            return $this->indexes[$index][$value];
        }

        return array();
    }

    private function expandRelativeRoutes($parent, $self) {
        // Do nothing when not needed
        if (is_string($self) && !strstr($self, './')) {
            return $self;
        }
        if (is_array($self)) {
            $found = false;
            foreach ($self as &$v) {
                if (strstr($v, './')) {
                    $found = true;
                }
            }
            if (!$found) {
                return $self;
            }
        }


        if (is_array($parent) && is_string($self)) {
            foreach ($parent as $k => &$v) {
                if (strstr($self, '../')) {
                    $v = str_replace('../', dirname(rtrim($v, '/')).'/', $self);
                } elseif (strstr($self, './')) {
                    $v = str_replace('./', rtrim($v, '/').'/', $self);
                }
            }

            return $parent;
        }

        if (is_string($parent) && is_array($self)) {
            foreach ($self as $k => &$v) {
                if (strstr($self, '../')) {
                    $v = str_replace('../', dirname(rtrim($parent, '/')).'/', $v);
                } elseif (strstr($self, './')) {
                    $v = str_replace('./', rtrim($parent, '/').'/', $v);
                }
            }

            return $self;
        }

        if (is_array($parent) && is_array($self)) {
            foreach ($parent as $k => &$v) {
                if (!array_key_exists($k, $self)) {
                    unset($parent[$k]);

                    continue;
                }

                if (strstr($self[$k], '../')) {
                    $v = str_replace('../', dirname(rtrim($v, '/')).'/', $self[$k]);
                } elseif (strstr($self[$k], './')) {
                    $v = str_replace('./', rtrim($v, '/').'/', $self[$k]);
                }
            }

            return $parent;
        }

        if (is_string($parent) && is_string($self)) {
            if (strstr($self, '../')) {
                return str_replace('../', dirname(rtrim($parent, '/')).'/', $self);
            } elseif (strstr($self, './')) {
                return str_replace('./', rtrim($parent, '/').'/', $self);
            }
        }

        throw new HTTPException(500, 'Expecting string or array of routes to merge.');
    }

    public function query($query, $keep_metadata = false)
    {
        $limit   = (isset($query['_limit']))   ? $query['_limit']   : 0;
        $offset  = (isset($query['_offset']))  ? $query['_offset']  : 0;
        $orderby = (isset($query['_orderby'])) ? explode(' ', $query['_orderby']) : array('order', 'ASC');

        if ($orderby && isset($orderby[1]) && !($orderby[1] === 'ASC' || $orderby[1] === 'DESC')) {
            throw new HTTPException(500, 'Query Error: If specified, ordering method must be either `ASC` or `DESC`.');
        }

        unset($query['_limit'], $query['_offset'], $query['_orderby']);

        if (isset($query['id'])) {
            if (!isset($query['type'])) {
                throw new HTTPException(500, 'Querying by id requires passing type');
            }
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
                $result = $this->data[$id];

                if (!isset($result['link']) && isset($result['route']) && isset($result['type']) && isset($result['id'])) {
                    $result['link'] = array('link()' => array('type' => $result['type'], 'id' => $result['id']));
                }

                if ($keep_metadata) {
                    $results[] = $result;
                } else {
                    foreach ($result as $k => &$v) {
                        if ($k[0]==='_') {
                            unset($result[$k]);
                        }
                    }

                    $results[] = $result;
                }
            }
        }

        if (empty($results)) {
            trigger_error('404: '.json_encode($query));
            throw new HTTPException(404, 'Your query returned zero results');
        }

        if (isset($query['id']) || $limit===1) {
            return $results[0];
        }

        self::orderby($results, $orderby);

        return $results;
    }

    /**
     * Modify results according to order parameters
     *
     * Method modifies parrameters passed by refference
     *
     * @param array $array
     * @param array|bool $order
     * @return bool Returs `false` when nothing changed
     *
     */
    public static function orderby(&$array, &$orderby)
    {
        if (!is_array($array)) {
            return false;
        }

        // Try to reorder if the `order` attribute exists
        usort($array, function ($a, $b) use ($orderby) {
            $attr = $orderby && isset($orderby[0]) && is_string($orderby[0]) && strlen(trim($orderby[0])) > 0 ? $orderby[0] : 'order';

            $orderA = isset($a[$attr]) ? $a[$attr] : null;
            $orderB = isset($b[$attr]) ? $b[$attr] : null;

            // For now
            if (is_array($orderA) || is_array($orderB)) {
                return 0;
            }

            // Handle undefined values: numbers
            if (is_numeric($orderA) && $orderB === null) {
                $orderB = $orderA + 1;
            }

            // Handle undefined values: numbers
            if (is_numeric($orderB) && $orderA === null) {
                $orderA = $orderB + 1;
            }

            // Handle undefined values: string
            if (is_string($orderA) && $orderB === null) {
                $orderB = $orderA;
                $orderB[0] = $orderB[0] + 1;
            }

            // Handle undefined values: string
            if (is_string($orderB) && $orderA === null) {
                $orderA = $orderB;
                $orderA[0] = $orderA[0] + 1;
            }

            if ($orderA == $orderB) {
                return 0;
            }

            return ($orderA < $orderB) ? -1 : 1;
        });

        if ($orderby && strtoupper($orderby[1]) === 'DESC') {
            $array = array_reverse($array);
        }

        return true;
    }

    protected function loadCache()
    {
        $cache = json_decode(file_get_contents($this->cache_filepath), true);

        if (!isset($cache['indexes']) || !isset($cache['data'])) {
            throw new HTTPException(500, 'Cache is damadged');
        }

        $this->indexes = $cache['indexes'];
        $this->data    = $cache['data'];

        return $this;
    }

    protected function storeCache()
    {
        file_put_contents($this->cache_filepath, json_encode(array(
            'indexes' => $this->indexes,
            'data'    => $this->data
        )));

        return $this;
    }

    protected function cacheNeedsReload()
    {
        if (! file_exists($this->cache_filepath)) {
            return true;
        }

        if (filemtime($this->cache_filepath) < filemtime($this->filepath)) {
            return true;
        }

        return false;
    }
}
