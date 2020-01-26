<?php

    // first create the required MySQL table that is used by this class
    // you can do that by running in MySQL the 'session_data.sql' file
    // from the 'install' folder!

    // change the values to match the setting of your MySQL database
    $host = '';
    $username = '';
    $password = '';

    // this is the name of the database where you created the table used by this class
    $database = '';

    // create connection string
    $dsn = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4';

    // try to connect to the MySQL server using PDO
    try {
        $pdo = new PDO($dsn, $username, $password, array(
            PDO::ATTR_ERRMODE   =>  PDO::ERRMODE_EXCEPTION,
        ));
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }

    // include the Zebra_Session class
    require '../Zebra_Session.php';

    // instantiate the class
    // note that you don't need to call the session_start() function
    // as it is called automatically when the object is instantiated
    // also note that we are passing the PDO instance as the first argument
    $session = new Zebra_Session($pdo, 'sEcUr1tY_c0dE', '', true, false, 1000);

    // current session settings
    print_r('<pre><strong>Current session settings:</strong><br><br>');
    print_r($session->get_settings());
    print_r('</pre>');

    // from now on, use sessions as you would normally
    // the only difference is that session data is no longer saved on the server
    // but in your database

    print_r('
        The first time you run the script there should be an empty array (as there\'s nothing in the $_SESSION array)<br>
        After you press "refresh" on your browser, you will se the values that were written in the $_SESSION array<br>
    ');

    print_r('<pre>');
    print_r($_SESSION);
    print_r('</pre>');

    // add some values to the session
    $_SESSION['value1'] = 'hello';
    $_SESSION['value2'] = 'world';

    // now check the table and see that there is data in it!

    // to completely delete a session un-comment the following line
    // $session->stop();

?>