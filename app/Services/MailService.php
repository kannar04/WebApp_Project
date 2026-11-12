<?php
declare(strict_types=1);
namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;

final class MailService
{
    public function configured(): bool
    {
        $config=require dirname(__DIR__,2).'/config/mail.php';
        if(!$config['enabled']||!filter_var($config['from'],FILTER_VALIDATE_EMAIL)||!class_exists(PHPMailer::class)){return false;}
        if($config['transport']==='mail'){return function_exists('mail');}
        return $config['transport']==='smtp' && $config['smtp_host']!==''
            && $config['smtp_port']>0 && $config['smtp_port']<=65535
            && in_array($config['smtp_encryption'],['tls','ssl','none'],true)
            && ($config['smtp_username']==='' || $config['smtp_password']!=='')
            && ($config['smtp_ca_file']==='' || is_file($config['smtp_ca_file']));
    }

    /** True means accepted by the configured transport, not guaranteed inbox delivery. */
    public function send(string $recipient,string $subject,string $text,?string $html=null,?string $messageId=null): bool
    {
        if(!$this->configured()||!filter_var($recipient,FILTER_VALIDATE_EMAIL)){return false;}
        $config=require dirname(__DIR__,2).'/config/mail.php';
        try {
            $mail=new PHPMailer(true);
            $mail->CharSet=PHPMailer::CHARSET_UTF8;
            $mail->Encoding=PHPMailer::ENCODING_BASE64;
            $mail->Timeout=10;
            $mail->getSMTPInstance()->Timelimit=10;
            $mail->SMTPDebug=0;
            if($config['transport']==='smtp'){
                $mail->isSMTP();
                $mail->Host=$config['smtp_host'];$mail->Port=$config['smtp_port'];
                $mail->SMTPAuth=$config['smtp_username']!=='';
                $mail->Username=$config['smtp_username'];$mail->Password=$config['smtp_password'];
                $mail->SMTPSecure=match($config['smtp_encryption']){'tls'=>PHPMailer::ENCRYPTION_STARTTLS,'ssl'=>PHPMailer::ENCRYPTION_SMTPS,default=>''};
                $mail->SMTPAutoTLS=$config['smtp_encryption']!=='none';
                if($config['smtp_ca_file']!==''){$mail->SMTPOptions=['ssl'=>['cafile'=>$config['smtp_ca_file']]];}
            } else {$mail->isMail();}
            $mail->setFrom($config['from'],$config['from_name']);
            $mail->addAddress($recipient);
            $mail->Subject=$subject;
            if($messageId!==null){$mail->MessageID=$messageId;}
            if($html!==null){$mail->isHTML(true);$mail->Body=$html;$mail->AltBody=$text;}
            else{$mail->Body=$text;}
            return $mail->send();
        } catch(\Throwable $exception) {
            // SMTP errors may contain addresses, credentials or message contents. Keep them out of logs.
            error_log('Home2Home mail transport failed ('.$exception::class.').');
            return false;
        }
    }
}
