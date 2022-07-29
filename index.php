<?php
require_once('vendor/autoload.php');

if (file_exists('.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

//Check .env Variables
function setEnvDefault($envName, $envRequired, $envDefault = '')
{
    if ((getenv($envName) == false && !isset($_ENV[$envName])) && $envRequired === true) {
        throw new Exception('ENV missing: ' . $envName);
    } else if (!isset($_ENV[$envName])) {
        $_ENV[$envName] = $envDefault;
    }
}

try {
    setEnvDefault('LO_API_KEY', true);
    setEnvDefault('IMAP_HOST', true);
    setEnvDefault('IMAP_USER', true);
    setEnvDefault('IMAP_PASS', true);
    setEnvDefault('IMAP_PORT', false, '993');
    setEnvDefault('IMAP_PATH', false, '/imap/ssl');
    setEnvDefault('IMAP_FOLDER', false, 'INBOX');
    setEnvDefault('DELETE_MAIL', false, 'false');
    setEnvDefault('MOVE_MAIL', false, 'false');
    setEnvDefault('MOVE_MAIL_PATH', true, 'INBOX.Lexoffice');
    setEnvDefault('TEST_MODE', false, 'false');
} catch (Exception $e) {
    die($e->getMessage() . PHP_EOL);
}

$attachmetsDir = __DIR__ . DIRECTORY_SEPARATOR . 'attachments';

if (!is_dir($attachmetsDir)) {
    mkdir($attachmetsDir, 0777, true);
}

// Create PhpImap\Mailbox instance for all further actions
$mailbox = new PhpImap\Mailbox(
    sprintf('{%s:%s%s}%s', $_ENV['IMAP_HOST'], $_ENV['IMAP_PORT'], $_ENV['IMAP_PATH'], $_ENV['IMAP_FOLDER']), // IMAP server and mailbox folder
    $_ENV['IMAP_USER'], // Username for the before configured mailbox
    $_ENV['IMAP_PASS'], // Password for the before configured username
    $attachmetsDir, // Directory, where attachments will be saved (optional)
    'UTF-8' // Server encoding (optional)
);

// set some connection arguments (if appropriate)
$mailbox->setConnectionArgs(
    CL_EXPUNGE // don't do non-secure authentication
);

if ($_ENV['TEST_MODE'] == 'true') {
    echo '### TEST MODE ON ###' . PHP_EOL;
}

while (true) {

    try {
        $mailsIds = $mailbox->searchMailbox('UNSEEN');
    } catch (PhpImap\Exceptions\ConnectionException $ex) {
        throw new Exception("IMAP connection failed: " . $ex->getMessage());
    }

    if (!$mailsIds) {
        echo '[LOG] Mailbox is empty...wait 2 seconds' . PHP_EOL;
        sleep(2);
        continue;
    }

    foreach ($mailsIds as $mailId) {
        $mail = $mailbox->getMail($mailId);

        echo '[MAIL] Found mail from ' . $mail->fromAddress . PHP_EOL;

        if ($mail->hasAttachments()) {

            $attachments = $mail->getAttachments();

            echo '[INFO] Found ' . count($attachments) . ' attachments' . PHP_EOL;


            //send to lexoffice :)
            foreach ($attachments as $attachment) {

                if ($attachment->subtype == 'ZIP') {
                    echo '[INFO] Found zip-File...extract necessary' . PHP_EOL;
                    $zip = new ZipArchive;
                    $extractTo = str_replace('.bin', '', $attachment->filePath);
                    if ($zip->open($attachment->filePath) === TRUE) {
                        $zip->extractTo($extractTo . DIRECTORY_SEPARATOR);
                        $zip->close();
                        echo '[INFO] zip-File successfully extracted' . PHP_EOL;

                        $files = scandir($extractTo);
                        foreach ($files as $file) {
                            if (str_contains($file, '.pdf')) {
                                $attachmentPath = $extractTo . DIRECTORY_SEPARATOR . $file;
                                echo '[INFO] send extracted file: ' . $attachmentPath . PHP_EOL;
                                if ($_ENV['TEST_MODE'] == 'false') {
                                    exec('curl https://api.lexoffice.io/v1/files -X POST -H "Authorization: Bearer ' . $_ENV['LO_API_KEY'] . '" -H "Content-Type: multipart/form-data" -H "Accept: application/json" -F "file=@' . $attachmentPath . '" -F "type=voucher"');
                                }
                            }
                        }
                        rename($attachment->filePath, str_replace('.bin', '.zip', $attachment->filePath));

                    } else {
                        echo '[ERROR] Extracting failed...' . PHP_EOL;
                    }
                } else {
                    rename($attachment->filePath, str_replace('.bin', '.pdf', $attachment->filePath));
                    $attachmentPath = str_replace('.bin', '.pdf', $attachment->filePath);

                    if ($_ENV['TEST_MODE'] == 'false') {
                        exec('curl https://api.lexoffice.io/v1/files -X POST -H "Authorization: Bearer ' . $_ENV['LO_API_KEY'] . '" -H "Content-Type: multipart/form-data" -H "Accept: application/json" -F "file=@' . $attachmentPath . '" -F "type=voucher"');
                    }
                }
            }
        } else {
            echo '[INFO] Mail has no attachments' . PHP_EOL;
        }

        if ($_ENV['TEST_MODE'] == 'false') {
            if ($_ENV['DELETE_MAIL'] == 'true') {
                $mailbox->deleteMail($mail->id);
                echo '[INFO] Mail deleted' . PHP_EOL;
                $mailbox->expungeDeletedMails();
            }
            if ($_ENV['MOVE_MAIL'] == 'true') {
                $mailbox->moveMail($mail->id, $_ENV['MOVE_MAIL_PATH']);
                echo '[INFO] Mail moved to: ' . $_ENV['MOVE_MAIL_PATH'] . PHP_EOL;
            }
        }
    }
}

