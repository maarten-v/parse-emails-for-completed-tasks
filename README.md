So your emailbox is getting pretty full, you can't keep up anymore with all the tasks you have to do, and was that old email for this Asana task for a task that's already completed?
It takes a lot of time to check for every email if it is something that is already handled or not.
This app reads your emailbox for all Asana emails, Gitlab emails, Hackerone emails, Opsgenie Emails, Sentry emails, Zabbix emails, checks if the task is already completed or resolved, and if so, moves the email to a seperate emailbox.

- `composer install`
- Copy .env.example to .env and fill in the details
### Create an Asana access token
- Login at asana.com
- Click your profile photo from the topbar
- Select My Profile Settings…
- Open the Apps tab
- Click Manage Developer Apps
- Click + New Access Token
- Add the token to the .env
### Create a Jira token
- Navigate to https://id.atlassian.com/manage-profile/security/api-tokens
- Create a token
- Add the token to the .env
### Create a Gitlab token
- Navigate to https://gitlabdomain/profile/personal_access_tokens
- Create a token with api access
- Add the token to the .env
### Create a HackerOne token
- Navigate to https://hackerone.com/program_url/api
- Create a token with read access
- Add the token to the .env
### Create a Sentry token
- Navigate to https://sentrydomainname/api/
- Create a token with read access
- Add the name and the token to the .env
<br>&nbsp;
- run `art parse-email`

