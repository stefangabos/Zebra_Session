<?php

/**
 *  A drop-in replacement for PHP's default session handler, using MySQL for storage and providing better performance,
 *  better security and protection against session fixation and session hijacking.
 *
 *  Works with or without PDO.
 *
 *  Read more {@link https://github.com/stefangabos/Zebra_Session/#zebra-session- here}.
 *
 *  @author     Stefan Gabos <contact@stefangabos.ro>
 *  @version    4.1.0 (last revision: June 18, 2024)
 *  @copyright  © 2006 - 2024 Stefan Gabos
 *  @license    https://www.gnu.org/licenses/lgpl-3.0.txt GNU LESSER GENERAL PUBLIC LICENSE
 *  @package    Zebra_Session
 */
class Zebra_Session {

    /**
     *  @var    array<string>
     */
    private $flash_data;

    /**
     *  @var    string
     */
    private $flash_data_var;

    /**
     *  @var    object
     */
    private $link;

    /**
     *  @var    integer
     */
    private $lock_timeout;

    /**
     *  @var    boolean|callable
     */
    private $lock_to_ip;

    /**
     *  @var    boolean
     */
    private $lock_to_user_agent;

    /**
     *  @var    string
     */
    private $security_code;

    /**
     *  @var    string|false
     */
    private $session_lifetime;

    /**
     *  @var    string
     */
    private $session_lock;

    /**
     *  @var    string
     */
    private $table_name;

    /**
     *  @var    boolean
     */
    private $read_only = false;

