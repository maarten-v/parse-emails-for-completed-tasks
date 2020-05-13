So your emailbox is getting pretty full, you can't keep up anymore with all the tasks you have to do, and was that old email for this Asana task for a task that's already completed?
It takes a lot of time to check for every Asana email if the task is already completed or not.
This app reads your emailbox for all Asana emails, checks if the task is already completed in Asana, and if so, moves the email to a seperate emailbox.

- Composer install
- Create an Asana access token
    - Login at asana.com
    - Click your profile photo from the topbar
    - Select My Profile Settings…
    - Open the Apps tab
    - Click Manage Developer Apps
    - Click + New Access Token
- Copy .env.example to .env and fill in the details
- run `art parse-emails`
