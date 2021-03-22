<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Command;
use PhpImap\Exceptions\ConnectionException;
use PhpImap\Mailbox;
use Psr\Http\Message\ResponseInterface;

class parseEmail extends Command
{
    private const COMPLETEDASANAEMAILSFOLDER = 'Completed Asana tasks';
    private const MERGEDMRSEMAILSFOLDER = 'Merged MRs';
    private const COMPLETEDHACKERONEREPORTSFOLDER = 'Completed Hackerone reports';
    private const COMPLETEDJIRAISSUESFOLDER = 'Completed Jira issues';
    private const CLOSEDOPSGENIEALERTSFOLDER = 'Closed Opsgenie alerts';
    private const CLOSEDSENTRYREPORTSFOLDER = 'Closed Sentry reports';
    private const CLOSEDZABBIXPROBLEMSFOLDER = 'Closed Zabbix problems';

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
        $this->mailserver = '{' . env('EMAIL_HOSTNAME') . ':993/imap/ssl';
        if (env('EMAIL_SHARED_BOX')) {
            $this->mailserver .= '/authuser='. env('EMAIL_USERNAME'). '/user='.  env('EMAIL_SHARED_BOX');
        }
        $this->mailserver .= '}';

        $this->mailbox = $this->mailboxConnection('INBOX');
        $this->rootMailbox = $this->mailboxConnection('');
        $mailboxes = ($this->rootMailbox->getListingFolders());

        $this->client = new Client();

        //Parse Sentry Reports
        if (env('SENTRY_ENABLED')) {
            $this->createMailbox($mailboxes, self::CLOSEDSENTRYREPORTSFOLDER);
            $sentryEmails = $this->findEmails(env('SENTRY_EMAILADDRESS'));
            foreach ($sentryEmails as $mailId) {
                $this->parseSentryEmail($mailId);
            }
        }

        // Parse Zabbix problems
        if (env('ZABBIX_ENABLED')) {
            $this->createMailbox($mailboxes, self::CLOSEDZABBIXPROBLEMSFOLDER);
            $zabbixEmails = $this->findEmails(env('ZABBIX_EMAILADDRESS'));
            $zabbixCompletedIds = [];
            foreach ($zabbixEmails as $mailId) {
                $isResolvedZabbixProblem = $this->isResolvedZabbixProblem($mailId);
                if ($isResolvedZabbixProblem !== false) {
                    $zabbixCompletedIds[] = $isResolvedZabbixProblem;
                }
            }
            foreach ($zabbixEmails as $mailId) {
                if ($this->parseZabbixEmail($mailId, $zabbixCompletedIds)) {
                    continue;
                }
                $this->parseZabbixResolvedEmail($mailId, $zabbixCompletedIds);
            }
        }

        // Parse Opsgenie alerts
        if (env('OPSGENIE_ENABLED')) {
            $this->createMailbox($mailboxes, self::CLOSEDOPSGENIEALERTSFOLDER);
            $opsgenieEmails = $this->findEmails('opsgenie@eu.opsgenie.net');
            foreach ($opsgenieEmails as $mailId) {
                $this->parseOpsgenieEmail($mailId);
            }
        }

        // Parse Hackerone emails
        if (env('HACKERONE_ENABLED')) {
            $this->createMailbox($mailboxes, self::COMPLETEDHACKERONEREPORTSFOLDER);
            $hackeroneEmails = $this->findEmails('no-reply@hackerone.com');
            foreach ($hackeroneEmails as $mailId) {
                $this->processHackeroneEmail($mailId);
            }
        }

        // Parse Jira emails
        if (env('JIRA_ENBLED')) {
            $this->createMailbox($mailboxes, self::COMPLETEDJIRAISSUESFOLDER);
            $jiraEmails = $this->findEmails(env('JIRA_EMAILADDRESS'));
            foreach ($jiraEmails as $mailId) {
                $this->processJiraEmail($mailId);
            }
        }