    /**
     *  Constructor of class. Initializes the class and, optionally, calls
     *  {@link https://php.net/manual/en/function.session-start.php session_start()}
     *
     *  <code>
     *  // first, connect to a database containing the sessions table, either via PDO or using mysqli_connect
     *
     *  // include the class
     *  // (you don't need this if you are using Composer)
     *  require 'path/to/Zebra_Session.php';
     *
     *  // start the session
     *  // where $link is a connection link returned by mysqli_connect or a PDO instance
     *  $session = new Zebra_Session($link, 'sEcUr1tY_c0dE');
     *  </code>
     *
     *  >   **The following configuration options are set by the library when instantiated:**
     *
     *  <code>
     *  // only when over HTTPS
     *  ini_set('session.cookie_secure', 1);
     *  </code>
     *
     *  <code>
     *  // don't expose the cookie to client side scripting making it harder for an attacker to hijack the session ID
     *  ini_set('session.cookie_httponly', 1);
     *  </code>
     *
     *  <code>
     *  // make sure that PHP only uses cookies for sessions and disallow session ID passing as a GET parameter
     *  ini_set('session.use_only_cookies', 1);
     *  </code>
     *
     *  >   **The following configuration options are recommended to be set before instantiating this library:**
     *
     *  <code>
     *  // disallows supplying session IDs via `session_id('ID HERE')
     *  ini_set('session.use_strict_mode', 1);`
     *  </code>
     *
     *  By default, the cookie used by PHP to propagate session data across multiple pages (`PHPSESSID`) uses the
     *  current top-level domain and subdomain in the cookie declaration.
     *
     *  Example: `www.domain.com`
     *
     *  This means that the session data is not available to other subdomains. Therefore, a session started on
     *  `www.domain.com` will not be available on `blog.domain.com`. The solution is to change the domain PHP uses when
     *  it sets the `PHPSESSID` cookie by calling the line below *before* instantiating the Zebra_Session library:
     *
     *  <code>
     *  // takes the domain and removes the subdomain
     *  // blog.domain.com becoming .domain.com
     *  ini_set(
     *      'session.cookie_domain',
     *      substr($_SERVER['SERVER_NAME'], strpos($_SERVER['SERVER_NAME'], '.'))
     *  );
     *  </code>
     *
     *  From now on whenever PHP sets the `PHPSESSID` cookie, the cookie will be available to all subdomains!
     *
     *  @param  resource    &$link              An object representing the connection to a MySQL Server, as returned
     *                                          by calling {@link https://www.php.net/manual/en/mysqli.construct.php mysqli_connect},
     *                                          or a {@link https://www.php.net/manual/en/intro.pdo.php PDO} instance.
     *
     *                                          If you use {@link https://github.com/stefangabos/Zebra_Database Zebra_Database}
     *                                          to connect to the database, you can get the connection to the MySQL server
     *                                          via Zebra_Database's {@link https://stefangabos.github.io/Zebra_Database/Zebra_Database/Zebra_Database.html#methodget_link get_link}
     *                                          method.
     *
     *  @param  string      $security_code      The value of this argument is appended to the string created by
     *                                          concatenating the user browser's User Agent string (or an empty string
     *                                          if `lock_to_user_agent` is `FALSE`) and the user's IP address (or an
     *                                          empty string if `lock_to_ip` is `FALSE`), before creating an MD5 hash out
     *                                          of it and storing it in the database.
     *
     *                                          On each call this value will be generated again and compared to the
     *                                          value stored in the database ensuring that the session is correctly linked
     *                                          with the user who initiated the session thus preventing session hijacking.
     *
     *                                          <samp>To prevent session hijacking, make sure you choose a string around
     *                                          12 characters long containing upper- and lowercase letters, as well as
     *                                          digits. To simplify the process, use {@link https://www.random.org/passwords/?num=1&len=12&format=html&rnd=new this}
     *                                          link to generate such a random string.</samp>
     *
     *  @param  integer     $session_lifetime   (Optional) The number of seconds after which a session will be considered
     *                                          as **expired**.
     *
     *                                          >   A session is active for the number of seconds specified by this property
     *                                          (or until the browser/browser tab is closed if the value is `0`) **OR**
     *                                          the session has been inactive for more than the number of seconds specified
     *                                          by `session.gc_maxlifetime`.
     *
     *                                          >   This property sets the value of {@link https://www.php.net/manual/en/session.configuration.php#ini.session.cookie-lifetime session.cookie_lifetime}.
     *
     *                                          Expired sessions are cleaned up from the database whenever the garbage
     *                                          collection routine runs. The probability for the garbage collection
     *                                          routine to be executed is given by the values of `gc_probability` and
     *                                          `gc_divisor`.
     *
     *                                          To easily check the values of `session.gc_maxlifetime`, `gc_probability`
     *                                          and `gc_divisor` for your environment use the {@link get_settings()} method.
     *
     *                                          Default is `0` - the session is active until the browser/browser tab is
     *                                          is closed **OR** the session has been inactive for more than the number
     *                                          of seconds specified by `session.gc_maxlifetime`.
     *
     *  @param  boolean     $lock_to_user_agent (Optional) Whether to restrict the session to the same User Agent (browser)
     *                                          as when the session was first opened.
     *
     *                                          >   The user agent check only adds minor security, since an attacker that
     *                                              hijacks the session cookie will most likely have the same user agent.
     *
     *                                          In certain scenarios involving Internet Explorer, the browser will randomly
     *                                          change the user agent string from one page to the next by automatically
     *                                          switching into compatibility mode. So, on the first load you would have
     *                                          something like:
     *
     *                                          <code>Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.1; Trident/4...</code>
     *
     *                                          and reloading the page you would have
     *
     *                                          <code> Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; Trident/4...</code>
     *
     *                                          So, if the situation asks for this, change this value to `false`.
     *
     *                                          Default is `true`.
     *
     *  @param  boolean|callable $lock_to_ip    (Optional) Whether to restrict the session to the same IP as when the
     *                                          session was first opened.
     *
     *                                          Use this with caution as users may have a dynamic IP address which may
     *                                          change over time, or may come through proxies.
     *
     *                                          This is mostly useful if you know that all your users come from static IPs.
     *
     *                                          Default is `false`
     *
     *                                          If your environment needs a custom way to get the IP address, you can pass
     *                                          a callable function that will be called to get the IP address.
     *
     *  @param  int         $lock_timeout       (Optional) The maximum amount of time (in seconds) for which a lock on
     *                                          the session data can be kept.
     *
     *                                          >   This must be lower than the maximum execution time of the script!
     *
     *                                          Session locking is a way to ensure that data is correctly handled in a
     *                                          scenario with multiple concurrent AJAX requests.
     *
     *                                          Read more about it
     *                                          {@link http://thwartedefforts.org/2006/11/11/race-conditions-with-ajax-and-php-sessions/ here}.
     *
     *                                          Default is `60`
     *
     *  @param  string      $table_name         (Optional) Name of the MySQL table to be used by the class.
     *
     *                                          Default is `session_data`
     *
     *  @param  boolean     $start_session      (Optional) Whether to start the session right away (by calling {@link https://php.net/manual/en/function.session-start.php session_start()})
     *
     *                                          Default is `true`
     *
     *  @param  boolean     $read_only          (Optional) Opens session in read-only mode and without row locks. Any changes
     *                                          made to `$_SESSION` will not be saved, although the variable can be read/written.
     *
     *                                          Default is `false` (the default session behavior).
     *
     *  @return void
     */
    public function __construct(
        &$link,
        $security_code,
        $session_lifetime = 0,
        $lock_to_user_agent = true,
        $lock_to_ip = false,
        $lock_timeout = 60,
        $table_name = 'session_data',
        $start_session = true,
        $read_only = false
    ) {

        // continue if the provided link is valid
        if (($link instanceof MySQLi && $link->connect_error === null) || $link instanceof PDO) {

            // store the connection link
            $this->link = $link;

            // set session's maximum lifetime
            ini_set('session.cookie_lifetime', $session_lifetime);

            // tell the browser not to expose the cookie to client side scripting
            // this makes it harder for an attacker to hijack the session ID
            ini_set('session.cookie_httponly', '1');

            // make sure that PHP only uses cookies for sessions and disallow session ID passing as a GET parameter
            ini_set('session.use_only_cookies', '1');

            // if on HTTPS allows access to the session ID cookie only when the protocol is HTTPS
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
                ini_set('session.cookie_secure', '1');
            }

            // session lifetime
            $this->session_lifetime = max($session_lifetime, ini_get('session.gc_maxlifetime'));

            // we'll use this later on in order to try to prevent HTTP_USER_AGENT spoofing
            $this->security_code = $security_code;

            // some other defaults
            $this->lock_to_user_agent = $lock_to_user_agent;
            $this->lock_to_ip = $lock_to_ip;

            // the table to be used by the class
            $this->table_name = '`' . trim($table_name, '`') . '`';

            // the maximum amount of time (in seconds) for which a process can lock the session
            $this->lock_timeout = $lock_timeout;

            // set read-only flag
            $this->read_only = $read_only;

            // register the new handler
            session_set_save_handler(
                array(&$this, 'open'),
                array(&$this, 'close'),
                array(&$this, 'read'),
                array(&$this, 'write'),
                array(&$this, 'destroy'),
                array(&$this, 'gc')
            );

            // if a session is already started, destroy it first
            if (session_id() !== '') {
                session_destroy();
            }

            // start session if required
            if ($start_session) {
                session_start();
            }

            // the name for the session variable that will be used for
            // holding information about flash data session variables
            $this->flash_data_var = '_zebra_session_flash_data_ec3asbuiad';

            // assume no flash data
            $this->flash_data = array();

            // if any flash data exists
            if (isset($_SESSION[$this->flash_data_var])) {

                // retrieve flash data
                $this->flash_data = unserialize($_SESSION[$this->flash_data_var]);

                // destroy the temporary session variable
                unset($_SESSION[$this->flash_data_var]);

            }

            // handle flash data after script execution
            register_shutdown_function(array($this, '_manage_flash_data'));

        // if no MySQL connection
        } else {
            throw new Exception('Zebra_Session: No MySQL connection');
        }

    }

    /**
     *  Custom close() function
     *
     *  @return boolean
     *
     *  @access private
     */
    public function close() {

        // release the lock associated with the current session
        return $this->query('

            SELECT
                RELEASE_LOCK(?)

        ', $this->session_lock) !== false;

    }

    /**
     *  Custom destroy() function
     *
     *  @param  string  $session_id     The ID of the session to destroy
     *
     *  @return boolean
     *
     *  @access private
     */
    public function destroy($session_id) {

        // delete the current session from the database
        return $this->query('

            DELETE FROM
                ' . $this->table_name . '
            WHERE
                session_id = ?

        ', $session_id) !== false;

    }

    /**
     *  Custom gc() function (garbage collector)
     *
     *  @return boolean
     *
     *  @access private
     */
    public function gc() {

        // delete expired sessions from database
        $this->query('

            DELETE FROM
                ' . $this->table_name . '
            WHERE
                session_expire < ?

        ', time());

        return true;

    }

    /**
     *  Gets the number of active (not expired) sessions.
     *
     *  >   The returned value does not represent the exact number of active users as some sessions may be unused
     *      although they haven't expired
     *
     *  <code>
     *  // get the number of active sessions
     *  $active_sessions = $session->get_active_sessions();
     *  </code>
     *
     *  @return integer     Returns the number of active (not expired) sessions.
     */
    public function get_active_sessions() {

        // call the garbage collector
        $this->gc();

        // count the rows from the database
        $result = $this->query('

            SELECT
                COUNT(session_id) as count
            FROM
                ' . $this->table_name . '

        ');

        // return the number of found rows
        return $result['data']['count'];

    }

    /**
     *  Queries the system for the values of `session.gc_maxlifetime`, `session.gc_probability`, `session.gc_divisor`
     *  and `session.use_strict_mode`, and returns them as an associative array.
     *
     *  To view the result in a human-readable format use:
     *  <code>
     *  // get default settings
     *  print_r('<pre>');
     *  print_r($session->get_settings());
     *
     *  // would output something similar to (depending on your actual settings)
     *  // Array
     *  // (
     *  //     [session.gc_maxlifetime] => 1440 seconds (24 minutes)
     *  //     [session.gc_probability] => 1
     *  //     [session.gc_divisor] => 1000
     *  //     [probability] => 0.1%    // <- this is computed from the values above
     *  //     [session.use_strict_mode] => 1
     *  // )
     *  </code>
     *
     *  @since 1.0.8
     *
     *  @return array<string>   Returns the values of `session.gc_maxlifetime`, `session.gc_probability`, `session.gc_divisor`
     *                          and `session.use_strict_mode`, as an associative array.
     *
     */
    public function get_settings() {

        // get the settings
        $gc_maxlifetime     = ini_get('session.gc_maxlifetime');
        $gc_probability     = ini_get('session.gc_probability');
        $gc_divisor         = ini_get('session.gc_divisor');
        $use_strict_mode    = ini_get('session.use_strict_mode');

        // return them as an array
        return array(
            'session.gc_maxlifetime'    =>  $gc_maxlifetime . ' seconds (' . round($gc_maxlifetime / 60) . ' minutes)',
            'session.gc_probability'    =>  $gc_probability,
            'session.gc_divisor'        =>  $gc_divisor,
            'probability'               =>  ($gc_divisor > 0 ? (int)$gc_probability / (int)$gc_divisor * 100 : 0) . '%',
            'session.use_strict_mode'   =>  $use_strict_mode,
        );

    }

    /**
     *  Custom open() function
     *
     *  @return boolean
     *
     *  @access private
     */
    public function open() {

        return true;

    }

    /**
     *  Custom read() function
     *
     *  @param  string  $session_id     The ID of the session to read from
     *
     *  @return string
     *
     *  @access private
     */
    public function read($session_id) {

        // get the lock name associated with the current session
        // notice the use of sha1() which shortens the session ID to 40 characters so that it does not exceed the limit of
        // 64 characters for locking string imposed by mySQL >= 5.7.5
        // thanks to Andreas Heissenberger (see https://github.com/stefangabos/Zebra_Session/issues/16)
        $this->session_lock = 'session_' . sha1($session_id);

        // if we are *not* in read-only mode
        // read-only sessions do not need a lock
        if (!$this->read_only) {

            // try to obtain a lock with the given name and timeout
            $result = $this->query('SELECT GET_LOCK(?, ?)', $this->session_lock, $this->lock_timeout);

            // stop if there was an error
            if ($result['num_rows'] != 1) {
                throw new Exception('Zebra_Session: Could not obtain session lock');
            }

        }

        $hash = '';

        // if the sessions is locked to an user agent
        if ($this->lock_to_user_agent && isset($_SERVER['HTTP_USER_AGENT'])) {
            $hash .= $_SERVER['HTTP_USER_AGENT'];
        }

        // if session is locked to an IP address
        if ($this->lock_to_ip === true && ($ip_address = $this->get_ip_address()) !== '') {
            $hash .= $ip_address;
        } elseif ($this->lock_to_ip !== false && is_callable($this->lock_to_ip)) {
            $hash .= call_user_func($this->lock_to_ip);
        }

        // append this to the end
        $hash .= $this->security_code;

        // get the active (not expired) result associated with the session id and hash
        $result = $this->query('

            SELECT
                session_data
            FROM
                ' . $this->table_name . '
            WHERE
                session_id = ?
                AND session_expire > ?
                AND hash = ?
            LIMIT
                1

        ', $session_id, time(), md5($hash));

        // if there were no errors and data was found
        if ($result !== false && $result['num_rows'] > 0) {

            // return session data
            // don't bother with the unserialization - PHP handles this automatically
            return $result['data']['session_data'];

        }

        // if hash has changed or the session expired
        $this->destroy($session_id);

        // on error return an empty string - this HAS to be an empty string
        return '';

    }

    /**
     *  Regenerates the session id.
     *
     *  >   Call this method whenever you do a privilege change, in order to prevent session hijacking!
     *
     *  <code>
     *  // regenerate the session's ID
     *  $session->regenerate_id();
     *  </code>
     *
     *  @return void
     */
    public function regenerate_id() {

        // regenerates the id (create a new session with a new id and containing the data from the old session)
        // also, delete the old session
        session_regenerate_id(true);

    }

    /**
     *  Sets a **flash data** session variable which will only be available for the next server request and which will be
     *  automatically deleted afterwards.
     *
     *  Typically used for informational or status messages (for example: "data has been successfully updated").
     *
     *  <code>
     *  // set "myvar" which will only be available
     *  // for the next server request and will be
     *  // automatically deleted afterwards
     *  $session->set_flashdata('myvar', 'myval');
     *  </code>
     *
     *  "Flash data" session variables can be retrieved like any other session variable:
     *
     *  <code>
     *  if (isset($_SESSION['myvar'])) {
     *      // do something here but remember that the
     *      // flash data session variable is available
     *      // for a single server request after it has
     *      // been set!
     *  }
     *  </code>
     *
     *  @param  string  $name   The name of the session variable.
     *
     *  @param  string  $value  The value of the session variable.
     *
     *  @return void
     */
    public function set_flashdata($name, $value) {

        // set session variable
        $_SESSION[$name] = $value;

        // initialize the counter for this flash data
        $this->flash_data[$name] = 0;

    }

    /**
     *  Deletes all data related to the session.
     *
     *  >   This method runs the garbage collector respecting your environment's garbage collector-related properties.
     *      Read {@link __construct() here} for more information
     *
     *  <code>
     *  // end current session
     *  $session->stop();
     *  </code>
     *
     *  @since 1.0.1
     *
     *  @return void
     */
    public function stop() {

        // if a cookie is used to pass the session id
        if (ini_get('session.use_cookies')) {

            // get session cookie's properties
            $params = session_get_cookie_params();

            // unset the cookie
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);

        }

        // destroy the session
        session_unset();
        session_destroy();

    }

    /**
     *  Custom write() function
     *
     *  @param  string  $session_id     The ID of the session to write to
     *
     *  @param  mixed   $session_data   The values to be written
     *
     *  @return boolean
     *
     *  @access private
     */
    public function write($session_id, $session_data) {

        // we don't write session variable when in read-only mode
        if ($this->read_only) {
            return true;
        }

        // insert OR update session's data - this is how it works:
        // first it tries to insert a new row in the database BUT if session_id is already in the database then just
        // update session_data and session_expire for that specific session_id
        // read more here https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html
        return $this->query('
            INSERT INTO
                ' . $this->table_name . '
                (
                    session_id,
                    hash,
                    session_data,
                    session_expire
                )
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                session_data = VALUES(session_data),
                session_expire = VALUES(session_expire)
            ',
            $session_id,
            md5(
                ($this->lock_to_user_agent && isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '') .
                    ($this->lock_to_ip === true && ($ip_address = $this->get_ip_address()) !== '' ? $ip_address : (
                        $this->lock_to_ip !== false && is_callable($this->lock_to_ip) ? call_user_func($this->lock_to_ip) : '')
                    ) .
                    $this->security_code
            ),
            $session_data,
            time() + $this->session_lifetime
        ) !== false;

    }

    /**
     *  Manages flash data behind the scenes
     *
     *  @return void
     *
     *  @access private
     */
    public function _manage_flash_data() {

        // if there is flash data to be handled
        if (!empty($this->flash_data)) {

            // iterate through all the entries
            foreach ($this->flash_data as $variable => $counter) {

                // increment counter representing server requests
                $this->flash_data[$variable]++;

                // if this is not the first server request
                if ($this->flash_data[$variable] > 1) {

                    // unset the session variable
                    unset($_SESSION[$variable]);

                    // stop tracking
                    unset($this->flash_data[$variable]);

                }

            }

            // if there is any flash data left to be handled
            if (!empty($this->flash_data)) {

                // store data in a temporary session variable
                $_SESSION[$this->flash_data_var] = serialize($this->flash_data);

            }

        }

        // make sure session data is written
        // not matter how script execution ends
        session_write_close();

    }

    /**
     *  Mini-wrapper for running MySQL queries with parameter binding with or without PDO
     *
     *  @param  string  $query  The MySQL query to execute
     *
     *  @return mixed
     *
     *  @access private
     */
    private function query($query) {

        // if the provided connection link is a PDO instance
        if ($this->link instanceof PDO) {

            // if executing the query was a success
            if (($stmt = $this->link->prepare($query)) && $stmt->execute(array_slice(func_get_args(), 1))) {

                // prepare a standardized return value
                $result = array(
                    'num_rows'  =>  $stmt->rowCount(),
                    'data'      =>  $stmt->columnCount() == 0 ? array() : $stmt->fetch(PDO::FETCH_ASSOC),
                );

                // close the statement
                $stmt->closeCursor();

                // return result
                return $result;

            }

        // if link connection is a regular mysqli connection object
        } else {

            $stmt = mysqli_stmt_init($this->link);

            // if query is valid
            if ($stmt->prepare($query)) {

                // the arguments minus the first one (the SQL statement)
                $arguments = array_slice(func_get_args(), 1);

                // if there are any arguments
                if (!empty($arguments)) {

                    // prepare the data for "bind_param"
                    $bind_types = '';
                    $bind_data = array();
                    foreach ($arguments as $key => $value) {
                        $bind_types .= is_numeric($value) ? 'i' : 's';
                        $bind_data[] = &$arguments[$key];
                    }
                    array_unshift($bind_data, $bind_types);

                    // call "bind_param" with the prepared arguments
                    call_user_func_array(array($stmt, 'bind_param'), $bind_data);

                }

                // if the query was successfully executed
                if ($stmt->execute()) {

                    // get some information about the results
                    $results = $stmt->get_result();

                    // prepare a standardized return value
                    $result = array(
                        'num_rows'  =>  is_bool($results) ? $stmt->affected_rows : $results->num_rows,
                        'data'      =>  is_bool($results) ? array() : $results->fetch_assoc(),
                    );

                    // close the statement
                    $stmt->close();

                    // return result
                    return $result;

                }

            }

            // if we get this far there must've been an error
            throw new Exception($stmt->error);

        }

    }

    /**
     *  Tries to get client's *real* IP address.
     *
     *  This should return the same IP address when using something like an AWS load balancer.
     *
     *  @return string
     *
     *  @access private
     */
    private function get_ip_address() {

        $ipaddress = '';
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'] as $key) {
            if (($tmp = getenv($key))) {
                $ipaddress = $tmp;
                break;
            }
        }
        return $ipaddress;

    }

}
