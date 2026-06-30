<?php

// LINK TO DATABASE  
// $mysqli = mysqli_connect("localhost","my_user","my_password","my_db");
$link = mysqli_connect("localhost","u587538405_derek","c@ctDLJzH1K","u587538405_dereksprojects");

// CONNECTION VALIDATION
if(mysqli_connect_error()) {
  die("There was an error connecting to the database." . "<br>");
} else {
  echo "Database connection successful!" . "<br>";
}

date_default_timezone_set("America/Chicago");
$nowDateTime = date('y-m-d H:i:s');
$data = json_decode(file_get_contents("php://input"), true);

// QUERY TO INSERT
// if ($_SERVER["REQUEST_METHOD"] == "POST") {
//   // USER INPUT
//   $dateTime = $data["dateTime"];
//   $added = $data["added"];
//   $new_note = $data["note"];

//   // Prepare a SQL statement to prevent SQL injection
//   $stmt = $link->prepare("INSERT INTO DateTester (date, added, note) VALUES (?,?,?)");

//   // Bind parameters
//   $stmt->bind_param("sss", $nowDateTime, $added, $new_note);

//   // Execute the statement
//   if ($stmt->execute()) {
//       echo "New record created successfully";
//   } else {
//       echo "Error: " . $stmt->error;
//   }

//   // Close the statement
//   $stmt->close();
// }


// // GET REQUESTS
// if ($_SERVER["REQUEST_METHOD"] == "GET") {
//   $sql = "SELECT * FROM DateTester ORDER BY id DESC LIMIT 1";
//   $result = $link->query($sql);
  
//   $response = array();
  
//   if ($result->num_rows > 0) {
//       while($row = $result->fetch_assoc()) {
//           $response['date'] = $row['date'];
//           $response['day'] = $row['note'];
//       }
//   } else {
//       $response['date'] = null;
//   }
//   header('Content-Type: application/json');
//   echo json_encode($response);
// }


// PASSWORD MANAGEMENT
// $hash = password_hash("mypassword", PASSWORD_DEFAULT)
// if (password_verify("mypassword", $hash)) {
//   echo "Password Verified"
// } else {
//   echo "Invalid Password"
// }

// VALIDATION OF PHP COMPLETION
echo "End of File" . "<br>"

// Mysqli_real_escape_string($link,$name)
?>