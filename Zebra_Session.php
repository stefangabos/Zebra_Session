<?php

/**
 *  A PHP library acting as a drop-in replacement for PHP's default session handler, but instead of storing session data
 *  in flat files it stores them in a MySQL database, providing both better performance and better security and
 *  protection against session fixation and session hijacking.
 *
 *  Zebra_Session implements <i>session locking</i>. Session locking is a way to ensure that data is correctly handled
 *  in a scenario with multiple concurrent AJAX requests. Read more about it in this excellent article by <b>Andy Bakun</b>
 *  called {@link http://thwartedefforts.org/2006/11/11/race-conditions-with-ajax-and-php-sessions/ Race Conditions with Ajax and PHP Sessions}.
 *
 *  This library is also a solution for applications that are scaled across multiple web servers (using a
 *  load balancer or a round-robin DNS) and where the user's session data needs to be available. Storing sessions in a
 *  database makes them available to all of the servers!
 *
 *  Zebra_Session supports "flashdata" - session variable which will only be available for the next server request, and
 *  which will be automatically deleted afterwards. Typically used for informational or status messages (for example:
 *  "data has been successfully updated").
 *
 *  Zebra_Session is was inspired by John Herren's code from the {@link http://devzone.zend.com/413/trick-out-your-session-handler/ Trick out your session handler}
 *  article and {@link http://shiflett.org/articles/the-truth-about-sessions Chris Shiflett}'s articles about PHP sessions.
 *
 *  The code is heavily commented and generates no warnings/errors/notices when PHP's error reporting level is set to
 *  E_ALL.
 *
 *  Visit {@link http://stefangabos.ro/php-libraries/zebra-session/} for more information.
 *
 *  For more resources visit {@link http://stefangabos.ro/}
 *
 *  @author     Stefan Gabos <contact@stefangabos.ro>
 *  @version    2.1.1 (last revision: September 25, 2013)
 *  @copyright  (c) 2006 - 2014 Stefan Gabos
 *  @license    http://www.gnu.org/licenses/lgpl-3.0.txt GNU LESSER GENERAL PUBLIC LICENSE
 *  @package    Zebra_Session
 */

class Zebra_Session
{

    private $flashdata;
    private $flashdata_varname;
    private $session_lifetime;
    private $link;
    private $lock_timeout;
    private $lock_to_ip;
    private $lock_to_user_agent;
    private $table_name;

