<?php

require_once __DIR__ . '/Zebra_Session_Store.php';

class Zebra_Session_SQLServerStore implements Zebra_Session_Store
{

    private $link;
    private $table_name;


    public function __construct(&$link, $table_name = 'session_data')
    {
        $this->link = $link;
        $this->table_name = $table_name;
    }

    /**
     * Is the store valid or ready to use.
     * @return true / false.
     */
    public function isvalid()
    {
        return ($this->link instanceof PDO);
    }

    /**
     * Acquire lock on session key ($lock) within given $timeout.
     * @return true / false
     * @throws Exception
     */
    public function acquireLock($lock, $timeout)
    {
        $result = $this->query("DECLARE @result int;
        EXEC @result = sp_getapplock @Resource =  ?,
       @LockMode =  'Exclusive', @LockOwner = 'Session', @LockTimeout = ?, @DbPrincipal = 'public';
       SELECT @result AS result", $lock, $timeout * 1000);

        // stop if there was an error
        if ($result !== false && $result['data']['result'] < 0) {
            throw new Exception('Zebra_Session: Could not obtain session lock');
        }
    }

    /**
     * Release lock on session key ($lock)
     * @return true / false
     * @throws Exception
     */
    public function releaseLock($lock)
    {
        $result = $this->query("DECLARE @result int;
        EXEC @result = sp_releaseapplock @Resource =  ?, @LockOwner = 'Session', @DbPrincipal = 'public';
        SELECT @result AS result", $lock);

        // stop if there was an error
        if ($result !== false && $result['data']['result'] < 0) {
            throw new Exception('Zebra_Session: Could not obtain session lock');
        }
    }

    /**
     * Read session data.
     * @return string (or '' on error)
     */
    public function readSessionData($session_id, $hash)
    {
        $result = $this->query('
            SELECT TOP 1
                session_data
            FROM
                ' . $this->table_name . '
            WHERE
                session_id = ?
                AND session_expire > ?
                AND hash = ?
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

    /**
     * Save session data.
     * @return true / false.
     */
    public function writeSessionData($session_id, $hash, $session_data, $session_expire)
    {
        return $this->query('
            MERGE INTO 
                ' . $this->table_name . ' WITH (HOLDLOCK) AS target
            USING (SELECT
                    ? AS session_id,
                    ? AS hash,
                    ? AS session_data,
                    ? AS session_expire) AS source
                (
                    session_id,
                    hash,
                    session_data,
                    session_expire
                )
                ON (target.session_id = source.session_id)
                WHEN MATCHED
                    THEN UPDATE
                        SET session_data = source.session_data,
                        session_expire = source.session_expire
                WHEN NOT MATCHED
                    THEN INSERT (session_id, hash, session_data, session_expire)
                    VALUES (source.session_id, source.hash, source.session_data, source.session_expire);
            ',
                $session_id,
                $hash,
                $session_data,
                $session_expire
            ) !== false;
    }

    /**
     * Delete session data with id.
     * @return true / false.
     */
    public function deleteSession($session_id)
    {
        return $this->query('
            DELETE FROM
                ' . $this->table_name . '
            WHERE
                session_id = ?
        ', $session_id) !== false;
    }

    /**
     * Delete all the expired sessions until current time.
     */
    public function deleteExpiredSessions()
    {
        $this->query('
            DELETE FROM
                ' . $this->table_name . '
            WHERE
                session_expire < ?
        ', time());

        return true;
    }

    /**
     * Count number of active session until current time.
     * @return integer
     */
    public function countActiveSessions()
    {
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
     *  Mini-wrapper for running T-SQL queries with parameter binding with PDO
     *
     * @param string $query The T-SQL query to execute
     *
     * @return mixed
     *
     * @access private
     */
    private function query($query)
    {

        // if the provided connection link is a PDO instance
        if ($this->link instanceof PDO) {

            // if executing the query was a success
            if (($stmt = $this->link->prepare($query, [PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL])) && $stmt->execute(array_slice(func_get_args(), 1))) {

                // prepare a standardized return value
                $result = array(
                    'num_rows' => $stmt->rowCount(),
                    'data' => $stmt->columnCount() == 0 ? array() : $stmt->fetch(PDO::FETCH_ASSOC),
                );

                // close the statement
                $stmt->closeCursor();

                // return result
                return $result;

            }
        }
    }
}
