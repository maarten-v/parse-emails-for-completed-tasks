<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Command;
use PhpImap\Exceptions\ConnectionException;
use PhpImap\Exceptions\InvalidParameterException;
use PhpImap\IncomingMail;
use PhpImap\Mailbox;

class parseEmail extends Command
{
    const COMPLETEDEMAILSFOLDER = 'Completed-asana-tasks';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parse-email';

    private Mailbox $mailbox;
    private Mailbox $rootMailbox;

    private Client $client;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws InvalidParameterException
     */
    public function handle()
    {
        $mailserver = '{' . env('EMAIL_HOSTNAME') . ':993/imap/ssl}';
        $this->mailbox = new Mailbox(
            $mailserver . 'INBOX', // IMAP server and mailbox folder
            env('EMAIL_USERNAME'), // Username for the before configured mailbox
            env('EMAIL_PASSWORD'), // Password for the before configured username
            null, // Directory, where attachments will be saved (optional)
            'UTF-8' // Server encoding (optional)
        );

        try {
            // Get all emails (messages)
            // PHP.net imap_search criteria: http://php.net/manual/en/function.imap-search.php
            $mailsIds = $this->mailbox->searchMailbox('FROM mail.asana.com');
        } catch (ConnectionException $ex) {
            echo "IMAP connection failed: " . $ex;
            die();
        }

        $this->rootMailbox = new Mailbox(
            '{' . env('EMAIL_HOSTNAME') . ':993/imap/ssl}', // IMAP server and mailbox folder
            env('EMAIL_USERNAME'), // Username for the before configured mailbox
            env('EMAIL_PASSWORD'), // Password for the before configured username
            null, // Directory, where attachments will be saved (optional)
            'UTF-8' // Server encoding (optional)
        );
        $this->rootMailbox->setAttachmentsIgnore(true);

        $mailboxes = ($this->rootMailbox->getListingFolders());

        if (in_array($mailserver . self::COMPLETEDEMAILSFOLDER, $mailboxes, true) === false) {
            $this->info('Creating mailbox for parsed emails');
            $this->info('');
            $this->rootMailbox->createMailbox(self::COMPLETEDEMAILSFOLDER);
        }

        // If $mailsIds is empty, no emails could be found
        if (!$mailsIds) {
            die('No emails from Asana found in Inbox');
        }

        $this->mailbox->setAttachmentsIgnore(true);

        $this->client = new Client();

        foreach ($mailsIds as $mailId) {
            $this->parseEmail($mailId);
        }

        return;
    }

    private function parseEmail($mailId)
    {
        $this->info('');
        $email = $this->mailbox->getMail($mailId, false);
        $this->info('Subject: ' . $this->mailbox->decodeMimeStr($email->headers->subject));
        $mailText = $email->textHtml;
        if (preg_match('/taskId\': \'(?<digit>\d+)/', $mailText, $regexResults) === 0) {
            $this->info('No modern task id found');
            if (preg_match('/(?<digit>\d+)%2Ff&hash=/', $mailText, $regexResults) === 0) {
                $this->info('No old task id found');
                return;
            }
            $this->info('Old task id found');
        }
        $taskId = $regexResults['digit'];


        try {
            $asanaResult = $this->client->request(
                'GET',
                'https://app.asana.com/api/1.0/tasks/' . $taskId,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . env('ASANA_TOKEN'),
                    ],
                ]
            );
        } catch (ClientException $e) {
            if ($e->getCode() == 403) {
                $this->info('No access to this task, probably old and removed');
            }
            $this->processEmailForCompletedTask($mailId);
            return;
        }
        $asanaResultJson = json_decode($asanaResult->getBody());
        if ($asanaResultJson->data->completed) {
            $this->info('Completed!');
            $this->processEmailForCompletedTask($mailId);
        } else {
            $this->info('<fg=yellow>Task not completed yet</>');
        }
    }

    private function processEmailForCompletedTask($mailId)
    {
        $this->info('<fg=magenta>Moving email to other mailbox</>');
        $this->mailbox->moveMail($mailId, self::COMPLETEDEMAILSFOLDER);
    }

}
