<?php
// create_hash.php
// Use this file ONCE, then DELETE IT.

error_reporting(E_ALL);
ini_set('display_errors', 1);

$passwordToHash = 'adminpass';
$hashedPassword = password_hash($passwordToHash, PASSWORD_DEFAULT);

if ($hashedPassword === false) {
    echo "Hashing failed! Your PHP environment might be missing the 'password_hash' function, but this is very unlikely.";
} else {
    echo "Copy this entire line and paste it into your SQL query:<br><br>";
    echo '<textarea rows="3" cols="80" readonly>' . $hashedPassword . '</textarea>';
}
?>