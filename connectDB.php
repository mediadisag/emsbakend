<?php
/* Database connection settings */
	$servername = "localhost";
    $username = "u944310928_ems";		//put your phpmyadmin username.(default is "root")
    $password = "9pv6A@xv4U*V8a2";			//if your phpmyadmin has a password put it here.(default is "root")
    $dbname = "u944310928_ems";
    
	$conn = mysqli_connect($servername, $username, $password, $dbname);
	
	if ($conn->connect_error) {
        die("Database Connection failed: " . $conn->connect_error);
    }
?>