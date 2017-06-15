<?php
/* 
 * Interface to allow integration with different options. 
 * Author: Prasad
 */
interface Zebra_Session_Store {
	/**
	 * Is the store valid or ready to use. 
	 * @return true / false.
	 */
	public function isvalid();

	/**
	 * Acquire lock on session key ($lock) within given $timeout.
	 * @return true / false
	 */
	public function acquireLock($lock, $timeout);

	/**
	 * Release lock on session key ($lock)
	 * @return true / false
	 */
	public function releaseLock($lock);

	/**
	 * Read session data.
	 * @return string (or '' on error)
	 */
	public function readSessionData($session_id, $hash);

	/**
	 * Save session data.
	 * @return true / false.
	 */
	public function writeSessionData($session_id, $hash, $session_data, $session_expire);

	/**
	 * Delete session data with id.
	 * @return true / false.
	 */
	public function deleteSession($session_id);

	/**
	 * Delete all the expired sessions until current time.
	 */
	public function deleteExpiredSessions();

	/**
	 * Count number of active session until current time.
	 * @return integer
	 */
	public function countActiveSessions();
}

/* 
 * Default Store implementation for MySQLi 
 * Pulled from: https://github.com/stefangabos/Zebra_Session
 */
class Zebra_Session_MySQLiStore implements Zebra_Session_Store {

	private $link;
	private $table_name;

	public function __construct(&$link, $table_name = 'session_data') {
		$this->link = $link;
		$this->table_name = $table_name;
	}

	public function isValid() {
		return ($this->link instanceof MySQLi && $this->link->connect_error === null);
	}

	public function acquireLock($lock, $timeout) {
		$result = $this->_mysql_query('SELECT GET_LOCK("' . $this->_mysql_real_escape_string($lock) . '", ' . $this->_mysql_real_escape_string($timeout) . ')');
		return (!$result || mysqli_num_rows($result) != 1 || !($row = mysqli_fetch_array($result)) || $row[0] != 1) ? false : true;
	}

	public function releaseLock($lock) {
		if ($this->_mysql_query('SELECT RELEASE_LOCK("' . $lock . '")'))
			return true;
	}

	public function readSessionData($session_id, $hash) {
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
        ');

		// if anything was found
        if ($result && mysqli_num_rows($result) > 0) {

            // return found data
            $fields = mysqli_fetch_assoc($result);

            // don't bother with the unserialization - PHP handles this automatically
            return $fields['session_data'];

        }

        // on error return an empty string - this HAS to be an empty string
        return '';
	}

	public function writeSessionData($session_id, $hash, $session_data, $session_expire) {
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
                "' . $this->_mysql_real_escape_string($hash) . '",
                "' . $this->_mysql_real_escape_string($session_data) . '",
                "' . $this->_mysql_real_escape_string($session_expire) . '"
            )
            ON DUPLICATE KEY UPDATE
                session_data = "' . $this->_mysql_real_escape_string($session_data) . '",
                session_expire = "' . $this->_mysql_real_escape_string($session_expire) . '"

        ');

        return $result;
	}

	public function deleteSession($session_id) {
		$this->_mysql_query('

            DELETE FROM
                ' . $this->table_name . '
            WHERE
                session_id = "' . $this->_mysql_real_escape_string($session_id) . '"

        ');

        // return true if everything went well
        return ($this->_mysql_affected_rows() !== -1);
	}

	public function deleteExpiredSessions() {
		$this->_mysql_query('

            DELETE FROM
                ' . $this->table_name . '
            WHERE
                session_expire < "' . $this->_mysql_real_escape_string(time()) . '"

        ');
	}

	public function countActiveSessions() {
		$result = mysqli_fetch_assoc($this->_mysql_query('

            SELECT
                COUNT(session_id) as count
            FROM ' . $this->table_name . '

        '));

        // return the number of found rows
        return $result['count'];
	}

	/**
     *  Wrapper for PHP's "mysqli_affected_rows" function.
     *
     *  @access private
     */
    private function _mysql_affected_rows() {

        // call "mysqli_affected_rows" and return the result
        return mysqli_affected_rows($this->link);

    }

    /**
     *  Wrapper for PHP's "mysqli_error" function.
     *
     *  @access private
     */
    private function _mysql_error() {

        // call "mysqli_error" and return the result
        return 'Zebra_Session: ' . mysqli_error($this->link);

    }

    /**
     *  Wrapper for PHP's "mysqli_query" function.
     *
     *  @access private
     */
    private function _mysql_query($query) {

        // call "mysqli_query"
        $result = mysqli_query($this->link, $query)

            // stop if there was an error
            or die($this->_mysql_error());

        // return the result if query was successful
        return $result;

    }

    /**
     *  Wrapper for PHP's "mysqli_real_escape_string" function.
     *
     *  @access private
     */
    private function _mysql_real_escape_string($string) {

        // call "mysqli_real_escape_string" and return the result
        return mysqli_real_escape_string($this->link, $string);

    }
}
