# Safe updates watcher

Replaces UptimeRobot. Runs every 5 minutes on a server **other than the sites it watches**, checks each site's `/wp-json/egowebdesign/v1/status`, and emails when a site goes down, has a problem (its page checks fail or stopped), or comes back. While a site stays down, it reminds every 6 hours.

It also writes a JSON summary for the monitoring dashboard: each site's state, 30-day uptime and recent incidents, plus `generated` (if that gets old, the watcher itself has stopped).

## Install

```sh
mkdir -p ~/msu-watcher && cp watch.php ~/msu-watcher/
cp config.example.json ~/msu-watcher/config.json   # then edit it
php ~/msu-watcher/watch.php --test                 # test email
crontab -e   # add:  */5 * * * * /usr/bin/php ~/msu-watcher/watch.php >/dev/null 2>&1
```

`config.json`, `state.json` and the public file stay on the server only (the public file's random name is its password).
