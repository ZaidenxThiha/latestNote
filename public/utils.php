<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

function sendEmail($to, $toName, $subject, $htmlBody, $textBody) {
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'mailhog';
        $mail->Port = 1025;
        $mail->SMTPAuth = false;
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;

        // Sender and recipient
        $mail->setFrom('no-reply@noteapp.com', 'My Note');
        $mail->addAddress($to, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        error_log("Email sent to $to for subject: $subject");
        return true;
    } catch (Exception $e) {
        error_log("Failed to send email to $to: {$mail->ErrorInfo}");
        return false;
    }
}
?>