## version 3.0.0 (February 22, 2020)

- added integration with PDO
- implemented prepared statemets as `mysqli_real_escape_string` may not be secure enough when used with PHP < `5.7.6`; see [this](https://stackoverflow.com/questions/5741187/sql-injection-that-gets-around-mysql-real-escape-string/23277864#23277864) for more information; thanks [duckboy81](https://github.com/duckboy81) for suggesting
- sessions can now be started in read-only mode thus not having to do row locks; see [#26](https://github.com/stefangabos/Zebra_Session/pull/26); thanks [more7dev](https://github.com/more7dev)!
- [session.use_strict_mode](https://www.php.net/manual/en/session.configuration.php#ini.session.use-strict-mode) is now always enabled by the library automatically; thanks [dnanusevski](https://github.com/dnanusevski) for suggesting
- [session.cookie_secure](https://www.php.net/manual/en/session.configuration.php#ini.session.cookie-secure) is now automatically enabled by the library *if HTTPS connection is detected*; thanks [dnanusevski](https://github.com/dnanusevski) for suggesting
- fixed issue when using special characters in table name; see [#27](https://github.com/stefangabos/Zebra_Session/issues/27); thanks [more7dev](https://github.com/more7dev)!
- added option for disabling automatically starting the session; see [#28](https://github.com/stefangabos/Zebra_Session/issues/28); thanks [Nick Muerdter](https://github.com/GUI) for the pull request!
- minimum required PHP version has changed from `5.1.0` to `5.5.2`

## version 2.1.10 (January 05, 2019)

- fixed [bug](https://github.com/stefangabos/Zebra_Session/pull/24) because of incorrect logic; thanks [RolandD](https://github.com/roland-d)!

## version 2.1.9 (January 03, 2019)

- fixed [#16](https://github.com/stefangabos/Zebra_Session/issues/16) where the maximum length for lock keys in MySQL 5.7.5+ is limited to 64 characters; thanks to [Andreas Heissenberger](https://github.com/aheissenberger) for providing the fix!
- the library now destroys previous sessions when started
- database errors now throw exceptions instead of dying; thanks [Jonathon Hill](https://github.com/compwright)

## version 2.1.8 (May 20, 2017)

- documentation is now available in the repository and on GitHub
- the home of the library is now exclusively on GitHub

## version 2.1.7 (May 01, 2017)

- security tweaks (setting `session.cookie_httponly` and `session.use_only_cookies` to `1` by default)
- the stop() method will now also remove the associated cookie

## version 2.1.6 (April 19, 2017)

- hopefully [#13](https://github.com/stefangabos/Zebra_Session/issues/13) is now fixed for good

## version 2.1.5 (April 11, 2017)

- closed [#11](https://github.com/stefangabos/Zebra_Session/issues/11); thanks @soren121
- fixed (hopefully) [#13](https://github.com/stefangabos/Zebra_Session/issues/13); thanks to @alisonw for providing the current fix
- reduced overall code complexity

## version 2.1.4 (February 19, 2016)

- fixed an issue when using the library with Composer

## version 2.1.1 (September 25, 2014)

- this version makes use of a feature introduced in PHP 5.1.0 for the "regenerate_id" method; thanks to **Fernando Sávio**; this also means that now the library requires PHP 5.1.0+ to work

## version 2.1.0 (August 02, 2013)

- dropped support for PHP 4; minimum required version is now PHP 5
- dropped support for PHP's *mysql* extension, which is [officially deprecated as of PHP v5.5.0](http://php.net/manual/en/changelog.mysql.php) and will be removed in the future; the extension was originally introduced in PHP v2.0 for MySQL v3.23, and no new features have been added since 2006; the library now relies on PHP's [mysqli](http://php.net/manual/en/book.mysqli.php) extension
- because of the above, the order of the arguments passed to the [constructor](https://stefangabos.github.io/Zebra_Session/Zebra_Session/Zebra_Session.html#methodZebra_Session) have changed and now the "link" argument comes first, as with the *mysqli* extension this now needs to be explicitly given
- added support for "flash data" - session variable which will only be available for the next server request, and which will be automatically deleted afterwards. Typically used for informational or status messages (for example: "data has been successfully updated"); see the newly added [set_flashdata](https://stefangabos.github.io/Zebra_Session/Zebra_Session/Zebra_Session.html#methodset_flashdata) method; thanks **Andrei Bodeanschi** for suggesting!
- the project is now available on [GitHub](https://github.com/stefangabos/Zebra_Session) and also as a [package for Composer](https://packagist.org/packages/stefangabos/zebra_session)

## version 2.0.4 (January 19, 2013)

- previously, session were always tied to the user agent used when the session was first opened; while this is still true, now this behavior can be disabled by setting the [constructor](https://stefangabos.github.io/Zebra_Session/Zebra_Session/Zebra_Session.html#methodZebra_Session)'s new "lock_to_user_agent" argument to FALSE; why? because in certain scenarios involving Internet Explorer, the browser will randomly change the user agent string from one page to the next by automatically switching into compatibility mode; thanks to **Andrei Bodeanschi**
- also, the [constructor](https://stefangabos.github.io/Zebra_Session/Zebra_Session/Zebra_Session.html#methodZebra_Session) has another new "lock_to_ip" argument with which a session can be restricted to the same IP as when the session was first opened - use this with caution as many ISPs provide dynamic IP addresses which change over time, not to mention users who come through proxies; this is mostly useful if you know your users come from static IPs
- for better protection against session hijacking, the first argument of the [constructor](https://stefangabos.github.io/Zebra_Session/Zebra_Session/Zebra_Session.html#methodZebra_Session) is now mandatory
- altered the table structure
- because the table's structure has changed as well as the order of the arguments in the [constructor](https://stefangabos.github.io/Zebra_Session/Zebra_Session/Zebra_Session.html#methodZebra_Session), you'll have to change a few things when switching to this version from a previous one
- some documentation refinements

## version 2.0.3 (July 13, 2012)

- fixed a bug where sessions' life time was twice longer than expected; thanks to **Andrei Bodeanschi**
- details on how to preserve session data across sub domains was added to the documentation
- the messages related database connection errors are now more meaningful

## version 2.0.2 (October 24, 2011)

- fixed a bug with the get_active_sessions() method which was not working at all
- fixed a bug where the script was not using the provided MySQL link identifier (if available)

## version 2.0.1 (July 03, 2011)

- the constructor method now accepts an optional *link* argument which must be a MySQL link identifier. By default, the library made use of the last link opened by mysql_connect(). On some environments (particularly on a shared hosting) the "last link opened by mysql_connect" was not available at the time of the instantiation of the Zebra_Session library. For these cases, supplying the MySQL link identifier to the constructor method will fix things. Thanks to **Mark** for reporting.
- some documentation refinements

## version 2.0 (April 18, 2011)

- the class now implements <i>session locking</i>; session locking is a way to ensure that data is correctly handled in a scenario with multiple concurrent AJAX requests; thanks to **Michael Kliewe** for suggesting this and to <b>Andy Bakun</b> for this excellent article on the subject [Race Conditions with Ajax and PHP Sessions](http://thwartedefforts.org/2006/11/11/race-conditions-with-ajax-and-php-sessions/).

## version 1.0.8 (December 27, 2010)

- fixed a small bug in the *destroy* method; thanks to **Tagir Valeev** for reporting
- the script would trigger a PHP notice if the HTTP_USER_AGENT value was not available in the $_SERVER super-global
- added a new method "get_settings" that returns the default session-related settings for the environment where the class is used

## version 1.0.7 (October 29, 2008)

- the class will now trigger a fatal error if a database connection is not available
- the class will now report if MySQL table is not available

## version 1.0.6 (October 01, 2007)

- the constructor of the class now accepts a new argument: *tableName* - with this, the MySQL table used by the class can be changed

## version 1.0.5 (September 15, 2007)

- 'LIMIT 1' added to the *read* method improving the performance of the script; thanks to **A. Leeming** for suggesting this

## version 1.0.4 (August 23, 2007)

- rewritten the *write* method which previously had to run two queries each time; it now only runs a single one, using ON DUPLICATE KEY UPDATE; thanks to **Inchul Koo** for providing this information
- the type of the *http_user_agent* column in the MySQL table has been changed from TEXT to VARCHAR(32) as now it is an MD5 hash; this should increase the performance of the script; thanks to **Inchul Koo** for suggesting this
- the constructor of the class now accepts a new argument: *securityCode*, in order to try to prevent HTTP_USER_AGENT spoofing; read the documentation for more information; thanks to **Inchul Koo** for suggesting this

## version 1.0.3 (December 13, 2006)

- the *get_users_online* method is now more accurate as it now runs the garbage collector before getting the number of online users; thanks to **Gilles** for the tip
- the structure of the MySQL table used by the class was tweaked in so that the *http_user_agent* column has been changed from VARCHAR(255) to TEXT and the *session_data* column has been changed from TEXT to BLOB; thanks to **Gilles** for the tip

## version 1.0.2 (November 22, 2006)

- the class was trying to store the session data without *mysql_real_escape_string*-ing it and therefore, whenever the data to be saved contained quotes, nothing was saved; thanks to **Ed Kelly** for reporting this

## version 1.0.1 (September 11, 2006)

- the structure of the MySQL table used by the class was tweaked and now the *session_id* column is now a primary key and its type has changed from VARCHAR(255) to VARCHAR(32) as it now is an MD5 hash; previously a whole table scan was done every time a session was loaded; thanks to **Harry** for suggesting this
- added a new *stop* method which deletes a session along with the stored variables from the database; this was introduced because many people were using the private *destroy* method which is only for internal purposes
- the default settings for *session.gc_maxlifetime*, *session.gc_probability* and *session.gc_divisor* can now be overridden through the constructor
- on popular request, an example file was added

## version 1.0 (August 01, 2006)

- initial release
