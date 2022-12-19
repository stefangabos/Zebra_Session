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
     * @throws Exception
	 */
	public function acquireLock($lock, $timeout);

	/**
	 * Release lock on session key ($lock)
	 * @return true / false
     * @throws Exception
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
