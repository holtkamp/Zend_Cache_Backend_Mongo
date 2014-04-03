<?php
/**
 * @see Zend_Cache_Backend
 */
require_once 'Zend/Cache/Backend.php';

/**
 * @see Zend_Cache_Backend_ExtendedInterface
 */
require_once 'Zend/Cache/Backend/ExtendedInterface.php';

/**
 * @author     Olivier Bregeras (Stunti) (olivier.bregeras@gmail.com)
 * @author     Anton Stöckl (anton@stoeckl.de)
 * @package    Zend_Cache
 * @subpackage Zend_Cache_Backend_Mongo
 * @copyright  Copyright (c) 2009 Stunti. (http://www.stunti.org)
 * @copyright  Copyright (c) 2011 Anton Stöckl (http://www.stoeckl.de)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Zend_Cache_Backend_Mongo extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{
    const DEFAULT_HOST = '127.0.0.1';
    const DEFAULT_PORT =  27017;
    const DEFAULT_DBNAME = 'Db_Cache';
    const DEFAULT_COLLECTION = 'C_Cache';

    /**
     * The MongoCollection to which cache entries will be written.
     *
     * @var \MongoCollection
     */
    protected $_collection;

    /**
     * If true, indexes have already been ensured for the above collection.
     *
     * @var bool
     */
    private $_indexesEnsured = false;

    /**
     * Available options:
     * 'incrementHitCounter' => (bool): if true, hit counter is incremented
     *                          on each read (increases load on the master).
     *
     * Also:
     * 1. If 'collection' property is present and holds an instance
     *    of MongoConnection, it is used to hold the cached data.
     * 2. Otherwise, the following options are available:
     *    'host' => (string): the name of the MongoDB server
     *    'port' => (int): the port of the MongoDB server
     *    'user' => (string): username to connect as
     *    'password' => (string): password to connect with
     *    'dbname' => (string): name of the database to use
     *    'collection' => (string): name of the collection to use
     *
     * @var array
     */
    protected $_options = array(
        'host'       => self::DEFAULT_HOST,
        'port'       => self::DEFAULT_PORT,
        'dbname'     => self::DEFAULT_DBNAME,
        'collection' => self::DEFAULT_COLLECTION,
    );

    /**
     * Note that we use TTL Collections to have the Mongo deamon automatically clean
     * expired entries.
     *
     * @link http://docs.mongodb.org/manual/tutorial/expire-data/
     * @param array $options
     */
    public function __construct(array $options = array())
    {
        if (!extension_loaded('mongo')) {
            Zend_Cache::throwException('The MongoDB extension must be loaded for using this backend !');
        }
        parent::__construct($options);

        // Merge the options passed in; overriding any default options
        $this->_options = array_merge($this->_options, $options);

        // We check by is_object(), not by "instanceof \MongoCollection", because
        // there could be a wrapper passed, which defines __get() and __call()
        // methods to intercept and pass calls to a real wrapped MongoConnection
        // (e.g. for lazy connections, for reconnect support etc.).
        if (isset($this->_options['collection']) && is_object($this->_options['collection'])) {
            $this->_collection = $this->_options['collection'];
            $this->_options['collection'] = $this->_collection->getName();
        } else {
            $conn = new \MongoClient($this->_getServerConnectionUrl());
            $db = $conn->selectDB($this->_options['dbname']);
            $this->_collection = $db->selectCollection($this->_options['collection']);
        }
    }

    /**
     * Assembles the URL that can be used to connect to the MongoDB server.
     *
     * Note that:
     *  - FALSE, NULL or empty string values should be used to discard options
     *    in an environment-specific configuration. For example when a 'development'
     *    environment overrides a 'production' environment, it might be required
     *    to discard the username and/or password, when this is not required
     *    during development
     *
     * @link http://www.php.net/manual/en/mongoclient.construct.php
     * @return string
     */
    private function _getServerConnectionUrl()
    {
        $parts = array('mongodb://');
        if (isset($this->_options['username']) && strlen($this->_options['username']) > 0 && isset($this->_options['password']) && strlen($this->_options['password']) > 0) {
            $parts[] = $this->_options['username'];
            $parts[] = ':';
            $parts[] = $this->_options['password'];
            $parts[] = '@';
        }

        $parts[] = isset($this->_options['host']) && strlen($this->_options['host']) > 0 ? $this->_options['host'] : self::DEFAULT_HOST;
        $parts[] = ':';
        $parts[] = isset($this->_options['port']) && is_numeric($this->_options['port']) ? $this->_options['port'] : self::DEFAULT_PORT;
        $parts[] = '/';
        $parts[] = isset($this->_options['dbname']) && strlen($this->_options['dbname']) > 0 ? $this->_options['dbname'] : self::DEFAULT_DBNAME;

        return implode('', $parts);
    }

    /**
     * Expires a record (mostly used for testing purposes).
     *
     * @param string $id
     * @return void
     */
    public function ___expire($id)
    {
        if ($tmp = $this->_get($id)) {
            $tmp['expires_at'] = new \MongoDate(3600 * 24 * 7); // near 1970th, deep past
            $this->_collection->save($tmp);
        }
    }

    /**
     * Tests if a cache is available for the given id and (if yes) return it (false else).
     *
     * @param string $id                    Cache id.
     * @param bool $doNotTestCacheValidity  If set to true, the cache validity won't be tested.
     * @return string                       Cached data or false if not found.
     */
    public function load($id, $doNotTestCacheValidity = false)
    {
        try {
            if ($tmp = $this->_get($id, !empty($this->_options['incrementHitCounter']))) {
                if ($doNotTestCacheValidity || $tmp['expires_at'] === null || $tmp['expires_at']->sec >= time()) {
                    return $tmp['d'];
                }
                return false;
            }
        } catch (Exception $e) {
            $this->_log(__METHOD__ . ': ' . $e->getMessage());
            return false;
        }

        return false;
    }

    /**
     * Tests if a cache is available or not (for the given id).
     *
     * @param  string $id  Cache id.
     * @return mixed       Returns false (a cache is not available) or "last modified" timestamp (int) of the available cache record.
     */
    public function test($id)
    {
        try {
            if ($tmp = $this->_get($id)) {
                return $tmp['created_at'];
            }
        } catch (Exception $e) {
            $this->_log(__METHOD__ . ': ' . $e->getMessage());
            return false;
        }

        return false;
    }

    /**
     * Saves some string data into a cache record.
     *
     * Note: $data is always "string" (serialization is done by the
     * core, not by the backend).
     *
     * @param  string $data                Data to cache.
     * @param  string $id                  Cache id.
     * @param  array $tags                 Array of strings, the cache record will be tagged by each string entry.
     * @param  int|bool $specificLifetime  If != false, set a specific lifetime for this cache record (null => infinite lifetime).
     * @return boolean                     True if no problems appeared.
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false)
    {
        try {
            $lifetime = $this->getLifetime($specificLifetime);
            $result = $this->_set($id, $data, $lifetime, $tags);
        } catch (Exception $e) {
            $this->_log(__METHOD__ . ': ' . $e->getMessage());
            $result = false;
        }
        return (bool)$result;
    }

    /**
     * Removes a cache record.
     *
     * @param string $id Cache id.
     * @return boolean   True if no problems appeared.
     */
    public function remove($id)
    {
        try {
            $this->_ensureIndexes();
            $result = $this->_collection->remove(array('_id' => $id));
        } catch (Exception $e) {
            $this->_log(__METHOD__ . ': ' . $e->getMessage());
            return false;
        }
        return $result;
    }

    /**
     * Cleans some cache records (protected method used for recursive stuff).
     *
     * Available modes are:
     * Zend_Cache::CLEANING_MODE_ALL (default)    => remove all cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_OLD              => remove too old cache entries ($tags is not used)
     * Zend_Cache::CLEANING_MODE_MATCHING_TAG     => remove cache entries matching all given tags
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG => remove cache entries not {matching one of the given tags}
     *                                               ($tags can be an array of strings or a single string)
     * Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG => remove cache entries matching any given tags
     *                                               ($tags can be an array of strings or a single string)
     * @param  string $mode  Clean mode.
     * @param  array  $tags  Array of tags.
     * @return boolean       True if no problems appeared.
     * @throws Zend_Cache_Exception
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        $this->_ensureIndexes();
        switch ($mode) {
            case Zend_Cache::CLEANING_MODE_ALL:
                return $this->_collection->remove(array());
            case Zend_Cache::CLEANING_MODE_OLD:
                return $this->_collection->remove(array('expires_at' => array('$lt' => new \MongoDate())));
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                return $this->_collection->remove(array('t' => array('$all' => $tags)));
            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                return $this->_collection->remove(array('t' => array('$nin' => $tags)));
            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                return $this->_collection->remove(array('t' => array('$in' => $tags)));
            default:
                Zend_Cache::throwException('Invalid mode for clean() method');
                return false;
        }
    }

    /**
     * Returns true if the automatic cleaning is available for the backend.
     *
     * @return bool
     */
    public function isAutomaticCleaningAvailable()
    {
        return false;
    }

    /**
     * Sets the frontend directives.
     *
     * @param  array $directives Assoc of directives
     * @return void
     */
    public function setDirectives($directives)
    {
        parent::setDirectives($directives);
        $lifetime = $this->getLifetime(false);
        if ($lifetime === null) {
            // #ZF-4614 : we tranform null to zero to get the maximal lifetime
            parent::setDirectives(array('lifetime' => 0));
        }
    }

    /**
     * Returns an array of stored cache ids.
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        $cursor = $this->_collection->find();
        $ret = array();
        while ($tmp = $cursor->getNext()) {
            $ret[] = $tmp['_id'];
        }
        return $ret;
    }

    /**
     * Returns an array of stored tags.
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        $db = $this->_collection->db;

        $cmd['mapreduce'] = $this->_options['collection'];

        $cmd['map']       = 'function(){
                                this.t.forEach(
                                    function(z){
                                        emit( z , { count : 1 } );
                                    }
                                );
                            };';

        $cmd['reduce']    = 'function( key , values ){
                                var total = 0;
                                for ( var i=0; i<values.length; i++ )
                                    total += values[i].count;
                                return { count : total };
                            };';

        $cmd['out'] = array('replace' => 'getTagsCollection');

        $res2 = $db->command($cmd);

        $res3 = $db->selectCollection('getTagsCollection')->find();

        $res = array();
        foreach ($res3 as $key => $val) {
            $res[] = $key;
        }

        $db->dropCollection($res2['result']);

        return $res;
    }

    /**
     * Aux method to drop the whole collection.
     *
     * @return array
     */
    public function drop()
    {
        return $this->_collection->drop();
    }

    /**
     * Returns an array of stored cache ids which match given tags.
     * In case of multiple tags, a logical AND is made between tags.
     *
     * @param array $tags  Array of tags.
     * @return array       Array of matching cache ids (string).
     */
    public function getIdsMatchingTags($tags = array())
    {
        $cursor = $this->_collection->find(
            array('t' => array('$all' => $tags))
        );
        $ret = array();
        while ($tmp = $cursor->getNext()) {
            $ret[] = $tmp['_id'];
        }
        return $ret;
    }

    /**
     * Returns an array of stored cache ids which don't match given tags.
     * In case of multiple tags, a logical OR is made between tags.
     *
     * @param array $tags  Array of tags.
     * @return array       Array of not matching cache ids (string).
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        $cursor =  $this->_collection->find(
            array('t' => array('$nin' => $tags))
        );
        $ret = array();
        while ($tmp = $cursor->getNext()) {
            $ret[] = $tmp['_id'];
        }
        return $ret;
    }

    /**
     * Returns an array of stored cache ids which match any given tags.
     * In case of multiple tags, a logical AND is made between tags.
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = array())
    {
        $cursor =  $this->_collection->find(
            array('t' => array('$in' => $tags))
        );
        $ret = array();
        while ($tmp = $cursor->getNext()) {
            $ret[] = $tmp['_id'];
        }
        return $ret;
    }

    /**
     * No way to find the remaining space right now. So return 1.
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        return 1;
    }

    /**
     * Returns an array of metadatas for the given cache id.
     *
     * The array must include these keys:
     * - expire: the expire timestamp
     * - tags: a string array of tags
     * - mtime: timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
        if ($tmp = $this->_get($id)) {
            $expiresAt = $tmp['expires_at'];
            $createdAt = $tmp['created_at'];
            return array(
                'expire' => $expiresAt instanceof \MongoDate ? $expiresAt->sec : null,
                'tags' => $tmp['t'],
                'mtime' => $createdAt->sec
            );
        }
        return false;
    }

    /**
     * Gives (if possible) an extra lifetime to the given cache id.
     * TODO: consider using findOneAndModify to reduce amount of requests to MongoDB.
     *
     * @param string $id             Cache id.
     * @param integer $extraLifetime
     * @return boolean               True if OK.
     */
    public function touch($id, $extraLifetime)
    {
        $result = false;
        if ($tmp = $this->_get($id)) {
            // Check whether an expiration time has been set that has not expired yet.
            if ($tmp['expires_at'] instanceof \MongoDate && $tmp['expires_at']->sec > time()) {
                $newLifetime = $tmp['expires_at']->sec + $extraLifetime;
                $result = $this->_set($id, $tmp['d'], $newLifetime, $tmp['t']);
            }
        }
        return $result;
    }

    /**
     * Returns an associative array of capabilities (booleans) of the backend.
     *
     * The array must include these keys:
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => true,
            'tags' => true,
            'expired_read' => true,
            'priority' => false,
            'infinite_lifetime' => true,
            'get_list' => true
        );
    }

    /**
     * Saves data to a the MongoDB collection.
     *
     * @param integer $id
     * @param array $data
     * @param integer $lifetime
     * @param mixed $tags
     * @return boolean
     */
    private function _set($id, $data, $lifetime, $tags)
    {
        list ($nowMicroseconds, $nowSeconds) = explode(' ', microtime());
        $nowMicroseconds = intval($nowMicroseconds * 1000000); // convert from 'expressed in seconds' to complete microseconds
        $this->_ensureIndexes();
        return $this->_collection->save(
            array(
                '_id' => $id,
                'd' => $data,
                'created_at' => new \MongoDate($nowSeconds, $nowMicroseconds),
                'expires_at' => is_numeric($lifetime) && intval($lifetime) !== 0 ? new \MongoDate($nowSeconds + $lifetime, $nowMicroseconds) : null,
                't' => $tags,
                'hits' => 0
            )
        );
    }

    /**
     * Lookups a specific cache entry.
     *
     * Optionally, increment the hit counter when loading the cache entry
     * (this increases load on the master, so by default it is turned off).
     *
     * @param integer $id
     * @param boolean $incrementHitCounter = false
     * @return array|bool
     */
    private function _get($id, $incrementHitCounter = false)
    {
        if ($incrementHitCounter === true){
            return $this->_collection->findAndModify(
                array('_id' => $id),
                array('$inc' => array('hits' => 1))
            );
        } else {
            return $this->_collection->findOne(array('_id' => $id));
        }
    }

    /**
     * Calls ensureIndex() on the collection if they were not called yet.
     * Typically executed before cache writes only to avoid disturbing
     * the master database on much more frequent cache reads.
     *
     * @return void
     */
    private function _ensureIndexes()
    {
        if (!$this->_indexesEnsured) {
            $this->_indexesEnsured = true;
            $this->_collection->ensureIndex(array('t' => 1), array('background' => true));
            $this->_collection->ensureIndex(
                array('expires_at' => 1),
                array('background' => true,
                    'expireAfterSeconds' => 0 // Have entries expire directly (0 seconds) after reaching expiration time
                )
            );
        }
    }
}
