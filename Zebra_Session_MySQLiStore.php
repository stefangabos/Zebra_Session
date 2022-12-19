<?php

require_once __DIR__ . '/Zebra_Session_Store.php';

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
        return ($this->link instanceof MySQLi && $this->link->connect_error === null) || $this->link instanceof PDO;
    }

    public function acquireLock($lock, $timeout) {
        $result = $this->query('SELECT GET_LOCK(?, ?)', $lock, $timeout);

        // stop if there was an error
        if ($result['num_rows'] != 1) {
            throw new Exception('Zebra_Session: Could not obtain session lock');
        }
    }

    public function releaseLock($lock) {
        return $this->query('
            SELECT
                RELEASE_LOCK(?)
        ', $lock) !== false;
    }

    public function readSessionData($session_id, $hash) {
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
        $this->deleteSession($session_id);

        // on error return an empty string - this HAS to be an empty string
        return '';

    }

    public function writeSessionData($session_id, $hash, $session_data, $session_expire) {
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
                $hash,
                $session_data,
                $session_expire
            ) !== false;
    }

    public function deleteSession($session_id) {
        return $this->query('
            DELETE FROM
                ' . $this->table_name . '
            WHERE
                session_id = ?
        ', $session_id) !== false;
    }

    public function deleteExpiredSessions() {
        $this->query('
            DELETE FROM
                ' . $this->table_name . '
            WHERE
                session_expire < ?
        ', time());

        return true;
    }

    public function countActiveSessions() {
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
}
