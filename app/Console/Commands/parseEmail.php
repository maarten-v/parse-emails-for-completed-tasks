<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Command;
use PhpImap\Exceptions\ConnectionException;
use PhpImap\Exceptions\InvalidParameterException;
use PhpImap\IncomingMail;
use PhpImap\Mailbox;
use Psr\Http\Message\ResponseInterface;

class parseEmail extends Command
{
    private const COMPLETEDASANAEMAILSFOLDER = 'Completed Asana tasks';
    private const MERGEDMRSEMAILSFOLDER = 'Merged MRs';
    private const COMPLETEDHACKERONEREPORTS = 'Completed Hackerone reports';
    private const CLOSEDOPSGENIEALERTS = 'Closed Opsgenie alerts';

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
    protected $description = 'Move emails from completed Asana tasks and merged Gitlab MR\'s to seperate email folders';
    private string $mailserver;

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
     */
    public function handle()
    {
        $this->mailserver = '{' . env('EMAIL_HOSTNAME') . ':993/imap/ssl}';
        $this->mailbox = $this->mailboxConnection('INBOX');
        $this->rootMailbox = $this->mailboxConnection('');
        $mailboxes = ($this->rootMailbox->getListingFolders());

        $this->client = new Client();

        // Parse Opsgenie alerts
        $this->createMailbox($mailboxes, self::CLOSEDOPSGENIEALERTS);
        $opsgenieEmails = $this->findEmails('opsgenie@eu.opsgenie.net');
        foreach ($opsgenieEmails as $mailId) {
            $this->parseOpsgnieEmail($mailId);
        }

        // Parse Hakcerone emails
        $this->createMailbox($mailboxes, self::COMPLETEDHACKERONEREPORTS);
        $hackeroneEmails = $this->findEmails('no-reply@hackerone.com');
        foreach ($hackeroneEmails as $mailId) {
            $this->processHackeroneEmail($mailId);
        }

        // Parse Gitlab emails
        $this->createMailbox($mailboxes, self::MERGEDMRSEMAILSFOLDER);
        $gitlabEmails = $this->findEmails(env('GITLAB_EMAILADDRESS'));
        foreach ($gitlabEmails as $mailId) {
            $this->processEmailForMergedMR($mailId);
        }

        // Parse Asana emails
        $this->createMailbox($mailboxes, self::COMPLETEDASANAEMAILSFOLDER);
        $asanaEmails = $this->findEmails('mail.asana.com');
        foreach ($asanaEmails as $mailId) {
            $this->parseAsanaEmail($mailId);
        }

        return null;
    }

    private function parseOpsgnieEmail($mailId): void
    {
        $this->info('');
        $email = $this->mailbox->getMail($mailId, false);
        $subject = $this->mailbox->decodeMimeStr($email->headers->subject);
        $this->info('Subject: ' . $subject);
        if (substr($subject, 0, 6) === 'Closed' || substr($subject,0,5) === 'Acked') {
            $this->moveEmail($mailId, self::CLOSEDOPSGENIEALERTS);
        }
    }

