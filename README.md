<img src="https://github.com/stefangabos/zebrajs/blob/master/docs/images/logo.png" alt="zebrajs" align="right">

# Zebra_Session

*A drop-in replacement for PHP's default session handler which stores session data in a MySQL database, providing both better performance and better security and protection against session fixation and session hijacking*

[![Latest Stable Version](https://poser.pugx.org/stefangabos/zebra_session/v/stable)](https://packagist.org/packages/stefangabos/zebra_session) [![Total Downloads](https://poser.pugx.org/stefangabos/zebra_session/downloads)](https://packagist.org/packages/stefangabos/zebra_session) [![Monthly Downloads](https://poser.pugx.org/stefangabos/zebra_session/d/monthly)](https://packagist.org/packages/stefangabos/zebra_session) [![Daily Downloads](https://poser.pugx.org/stefangabos/zebra_session/d/daily)](https://packagist.org/packages/stefangabos/zebra_session) [![License](https://poser.pugx.org/stefangabos/zebra_session/license)](https://packagist.org/packages/stefangabos/zebra_session)

Session support in PHP consists of a way to preserve information (variables) on subsequent accesses to a website's pages. Unlike cookies, variables are not stored on the user's computer. Instead, only a *session identifier* is stored in a cookie on the visitor's computer, which is matched up with the actual session data kept on the server, and made available to us through the [$_SESSION](https://www.php.net/manual/en/reserved.variables.session.php) super-global. Session data is retrieved as soon as we open a session, usually at the beginning of each page.

By default, session data is stored on the server in flat files, separate for each session. The problem with this scenario is that performance degrades proportionally with the number of session files existing in the session directory (depending on the server's operating system's ability to handle directories with numerous files). Another issue is that session files are usually stored in a location that is world readable posing a security concern on shared hosting.

This is where **Zebra_Session** comes in handy - a PHP library that acts as a drop-in replacement for PHP's default session handler, but instead of storing session data in flat files it stores them in a **MySQL database**, providing better security and better performance.

Zebra_Session is also a solution for applications that are scaled across multiple web servers (using a load balancer or a round-robin DNS) where the user's session data needs to be available. Storing sessions in a database makes them available to all of the servers!

Supports *"flash data"* - session variables which will only be available for the next server request, and which will be automatically deleted afterwards. Typically used for informational or status messages (for example: "data has been successfully updated").

This class is was inspired by John Herren's code from the [Trick out your session handler](https://web.archive.org/web/20081221052326/http://devzone.zend.com/node/view/id/141) article (now only available on the [Internet Archive](https://web.archive.org/web/20081221052326/http://devzone.zend.com/node/view/id/141)) and Chris Shiflett's code from his book [Essential PHP Security](https://phpsecurity.org/code/ch08-2), chapter 8, Shared Hosting, Pg. 78-80.

Zebra_Session's code is heavily commented and generates no warnings/errors/notices when PHP's error reporting level is set to [E_ALL](https://www.php.net/manual/en/function.error-reporting.php).

Starting with version 2.0, Zebra_Session implements [row locks](https://dev.mysql.com/doc/refman/8.0/en/miscellaneous-functions.html#function_get-lock), ensuring that data is correctly handled in a scenario with multiple concurrent AJAX requests.

Citing from [Race Conditions with Ajax and PHP Sessions](http://thwartedefforts.org/2006/11/11/race-conditions-with-ajax-and-php-sessions/), a great article by Andy Bakun:

> When locking is not used, multiple requests (represented in these diagrams as processes P1, P2 and P3) access the session data without any consideration for the other processes and the state of the session data. The running time of the requests are indicated by the height of each process's colored area (the actual run times are unimportant, only the relative start times and durations).

![Session access without locking](http://stefangabos.ro/wp-content/uploads/2011/04/session-access-without-locking.png)

> In the example above, no matter how P2 and P3 change the session data, the only changes that will be reflected in the session are those that P1 made because they were written last. When locking is used, the process can start up, request a lock on the session data before it reads it, and then get a consistent read of the session once it acquires exclusive access to it. In the following diagram, all reads occur after writes:

![Session access without locking](http://stefangabos.ro/wp-content/uploads/2011/04/session-access-with-locking.png)

> The process execution is interleaved, but access to the session data is serialized. The process is waiting for the lock to be released during the period between when the process requests the session lock and when the session is read. This means that your session data will remain consistent, but it also means that while processes P2 and P3 are waiting for their turn to acquire the lock, nothing is happening. This may not be that important if all of the requests change or write to the session data, but if P2 just needs to read the session data (perhaps to get a login identifier), it is being held up for no reason.

So, in the end, this is not the best solution but still is better than nothing. The best solution is probably a *per-variable* locking. You can read a very detailed article about all this in Andy Bakun's article [Race Conditions with Ajax and PHP Sessions](http://thwartedefforts.org/2006/11/11/race-conditions-with-ajax-and-php-sessions/).

Thanks to [Michael Kliewe](https://www.phpgangsta.de/) who brought this to my attention!

:books: Check out the [awesome documentation](https://stefangabos.github.io/Zebra_Session/Zebra_Session/Zebra_Session.html)!

## Support the development of this library

[![Donate](https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=8J7UKSA7G6372)

## Features

- acts as a wrapper for PHP's default session handling functions, but instead of storing session data in flat files it stores them in a MySQL database, providing better security and better performance

- it is a drop-in and seamingless replacement for PHP's default session handler: PHP sessions will be used in the same way as prior to using the library; you don't need to change any existing code!

- integrates seamlesly with PDO (if you are using PDO) but works perfectly without it

- implements *row locks*, ensuring that data is correctly handled in scenarios with multiple concurrent AJAX requests

- because session data is stored in a database, the library represents a solution for applications that are scaled across multiple web servers (using a load balancer or a round-robin DNS)

- has awesome documentation

- the code is heavily commented and generates no warnings/errors/notices when PHP's error reporting level is set to E_ALL

## Requirements

PHP 5.5.2+ with the `mysqli extension` activated, MySQL 4.1.22+

## Installation

Download the latest version, unpack it, and load it in your project

```php
require_once 'Zebra_Session.php';
```

## Installation with Composer

You can install Zebra_Session via [Composer](https://packagist.org/packages/stefangabos/zebra_session)

```
composer require stefangabos/zebra_session
```

## Install MySQL table

Notice a directory called *install* containing a file named *session_data.sql*. This file contains the SQL code that will create a table that is used by the class to store session data. Import or execute the SQL code using your preferred MySQL manager (like phpMyAdmin or the fantastic Adminer) into a database of your choice.

## How to use

> Note that this class assumes that there is an active connection to a MySQL database and it does not attempt to create one!

```php
// first, connect to a database containing the sessions table
// either by something similar to
//
// $link = mysqli_connect(host, username, password, database);
//
//  or by using PDO
//
//  try {
//      $link = new PDO(
//      'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4', $username, $password, array(
//         PDO::ATTR_ERRMODE   =>  PDO::ERRMODE_EXCEPTION,
//     ));
// } catch (\PDOException $e) {
//     throw new \PDOException($e->getMessage(), (int)$e->getCode());
// }

// include the Zebra_Session class
// (you don't need this if you are using Composer)
require 'path/to/Zebra_Session.php';

// instantiate the class
// this also calls session_start()
$session = new Zebra_Session($link, 'sEcUr1tY_c0dE');

// from now on, use sessions as you would normally
// this is why it is called a "drop-in replacement" :)
$_SESSION['foo'] = 'bar';

// data is in the database!
```

 :books: Check out the [awesome documentation](https://stefangabos.github.io/Zebra_Session/Zebra_Session/Zebra_Session.html)!