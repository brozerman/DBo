DBo
===

Efficient ORM.

Install
-------
Requires Git, PHP 5.4+
<pre>
git clone git://github.com/thomasbley/DBo.git .

php -r "\
include 'DBo.php';\
DBo::conn(new mysqli('127.0.0.1', 'root', '', 'test'), 'test');\
DBo::exportSchema(); // generate schema.php"
</pre>

Specification
-------------
http://we-love-php.blogspot.de/2012/08/how-to-implement-small-and-fast-orm.html

Testing
-------
[![Build Status](https://travis-ci.org/thomasbley/DBo.png)](https://travis-ci.org/thomasbley/DBo)
https://github.com/thomasbley/DBo/blob/master/.travis.yml<br/>

License
-------
This code is released under the <a href="/thomasbley/DBo/blob/master/LICENSE">MIT License</a>.