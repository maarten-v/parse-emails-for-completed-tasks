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
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parse-email';

    private Mailbox $mailbox;

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
        $this->mailbox = new Mailbox(
            '{mail.maximum.nl:993/imap/ssl}INBOX', // IMAP server and mailbox folder
            env('EMAIL_USERNAME'), // Username for the before configured mailbox
            env('EMAIL_PASSWORD'), // Password for the before configured username
            __DIR__, // Directory, where attachments will be saved (optional)
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

        // If $mailsIds is empty, no emails could be found
        if (!$mailsIds) {
            die('Mailbox is empty');
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
        $email = $this->mailbox->getMail($mailId);
        $this->info('Subject: ' . $this->mailbox->decodeMimeStr($email->headers->subject));
        $mailText = $email->textHtml;
        if (preg_match('/taskId\': \'(?<digit>\d+)/', $mailText, $regexResults) === 0) {
            $this->info('no task id found');
            return;
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
            $this->processEmailForCompletedTask($email);
            return;
        }
        $asanaResultJson = json_decode($asanaResult->getBody());
        if ($asanaResultJson->data->completed) {
            $this->info('Completed!');
            $this->processEmailForCompletedTask($email);
        }
    }

    private function processEmailForCompletedTask(IncomingMail $email) {
        $this->info('doing something with this email');
    }

}
