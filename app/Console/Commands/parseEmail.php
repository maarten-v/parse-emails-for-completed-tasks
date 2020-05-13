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
    const COMPLETEDASANAEMAILSFOLDER = 'Completed Asana tasks';
    const MERGEDMRSEMAILSFOLDER = 'Merged MRs';
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
        $this->mailbox->setAttachmentsIgnore(true);

        $this->rootMailbox = new Mailbox(
            '{' . env('EMAIL_HOSTNAME') . ':993/imap/ssl}', // IMAP server and mailbox folder
            env('EMAIL_USERNAME'), // Username for the before configured mailbox
            env('EMAIL_PASSWORD'), // Password for the before configured username
            null, // Directory, where attachments will be saved (optional)
            'UTF-8' // Server encoding (optional)
        );
        $this->rootMailbox->setAttachmentsIgnore(true);
        $mailboxes = ($this->rootMailbox->getListingFolders());

        if (in_array($mailserver . self::COMPLETEDASANAEMAILSFOLDER, $mailboxes, true) === false) {
            $this->info('Creating mailbox for parsed Asana emails');
            $this->info('');
            $this->rootMailbox->createMailbox(self::COMPLETEDASANAEMAILSFOLDER);
        }

        if (in_array($mailserver . self::MERGEDMRSEMAILSFOLDER, $mailboxes, true) === false) {
            $this->info('Creating mailbox for parsed Gitlab emails');
            $this->info('');
            $this->rootMailbox->createMailbox(self::MERGEDMRSEMAILSFOLDER);
        }

        $this->client = new Client();

        try {
            // Get all emails (messages)
            // PHP.net imap_search criteria: http://php.net/manual/en/function.imap-search.php
            $mailsIds = $this->mailbox->searchMailbox('FROM gitlab@maximum.nl');
        } catch (ConnectionException $ex) {
            echo "IMAP connection failed: " . $ex;
            die();
        }

        // If $mailsIds is empty, no emails could be found
        if (!$mailsIds) {
            die('No emails from Asana found in Inbox');
        }

        foreach ($mailsIds as $mailId) {
            $this->processEmailForMergedMR($mailId);
        }

        try {
            // Get all emails (messages)
            // PHP.net imap_search criteria: http://php.net/manual/en/function.imap-search.php
            $mailsIds = $this->mailbox->searchMailbox('FROM mail.asana.com');
        } catch (ConnectionException $ex) {
            echo "IMAP connection failed: " . $ex;
            die();
        }

        if (in_array($mailserver . self::COMPLETEDASANAEMAILSFOLDER, $mailboxes, true) === false) {
            $this->info('Creating mailbox for parsed emails');
            $this->info('');
            $this->rootMailbox->createMailbox(self::COMPLETEDASANAEMAILSFOLDER);
        }

        // If $mailsIds is empty, no emails could be found
        if (!$mailsIds) {
            die('No emails from Asana found in Inbox');
        }

        foreach ($mailsIds as $mailId) {
            $this->parseAsanaEmail($mailId);
        }

        return null;
    }

    private function parseAsanaEmail($mailId)
    {
        $this->info('');
        $email = $this->mailbox->getMail($mailId, false);
        $this->info('Subject: ' . $this->mailbox->decodeMimeStr($email->headers->subject));
        $mailText = $email->textHtml;
        if (preg_match('/taskId\': \'(?<digit>\d+)/', $mailText, $regexResults) === 0) {
            $this->info('<fg=yellow>No modern task id found</>');
            if (preg_match('/(?<digit>\d+)%2Ff&hash=/', $mailText, $regexResults) === 0) {
                $this->info('<fg=yellow>No old task id found</>');
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
            $this->moveEmail($mailId);
            return;
        }
        $asanaResultJson = json_decode($asanaResult->getBody());
        if ($asanaResultJson->data->completed) {
            $this->info('Completed!');
            $this->moveEmail($mailId, self::COMPLETEDASANAEMAILSFOLDER);
        } else {
            $this->info('<fg=yellow>Task not completed yet</>');
        }
    }

    private function processEmailForMergedMR($mailId) {
        $this->info('');
        $email = $this->mailbox->getMail($mailId, false);
        $this->info('Subject: ' . $this->mailbox->decodeMimeStr($email->headers->subject));
        if (preg_match('/X-GitLab-Project-Id: (?<digit>\d+)/', $email->headersRaw, $regexResultProjectId) === 0) {
            $this->info('<fg=yellow>No project id found</>');
            return;
        }
        if (preg_match('/X-GitLab-MergeRequest-IID: (?<digit>\d+)/', $email->headersRaw, $regexResultMRId) === 0) {
            $this->info('<fg=yellow>No merge request id found</>');
            return;
        }
        $projectId = $regexResultProjectId['digit'];
        $MRId = $regexResultMRId['digit'];

        try {
            $gitlabResult = $this->client->request(
                'GET',
                'https://git.maximum.nl/api/v4/projects/'. $projectId. '/merge_requests/'. $MRId,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . env('GITLAB_TOKEN'),
                    ],
                ]
            );
        } catch (ClientException $e) {
            $this->error('Error connecting with Gitlab');
            $this->error($e);
            return;
        }

        $gitlabResultJson = json_decode($gitlabResult->getBody());
        $state = $gitlabResultJson->state;
        $this->info('<fg=yellow>State: '. $state. '</>');
        if ($state === 'merged' || $state === 'closed') {
            $this->moveEmail($mailId, self::MERGEDMRSEMAILSFOLDER);
        }

    }

    private function moveEmail($mailId, $folder)
    {
        $this->info('<fg=magenta>Moving email to other mailbox</>');
        $this->mailbox->moveMail($mailId, $folder);
    }

}
