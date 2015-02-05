Zend_Cache_Backend_Mongo
============
:Author: Anton Stöckl <anton@stoeckl.de>

About
=====
**Zend_Cache_Backend_Mongo** is a `Zend Framework <http://zendframework.com/> Backend for `MongoDB <http://www.mongodb.org/>.
It supports tags and autocleaning.

Dependencies
============
**Zend_Cache_Backend_Mongo** requires the MongoDB database version 1.1.1 or above as it use MapReduce for some features.
It has been tested with database version 2.0.0 and 2.0.1.

Installation
============

See http://framework.zend.com/manual/en/zend.cache.backends.html about how to add a new Backend for Zend_Cache

Configuration
=============

See http://framework.zend.com/manual/en/zend.cache.backends.html about how to configure cache backends.
Constructor options for this backend:

Associative array with following fields:

- 'host' => (string) : the name of the mongodb server
- 'port' => (integer) : the port of the mongodb server
- 'persistent' => (bool) : use or not persistent connections to this mongodb server
- 'collection' => (string) : name of the collection to use
- 'dbname' => (string) : name of the database to use

Credits
=======

:Original Author: Olivier Bregeras <olivier.bregeras@gmail.com>

Changes against original version from Stunti
============================================

- fixed function getTags() as the map reduce syntax was wrong (for MongoDB 2.0.0)
- added indexes for optimal performance
- added field 'expire' so that cleaning with Zend_Cache::CLEANING_MODE_OLD does not need to have a function
- removed the tests and the queue folder

License
=======
**Zend_Cache_Backend_Mongo** is licensed under the New BSD License http://framework.zend.com/license/new-bsd
See *LICENSE* for details.