    /**
     *  Constructor of class. Initializes the class and automatically calls
     *  {@link http://php.net/manual/en/function.session-start.php start_session()}.
     *
     *  <code>
     *  // first, connect to a database containing the sessions table
     *
     *  //  include the class
     *  require 'path/to/Zebra_Session.php';
     *
     *  //  start the session
     *  //  where $link is a connection link returned by mysqli_connect
     *  $session = new Zebra_Session($link, 'sEcUr1tY_c0dE');
     *  </code>
     *
     *  By default, the cookie used by PHP to propagate session data across multiple pages ('PHPSESSID') uses the
     *  current top-level domain and subdomain in the cookie declaration.
     *
     *  Example: www.domain.com
     *
     *  This means that the session data is not available to other subdomains. Therefore, a session started on
     *  www.domain.com will not be available on blog.domain.com. The solution is to change the domain PHP uses when it
     *  sets the 'PHPSESSID' cookie by calling the line below *before* instantiating the Zebra_Session library.
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
     *  From now on whenever PHP sets the 'PHPSESSID' cookie, the cookie will be available to all subdomains!
     *
     *  @param  resource    $link               An object representing the connection to a MySQL Server, as returned
     *                                          by calling {@link http://www.php.net/manual/en/mysqli.construct.php mysqli_connect}.
     *
     *                                          If you use {@link http://stefangabos.ro/php-libraries/zebra-database/ Zebra_Database}
     *                                          to connect to the database, you can get the connection to the MySQL server
     *                                          via Zebra_Database's {@link http://stefangabos.ro/wp-content/docs/Zebra_Database/Zebra_Database/Zebra_Database.html#methodget_link get_link}
     *                                          method.
     *
     *  @param  string      $security_code      The value of this argument is appended to the string created by
     *                                          concatenating the user's User Agent (browser) string (or an empty string
     *                                          if "lock_to_user_agent" is FALSE) and to the user's IP address (or an
     *                                          empty string if "lock_to_ip" is FALSE), before creating an MD5 hash out
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
     *                                          as <i>expired</i>.
     *
     *                                          Expired sessions are cleaned up from the database whenever the <i>garbage
     *                                          collection routine</i> is run. The probability of the <i>garbage collection
     *                                          routine</i> to be executed is given by the values of <i>$gc_probability</i>
     *                                          and <i>$gc_divisor</i>. See below.
     *
     *                                          Default is the value of <i>session.gc_maxlifetime</i> as set in in php.ini.
     *                                          Read more at {@link http://www.php.net/manual/en/session.configuration.php}
     *
     *                                          To clear any confusions that may arise: in reality, <i>session.gc_maxlifetime</i>
     *                                          does not represent a session's lifetime but the number of seconds after
     *                                          which a session is seen as <i>garbage</i> and is deleted by the <i>garbage
     *                                          collection routine</i>. The PHP setting that sets a session's lifetime is
     *                                          <i>session.cookie_lifetime</i> and is usually set to "0" - indicating that
     *                                          a session is active until the browser/browser tab is closed. When this class
     *                                          is used, a session is active until the browser/browser tab is closed and/or
     *                                          a session has been inactive for more than the number of seconds specified
     *                                          by <i>session.gc_maxlifetime</i>.
     *
     *                                          To see the actual value of <i>session.gc_maxlifetime</i> for your
     *                                          environment, use the {@link get_settings()} method.
     *
     *                                          Pass an empty string to keep default value.
     *
     *  @param  boolean     $lock_to_user_agent (Optional) Whether to restrict the session to the same User Agent (or
     *                                          browser) as when the session was first opened.
     *
     *                                          <i>The user agent check only adds minor security, since an attacker that
     *                                          hijacks the session cookie will most likely have the same user agent.</i>
     *
     *                                          In certain scenarios involving Internet Explorer, the browser will randomly
     *                                          change the user agent string from one page to the next by automatically
     *                                          switching into compatibility mode. So, on the first load you would have
     *                                          something like:
     *
     *                                          <code>Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.1; Trident/4.0; etc...</code>
     *
     *                                          and reloading the page you would have
     *
     *                                          <code> Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; Trident/4.0; etc...</code>
     *
     *                                          So, if the situation asks for this, change this value to FALSE.
     *
     *                                          Default is TRUE.
     *
     *  @param  boolean     $lock_to_ip         (Optional) Whether to restrict the session to the same IP as when the
     *                                          session was first opened.
     *
     *                                          Use this with caution as many users have dynamic IP addresses which may
     *                                          change over time, or may come through proxies.
     *
     *                                          This is mostly useful if your know that all your users come from static IPs.
     *
     *                                          Default is FALSE.
     *
     *  @param  integer     $gc_probability     (Optional) Used in conjunction with <i>$gc_divisor</i>. It defines the
     *                                          probability that the <i>garbage collection routine</i> is started.
     *
     *                                          The probability is expressed by the formula:
     *
     *                                          <code>
     *                                          $probability = $gc_probability / $gc_divisor;
     *                                          </code>
     *
     *                                          So, if <i>$gc_probability</i> is 1 and <i>$gc_divisor</i> is 100, it means
     *                                          that there is a 1% chance the the <i>garbage collection routine</i> will
     *                                          be called on each request.
     *
     *                                          Default is the value of <i>session.gc_probability</i> as set in php.ini.
     *                                          Read more at {@link http://www.php.net/manual/en/session.configuration.php}
     *
     *                                          To see the actual value of <i>session.gc_probability</i> for your
     *                                          environment, and the computed <i>probability</i>, use the
     *                                          {@link get_settings()} method.
     *
     *                                          Pass an empty string to keep default value.
     *
     *  @param  integer     $gc_divisor         (Optional) Used in conjunction with <i>$gc_probability</i>. It defines the
     *                                          probability that the <i>garbage collection routine</i> is started.
     *
     *                                          The probability is expressed by the formula:
     *
     *                                          <code>
     *                                          $probability = $gc_probability / $gc_divisor;
     *                                          </code>
     *
     *                                          So, if <i>$gc_probability</i> is 1 and <i>$gc_divisor</i> is 100, it means
     *                                          that there is a 1% chance the the <i>garbage collection routine</i> will
     *                                          be called on each request.
     *
     *                                          Default is the value of <i>session.gc_divisor</i> as set in php.ini.
     *                                          Read more at {@link http://www.php.net/manual/en/session.configuration.php}
     *
     *                                          To see the actual value of <i>session.gc_divisor</i> for your
     *                                          environment, and the computed <i>probability</i>, use the
     *                                          {@link get_settings()} method.
     *
     *                                          Pass an empty string to keep default value.
     *
     *  @param  string      $table_name         (Optional) Name of the MySQL table used by the class.
     *
     *                                          Default is <i>session_data</i>.
     *
     *  @param  string      $lock_timeout       (Optional) The maximum amount of time (in seconds) for which a lock on
     *                                          the session data can be kept.
     *
     *                                          <i>This must be lower than the maximum execution time of the script!</i>
     *
     *                                          Session locking is a way to ensure that data is correctly handled in a
     *                                          scenario with multiple concurrent AJAX requests.
     *
     *                                          Read more about it at
     *                                          {@link http://thwartedefforts.org/2006/11/11/race-conditions-with-ajax-and-php-sessions/}
     *
     *                                          Default is <i>60</i>
     *
     *  @return void
     */
    function __construct(&$link, $security_code, $session_lifetime = '', $lock_to_user_agent = true, $lock_to_ip = false, $gc_probability = '', $gc_divisor = '', $table_name = 'session_data', $lock_timeout = 60)
    {

        // store the connection link
        $this->link = $link;

        // continue if there is an active MySQL connection
        if ($this->_mysql_ping()) {

            // make sure session cookies never expire so that session lifetime
            // will depend only on the value of $session_lifetime
            ini_set('session.cookie_lifetime', 0);

            // if $session_lifetime is specified and is an integer number
            if ($session_lifetime != '' && is_integer($session_lifetime))

                // set the new value
                ini_set('session.gc_maxlifetime', (int)$session_lifetime);

            // if $gc_probability is specified and is an integer number
            if ($gc_probability != '' && is_integer($gc_probability))

                // set the new value
                ini_set('session.gc_probability', $gc_probability);

            // if $gc_divisor is specified and is an integer number
            if ($gc_divisor != '' && is_integer($gc_divisor))

                // set the new value
                ini_set('session.gc_divisor', $gc_divisor);

            // get session lifetime
            $this->session_lifetime = ini_get('session.gc_maxlifetime');

            // we'll use this later on in order to try to prevent HTTP_USER_AGENT spoofing
            $this->security_code = $security_code;

            // some other defaults
            $this->lock_to_user_agent = $lock_to_user_agent;
            $this->lock_to_ip = $lock_to_ip;

            // the table to be used by the class
            $this->table_name = $table_name;

            // the maximum amount of time (in seconds) for which a process can lock the session
            $this->lock_timeout = $lock_timeout;

            // register the new handler
            session_set_save_handler(
                array(&$this, 'open'),
                array(&$this, 'close'),
                array(&$this, 'read'),
                array(&$this, 'write'),
                array(&$this, 'destroy'),
                array(&$this, 'gc')
            );

            // start the session
            session_start();

            // the name for the session variable that will be created upon script execution
            // and destroyed when instantiating this library, and which will hold information
            // about flashdata session variables
            $this->flashdata_varname = '_zebra_session_flashdata_ec3asbuiad';

            // assume no flashdata
            $this->flashdata = array();

            // if there are any flashdata variables that need to be handled
            if (isset($_SESSION[$this->flashdata_varname])) {

                // store them
                $this->flashdata = unserialize($_SESSION[$this->flashdata_varname]);

                // and destroy the temporary session variable
                unset($_SESSION[$this->flashdata_varname]);

            }

            // handle flashdata after script execution
            register_shutdown_function(array($this, '_manage_flashdata'));

        // if no MySQL connections could be found
        // trigger a fatal error message and stop execution
        } else trigger_error('Zebra_Session: No MySQL connection!', E_USER_ERROR);

    }