    private function parseAsanaEmail($mailId): void
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
            if ($e->getCode() === 403) {
                $this->info('No access to this task, probably old and removed');
                $this->moveEmail($mailId, self::COMPLETEDASANAEMAILSFOLDER);
            }
            return;
        }
        $asanaResultJson = json_decode($asanaResult->getBody(), false, 512, JSON_THROW_ON_ERROR);
        if ($asanaResultJson->data->completed) {
            $this->info('Completed!');
            $this->moveEmail($mailId, self::COMPLETEDASANAEMAILSFOLDER);
        } else {
            $this->info('<fg=yellow>Task not completed yet</>');
        }
    }

    private function processHackeroneEmail($mailId)
    {
        $this->info('');
        $email = $this->mailbox->getMail($mailId, false);
        $this->info('Subject: ' . $this->mailbox->decodeMimeStr($email->headers->subject));
        if (preg_match('/#(?<digit>\d+)/', $email->headersRaw, $regexResult) === 0) {
            $this->info('<fg=yellow>No report id found</>');
            return;
        }
        $reportId = $regexResult['digit'];

        $guzzleResult = $this->client->request(
            'GET',
            'https://api.hackerone.com/v1/reports/' . $reportId,
            [
                'auth' => [
                    env('HACKERONE_TOKEN_IDENTIFIER'),
                    env('HACKERONE_TOKEN_VALUE'),
                ],
            ]
        );
        $guzzleResultJson = json_decode($guzzleResult->getBody());
        $state = $guzzleResultJson->data->attributes->state;
        $this->info("<fg=yellow>$state</>");
        if (in_array($state, ['informative', 'resolved', 'not-applicable', 'duplicate', 'spam'])) {
            $this->moveEmail($mailId, self::COMPLETEDHACKERONEREPORTS);
        }
    }

    private function findEmails(string $fromAddress): array
    {
        try {
            // Get all emails (messages)
            // PHP.net imap_search criteria: http://php.net/manual/en/function.imap-search.php
            $mailsIds = $this->mailbox->searchMailbox('FROM ' . $fromAddress);
        } catch (ConnectionException $ex) {
            echo "IMAP connection failed: " . $ex;
            die();
        }

        // If $mailsIds is empty, no emails could be found
        if (!$mailsIds) {
            $this->info('No emails found from ' . $fromAddress . ' in inbox');
        }

        return $mailsIds;
    }

    private function processEmailForMergedMR($mailId): void
    {
        $this->info('');
        $email = $this->mailbox->getMail($mailId, false);
        $this->info('Subject: ' . $this->mailbox->decodeMimeStr($email->headers->subject));
        if (preg_match('/X-GitLab-Project-Id: (?<digit>\d+)/i', $email->headersRaw, $regexResultProjectId) === 0) {
            $this->info('<fg=yellow>No project id found</>');
            return;
        }
        $projectId = $regexResultProjectId['digit'];
        if (preg_match('/X-GitLab-Pipeline-Id: (?<digit>\d+)/i', $email->headersRaw, $regexResultPipelineId) !== 0) {
            $pipelineId = $regexResultPipelineId['digit'];
            $gitlabResult = $this->gitlabRequest('projects/' . $projectId . '/pipelines/' . $pipelineId);
            $gitlabResultJson = json_decode($gitlabResult->getBody());
            $branch = $gitlabResultJson->ref;
            try {
                $gitlabResult = $this->gitlabRequest(
                    'projects/' . $projectId . '/repository/branches/' . urlencode($branch)
                );
            } catch (ClientException $e) {
                if ($e->getCode() === 404) {
                    $this->info('<fg=yellow>Branch not found, probably deleted</>');
                    $this->moveEmail($mailId, self::MERGEDMRSEMAILSFOLDER);
                }
                return;
            }
            $gitlabResultJson = json_decode($gitlabResult->getBody(), false, 512, JSON_THROW_ON_ERROR);
            dd($gitlabResultJson);
        }

        if (preg_match('/X-GitLab-MergeRequest-IID: (?<digit>\d+)/i', $email->headersRaw, $regexResultMRId) === 0) {
            $this->info('<fg=yellow>No merge request id found</>');
            return;
        }
        $MRId = $regexResultMRId['digit'];

        $gitlabResult = $this->gitlabRequest('projects/' . $projectId . '/merge_requests/' . $MRId);

        $gitlabResultJson = json_decode($gitlabResult->getBody());
        $state = $gitlabResultJson->state;
        $this->info('<fg=yellow>State: ' . $state . '</>');
        if ($state === 'merged' || $state === 'closed') {
            $this->moveEmail($mailId, self::MERGEDMRSEMAILSFOLDER);
        }
    }

    private function moveEmail($mailId, $folder): void
    {
        $this->info('<fg=magenta>Moving email to other mailbox</>');
        $this->mailbox->moveMail($mailId, $folder);
    }

    private function mailboxConnection(string $mailbox): Mailbox
    {
        $mailbox = new Mailbox(
            $this->mailserver . $mailbox, // IMAP server and mailbox folder
            env('EMAIL_USERNAME'), // Username for the before configured mailbox
            env('EMAIL_PASSWORD'), // Password for the before configured username
            null, // Directory, where attachments will be saved (optional)
            'UTF-8' // Server encoding (optional)
        );
        $mailbox->setAttachmentsIgnore(true);

        return $mailbox;
    }

    private function createMailbox(array $mailboxes, string $newMailbox): void
    {
        if (in_array($this->mailserver . $newMailbox, $mailboxes, true) === false) {
            $this->info('');
            $this->info('Creating mailbox: ' . $newMailbox);
            $this->info('');
            $this->rootMailbox->createMailbox($newMailbox);
        }
    }

    private function gitlabRequest(string $path): ResponseInterface
    {
        return $this->client->request(
            'GET',
            'https://' . env('GITLAB_HOSTNAME') . '/api/v4/' . $path,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . env('GITLAB_TOKEN'),
                ],
            ]
        );
    }

}
