# Tiledesk project incremental backup

Use this PHP script to make a local backup of your [Tiledesk](https://www.tiledesk.com/) project.

The script saves all requests (uploaded files are also stored), activities and leads.

At each execution the script starts from the last processed page.

A cron job can be scheduled to perform nightly incremental backups.

## Usage example

edit the script and set the parameters of your project:

```php
$username = 'xxxxx';
$password = 'xxxxx';
$project_id = 'xxxxx';

$pages_log_file = __DIR__ . '/_log.json';
```

then from your terminal:

```
php tiledesk_project_backup.php > out_log.txt
```

a json file is stored on local disk recording the last page number for incremental executions.