    /**
     *  Get the number of active sessions - sessions that have not expired.
     *
     *  <i>The returned value does not represent the exact number of active users as some sessions may be unused
     *  although they haven't expired.</i>
     *
     *  <code>
     *  // first, connect to a database containing the sessions table
     *
     *  //  include the class
     *  require 'path/to/Zebra_Session.php';
     *
     *  //  start the session
     *  //  where $link is a connection link returned by mysqli_connect
     *  $session = new Zebra_Session($link, 'sEcUr1tY_c0dE');
     *
     *  //  get the (approximate) number of active sessions
     *  $active_sessions = $session->get_active_sessions();
     *  </code>
     *
     *  @return integer     Returns the number of active (not expired) sessions.
     */
    public function get_active_sessions()
    {

        // call the garbage collector
        $this->gc();

        // counts the rows from the database
        $result = @mysqli_fetch_assoc($this->_mysql_query('

            SELECT
                COUNT(session_id) as count
            FROM ' . $this->table_name . '

        ')) or die(_mysql_error());

        // return the number of found rows
        return $result['count'];

    }

    /**
     *  Queries the system for the values of <i>session.gc_maxlifetime</i>, <i>session.gc_probability</i> and <i>session.gc_divisor</i>
     *  and returns them as an associative array.
     *
     *  To view the result in a human-readable format use:
     *  <code>
     *  //  include the class
     *  require 'path/to/Zebra_Session.php';
     *
     *  //  instantiate the class
     *  $session = new Zebra_Session();
     *
     *  //  get default settings
     *  print_r('<pre>');
     *  print_r($session->get_settings());
     *
     *  //  would output something similar to (depending on your actual settings)
     *  //  Array
     *  //  (
     *  //      [session.gc_maxlifetime] => 1440 seconds (24 minutes)
     *  //      [session.gc_probability] => 1
     *  //      [session.gc_divisor] => 1000
     *  //      [probability] => 0.1%
     *  //  )
     *  </code>
     *
     *  @since 1.0.8
     *
     *  @return array   Returns the values of <i>session.gc_maxlifetime</i>, <i>session.gc_probability</i> and <i>session.gc_divisor</i>
     *                  as an associative array.
     *
     */
    public function get_settings()
    {

        // get the settings
        $gc_maxlifetime = ini_get('session.gc_maxlifetime');
        $gc_probability = ini_get('session.gc_probability');
        $gc_divisor     = ini_get('session.gc_divisor');

        // return them as an array
        return array(
            'session.gc_maxlifetime'    =>  $gc_maxlifetime . ' seconds (' . round($gc_maxlifetime / 60) . ' minutes)',
            'session.gc_probability'    =>  $gc_probability,
            'session.gc_divisor'        =>  $gc_divisor,
            'probability'               =>  $gc_probability / $gc_divisor * 100 . '%',
        );

    }

    /**
     *  Regenerates the session id.
     *
     *  <b>Call this method whenever you do a privilege change in order to prevent session hijacking!</b>
     *
     *  <code>
     *  // first, connect to a database containing the sessions table
     *
     *  //  include the class
     *  require 'path/to/Zebra_Session.php';
     *
     *  //  start the session
     *  //  where $link is a connection link returned by mysqli_connect
     *  $session = new Zebra_Session($link, 'sEcUr1tY_c0dE');
     *
     *  //  regenerate the session's ID
     *  $session->regenerate_id();
     *  </code>
     *
     *  @return void
     */
    public function regenerate_id()
    {

        // regenerates the id (create a new session with a new id and containing the data from the old session)
        // also, delete the old session
        session_regenerate_id(true);

    }

    /**
     *  Sets a "flashdata" session variable which will only be available for the next server request, and which will be
     *  automatically deleted afterwards.
     *
     *  Typically used for informational or status messages (for example: "data has been successfully updated").
     *
     *  <code>
     *  // first, connect to a database containing the sessions table
     *
     *  // include the library
     *  require 'path/to/Zebra_Session.php';
     *
     *  //  start the session
     *  //  where $link is a connection link returned by mysqli_connect
     *  $session = new Zebra_Session($link, 'sEcUr1tY_c0dE');
     *
     *  // set "myvar" which will only be available
     *  // for the next server request and will be
     *  // automatically deleted afterwards
     *  $session->set_flashdata('myvar', 'myval');
     *  </code>
     *
     *  Flashdata session variables can be retrieved as any other session variable:
     *
     *  <code>
     *  if (isset($_SESSION['myvar'])) {
     *      // do something here but remember that the
     *      // flashdata session variable is available
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
    public function set_flashdata($name, $value)
    {

        // set session variable
        $_SESSION[$name] = $value;

        // initialize the counter for this flashdata
        $this->flashdata[$name] = 0;

    }

    /**
     *  Deletes all data related to the session
     *
     *  <code>
     *  // first, connect to a database containing the sessions table
     *
     *  //  include the class
     *  require 'path/to/Zebra_Session.php';
     *
     *  //  start the session
     *  //  where $link is a connection link returned by mysqli_connect
     *  $session = new Zebra_Session($link, 'sEcUr1tY_c0dE');
     *
     *  //  end current session
     *  $session->stop();
     *  </code>
     *
     *  @since 1.0.1
     *
     *  @return void
     */
    public function stop()
    {

        $this->regenerate_id();

        session_unset();

        session_destroy();

    }

    /**
     *  Custom close() function
     *
     *  @access private
     */
    function close()
    {

        // release the lock associated with the current session
        $this->_mysql_query('SELECT RELEASE_LOCK("' . $this->session_lock . '")')

            // stop execution and print message on error
            or die($this->_mysql_error());

        return true;

    }

    /**
     *  Custom destroy() function
     *
     *  @access private
     */
    function destroy($session_id)
    {

        // deletes the current session id from the database
        $result = $this->_mysql_query('

            DELETE FROM
                ' . $this->table_name . '
            WHERE
                session_id = "' . $this->_mysql_real_escape_string($session_id) . '"

        ') or die($this->_mysql_error());

        // if anything happened
        // return true
        if ($this->_mysql_affected_rows() !== -1) return true;

        // if something went wrong, return false
        return false;

    }

    /**
     *  Custom gc() function (garbage collector)
     *
     *  @access private
     */
    function gc()
    {

        // deletes expired sessions from database
        $result = $this->_mysql_query('

            DELETE FROM
                ' . $this->table_name . '
            WHERE
                session_expire < "' . $this->_mysql_real_escape_string(time()) . '"

        ') or die($this->_mysql_error());

    }

    /**
     *  Custom open() function
     *
     *  @access private
     */
    function open($save_path, $session_name)
    {

        return true;

    }

    /**
     *  Custom read() function
     *
     *  @access private
     */
    function read($session_id)
    {

        // get the lock name, associated with the current session
        $this->session_lock = $this->_mysql_real_escape_string('session_' . $session_id);

        // try to obtain a lock with the given name and timeout
        $result = $this->_mysql_query('SELECT GET_LOCK("' . $this->session_lock . '", ' . $this->_mysql_real_escape_string($this->lock_timeout) . ')');

        // if there was an error
        // stop execution
        if (!is_object($result) || strtolower(get_class($result)) != 'mysqli_result' || @mysqli_num_rows($result) != 1 || !($row = mysqli_fetch_array($result)) || $row[0] != 1) die('Zebra_Session: Could not obtain session lock!');

        //  reads session data associated with a session id, but only if
        //  -   the session ID exists;
        //  -   the session has not expired;
        //  -   if lock_to_user_agent is TRUE and the HTTP_USER_AGENT is the same as the one who had previously been associated with this particular session;
        //  -   if lock_to_ip is TRUE and the host is the same as the one who had previously been associated with this particular session;
        $hash = '';

        // if we need to identify sessions by also checking the user agent
        if ($this->lock_to_user_agent && isset($_SERVER['HTTP_USER_AGENT']))

            $hash .= $_SERVER['HTTP_USER_AGENT'];

        // if we need to identify sessions by also checking the host
        if ($this->lock_to_ip && isset($_SERVER['REMOTE_ADDR']))

            $hash .= $_SERVER['REMOTE_ADDR'];

        // append this to the end
        $hash .= $this->security_code;

        $result = $this->_mysql_query('

            SELECT
                session_data
            FROM
                ' . $this->table_name . '
            WHERE
                session_id = "' . $this->_mysql_real_escape_string($session_id) . '" AND
                session_expire > "' . time() . '" AND
                hash = "' . $this->_mysql_real_escape_string(md5($hash)) . '"
            LIMIT 1

        ') or die($this->_mysql_error());

        // if anything was found
        if (is_object($result) && strtolower(get_class($result)) == 'mysqli_result' && @mysqli_num_rows($result) > 0) {

            // return found data
            $fields = @mysqli_fetch_assoc($result);

            // don't bother with the unserialization - PHP handles this automatically
            return $fields['session_data'];

        }

        $this->regenerate_id();

        // on error return an empty string - this HAS to be an empty string
        return '';

    }

    /**
     *  Custom write() function
     *
     *  @access private
     */
    function write($session_id, $session_data)
    {

        // insert OR update session's data - this is how it works:
        // first it tries to insert a new row in the database BUT if session_id is already in the database then just
        // update session_data and session_expire for that specific session_id
        // read more here http://dev.mysql.com/doc/refman/4.1/en/insert-on-duplicate.html
        $result = $this->_mysql_query('

            INSERT INTO
                ' . $this->table_name . ' (
                    session_id,
                    hash,
                    session_data,
                    session_expire
                )
            VALUES (
                "' . $this->_mysql_real_escape_string($session_id) . '",
                "' . $this->_mysql_real_escape_string(md5(($this->lock_to_user_agent && isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '') . ($this->lock_to_ip && isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '') . $this->security_code)) . '",
                "' . $this->_mysql_real_escape_string($session_data) . '",
                "' . $this->_mysql_real_escape_string(time() + $this->session_lifetime) . '"
            )
            ON DUPLICATE KEY UPDATE
                session_data = "' . $this->_mysql_real_escape_string($session_data) . '",
                session_expire = "' . $this->_mysql_real_escape_string(time() + $this->session_lifetime) . '"

        ') or die($this->_mysql_error());

        // if anything happened
        if ($result) {

            // note that after this type of queries, mysqli_affected_rows() returns
            // - 1 if the row was inserted
            // - 2 if the row was updated

            // if the row was updated
            // return TRUE
            if (@$this->_mysql_affected_rows() > 1) return true;

            // if the row was inserted
            // return an empty string
            else return '';

        }

        // if something went wrong, return false
        return false;

    }

    /**
     *  Manages flashdata behind the scenes
     *
     *  @access private
     */
    function _manage_flashdata()
    {

        // if there is flashdata to be handled
        if (!empty($this->flashdata)) {

            // iterate through all the entries
            foreach ($this->flashdata as $variable => $counter) {

                // increment counter representing server requests
                $this->flashdata[$variable]++;

                // if we're past the first server request
                if ($this->flashdata[$variable] > 1) {

                    // unset the session variable
                    unset($_SESSION[$variable]);

                    // stop tracking
                    unset($this->flashdata[$variable]);

                }

            }

            // if there is any flashdata left to be handled
            if (!empty($this->flashdata))

                // store data in a temporary session variable
                $_SESSION[$this->flashdata_varname] = serialize($this->flashdata);

        }

    }

    /**
     *  Wrapper for PHP's "mysqli_affected_rows" function.
     *
     *  @access private
     */
    private function _mysql_affected_rows()
    {

        // execute "mysqli_affected_rows" and returns the result
        return mysqli_affected_rows($this->link);

    }

    /**
     *  Wrapper for PHP's "mysqli_error" function.
     *
     *  @access private
     */
    private function _mysql_error()
    {

        // execute "mysqli_error" and returns the result
        return 'Zebra_Session: ' . mysqli_error($this->link);

    }

    /**
     *  Wrapper for PHP's "mysqli_query" function.
     *
     *  @access private
     */
    private function _mysql_query($query)
    {

        // execute "mysqli_query" and returns the result
        return mysqli_query($this->link, $query);

    }

    /**
     *  Wrapper for PHP's "mysqli_ping" function.
     *
     *  @access private
     */
    private function _mysql_ping()
    {

        // execute "mysqli_ping" and returns the result
        return mysqli_ping($this->link);

    }

    /**
     *  Wrapper for PHP's "mysqli_real_escape_string" function.
     *
     *  @access private
     */
    private function _mysql_real_escape_string($string)
    {

        // execute "mysqli_real_escape_string" and returns the result
        return mysqli_real_escape_string($this->link, $string);

    }

}

?>