        // Parse Gitlab emails
        if (env('GITLAB_ENABLED')) {
            $this->createMailbox($mailboxes, self::MERGEDMRSEMAILSFOLDER);
            $gitlabEmails = $this->findEmails(env('GITLAB_EMAILADDRESS'));
            foreach ($gitlabEmails as $mailId) {
                $this->processEmailForMergedMR($mailId);
            }
        }

        // Parse Asana emails
        if (env('ASANA_ENABLED')) {
            $this->createMailbox($mailboxes, self::COMPLETEDASANAEMAILSFOLDER);
            $asanaEmails = $this->findEmails('asana.com');
            foreach ($asanaEmails as $mailId) {
                $this->parseAsanaEmail($mailId);
            }
        }

        return null;
    }

    private function parseSentryEmail(int $mailId): void
    {
        $this->info('');
        $email = $this->mailbox->getMail($mailId, false);
        $subject = $this->mailbox->decodeMimeStr($email->headers->subject);
        $this->info('Subject: ' . $subject);
        if (preg_match('/X-Sentry-Reply-To: (?<digit>\d+)/i', $email->headersRaw, $regexResultReportId) === 0) {
            $this->info('<fg=yellow>No report id found</>');
            return;
        }
        try {
            $sentryResult = $this->client->request(
                'GET',
                'https://' . env('SENTRY_HOSTNAME') . '/api/0/issues/' . $regexResultReportId['digit'] . '/',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . env('SENTRY_TOKEN'),
                    ],
                ]
            );
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                $this->info('Report is not found, probably old and removed. Id: ' . $regexResultReportId['digit']);
                $this->moveEmail($mailId, self::CLOSEDSENTRYREPORTSFOLDER);
            }
            return;
        }
        $sentryResult = json_decode($sentryResult->getBody(), false, 512, JSON_THROW_ON_ERROR);
        $status = $sentryResult->status;
        $this->info("<fg=yellow>Status: $status</>");
        if ($status === 'resolved' || $status === 'ignored') {
            $this->moveEmail($mailId, self::CLOSEDSENTRYREPORTSFOLDER);
        }
    }

    private function parseOpsgenieEmail(int $mailId): void
    {
        $this->info('');
        $email = $this->mailbox->getMail($mailId, false);
        $subject = $this->mailbox->decodeMimeStr($email->headers->subject);
        $this->info('Subject: ' . $subject);
        if (strpos($subject, 'Closed') === 0 || strpos($subject, 'Acked') === 0) {
            $this->moveEmail($mailId, self::CLOSEDOPSGENIEALERTSFOLDER);
        }
    }

    private function parseAsanaEmail(int $mailId): void
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
            if (in_array($e->getCode(), [403, 404] , true)) {
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

    private function isResolvedZabbixProblem(int $mailId): bool
    {
        $email = $this->mailbox->getMail($mailId, false);
        $subject = $this->mailbox->decodeMimeStr($email->headers->subject);
        if ((strpos($subject, 'Resolved:') !== 0)) {
            return false;
        }
        $this->info('');
        $this->info('Subject: ' . $subject);
        if (preg_match('/Original problem ID: (?<digit>\d+)/', $email->textPlain, $regexResult) === 0) {
            $this->info('<fg=yellow>No problem id found</>');
            return false;
        }
        return $regexResult['digit'];
    }

    private function parseZabbixEmail(int $mailId, array $completedIds): bool
    {
        $email = $this->mailbox->getMail($mailId, false);
        $subject = $this->mailbox->decodeMimeStr($email->headers->subject);
        if ((strpos($subject, 'Problem:') !== 0)) {
            return false;
        }
        $this->info('');
        $this->info('Subject: ' . $subject);
        if (preg_match('/Original problem ID: (?<digit>\d+)/', $email->textPlain, $regexResult) === 0) {
            $this->info('<fg=yellow>No problem id found</>');
            return false;
        }
        if (in_array($regexResult['digit'], $completedIds, true)) {
            $this->moveEmail($mailId, self::CLOSEDZABBIXPROBLEMSFOLDER);
        }
        return true;
    }

    private function parseZabbixResolvedEmail(int $mailId, array $completedIds): void
    {
        $email = $this->mailbox->getMail($mailId, false);
        $subject = $this->mailbox->decodeMimeStr($email->headers->subject);
        if ((strpos($subject, 'Resolved:') !== 0)) {
            return;
        }
        $this->info('');
        $this->info('Subject: ' . $subject);
        if (preg_match('/Original problem ID: (?<digit>\d+)/', $email->textPlain, $regexResult) === 0) {
            $this->info('<fg=yellow>No problem id found</>');
            return;
        }
        if (in_array($regexResult['digit'], $completedIds, true)) {
            $this->moveEmail($mailId, self::CLOSEDZABBIXPROBLEMSFOLDER);
        }
    }

    private function processHackeroneEmail(int $mailId): void
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
            $this->moveEmail($mailId, self::COMPLETEDHACKERONEREPORTSFOLDER);
        }
    }

    private function processJiraEmail(int $mailId): void
    {
        $this->info('');
        $email = $this->mailbox->getMail($mailId, false);
        $this->info('Subject: ' . $this->mailbox->decodeMimeStr($email->headers->subject));
        if (preg_match('/(?<code>\w+-\d+)/', $email->headers->subject, $match )) {
            $issueId = $match['code'];
            $guzzleResult = $this->client->request(
                'GET',
                'https://webparking.atlassian.net/rest/api/3/issue/' . $issueId . '?fields=status',
                [
                    'auth' => [
                        env('JIRA_USERNAME'),
                        env('JIRA_TOKEN'),
                    ],
                    'headers' => [
                        'Accept' => 'application/json',
                    ]
                ]
            );
            $response = json_decode($guzzleResult->getBody());
            if ($response->fields->status->statusCategory->key === 'done') {
                $this->moveEmail($mailId, self::COMPLETEDJIRAISSUESFOLDER);
            }
        }

    }

    private function findEmails(string $fromAddress): array
    {
        try {
            // Get all emails (messages)
            // PHP.net imap_search criteria: http://php.net/manual/en/function.imap-search.php
            $mailsIds = $this->mailbox->searchMailbox('FROM ' . $fromAddress, true);
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

    private function processEmailForMergedMR(int $mailId): void
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
            dump($gitlabResultJson);
        }

        if (preg_match('/X-GitLab-MergeRequest-IID: (?<digit>\d+)/i', $email->headersRaw, $regexResultMRId) === 0) {
            $this->info('<fg=yellow>No merge request id found</>');
            return;
        }
        $MRId = $regexResultMRId['digit'];
        try {
            $gitlabResult = $this->gitlabRequest('projects/' . $projectId . '/merge_requests/' . $MRId);
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                $this->info('<fg=yellow>Merge request not found, probably deleted</>');
                $this->moveEmail($mailId, self::MERGEDMRSEMAILSFOLDER);
            }
            return;
        }
        $gitlabResultJson = json_decode($gitlabResult->getBody());
        $state = $gitlabResultJson->state;
        $this->info('<fg=yellow>State: ' . $state . '</>');
        if ($state === 'merged' || $state === 'closed') {
            $this->moveEmail($mailId, self::MERGEDMRSEMAILSFOLDER);
        }
    }

    private function moveEmail(int $mailId, string $folder): void
    {
        $this->info('<fg=magenta>Moving email to other mailbox</>');
        $this->mailbox->moveMail($mailId, $folder);
    }

    private function mailboxConnection(string $mailbox): Mailbox
    {
        $mailboxConnection = new Mailbox(
            $this->mailserver . $mailbox, // IMAP server and mailbox folder
            env('EMAIL_USERNAME'), // Username for the before configured mailbox
            env('EMAIL_PASSWORD'), // Password for the before configured username
            null, // Directory, where attachments will be saved (optional)
            'UTF-8' // Server encoding (optional)
        );
        $mailboxConnection->setAttachmentsIgnore(true);

        return $mailboxConnection;
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
