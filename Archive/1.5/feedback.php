<?php
date_default_timezone_set("America/Chicago");

// Email settings
$to = "dthroldahl@3playmedia.com, mmclaren@3playmedia.com";
$from = "Winter-2026-NER@dereksprojects.com";
$subject = "Winter 2026 NER Started";
$headers = "From:" . $from;

// Get POSTed JSON
$data = json_decode(file_get_contents("php://input"), true);

if (!empty($data["feedback"])) {
    $feedback = $data["feedback"];
    $timestamp = date("m/d/Y h:i:sa") . " CT";
    $logEntry = "[$timestamp] $feedback has started their assessment." . PHP_EOL;

    // Write to feedback.txt
    file_put_contents("feedback.txt", $logEntry, FILE_APPEND | LOCK_EX);

    // Email with timestamp in body
    $message = $logEntry;
    mail($to, $subject, $message, $headers);
}
?>
