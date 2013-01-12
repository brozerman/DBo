DBo
===

Efficient ORM.

Install
-------
Requires Git, PHP 5.4+
<pre>
git clone git@github.com:thomasbley/DBo.git
</pre>

Specification
-------------
http://we-love-php.blogspot.de/2012/08/how-to-implement-small-and-fast-orm.html

Get started
-----------
DBo::conn(new mysqli('127.0.0.1', 'root', '', 'test'), 'test');
DBo::exportSchema(); // generate schema.php

Testing
-------
https://travis-ci.org/#!/thomasbley/DBo<br/>
https://github.com/thomasbley/DBo/blob/master/.travis.yml<br/>

License
-------
This code is released under the <a href="/thomasbley/DBo/blob/master/LICENSE">MIT License</a>.