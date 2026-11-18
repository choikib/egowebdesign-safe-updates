# Safe updates watcher

Replaces UptimeRobot. Runs every 5 minutes on a server **other than the sites it watches**, checks each site's `/wp-json/egowebdesign/v1/status`, and emails when a site goes down, has a problem (its page checks fail or stopped), or comes back. While a site stays down, it reminds every 6 hours.

It also writes a JSON summary for the monitoring dashboard: each site's state, 30-day uptime and recent incidents, plus `generated` (if that gets old, the watcher itself has stopped).

## Sites sign up on their own

Each site running Safe updates 2.5+ posts its name, address and report link to `register.php` once a day (it can be turned off in its settings). The server checks the report link really answers as Safe updates for that site. A site listed in `config.json` is approved at once; any other one waits, and you get an email with a link to `approve.php`, where you add or ignore it (opening the page changes nothing, so mail scanners can't approve). Approved sites are watched from the next run, and listed with their report links in `sites_file` for the dashboard's daily routine.

## Install

```sh
mkdir -p ~/msu-watcher ~/public_html/msu-watch && cp watch.php lib.php ~/msu-watcher/
cp public/*.php ~/public_html/msu-watch/            # they load ~/msu-watcher/lib.php
cp config.example.json ~/msu-watcher/config.json   # then edit it
php ~/msu-watcher/watch.php --test                 # test email
crontab -e   # add:  */5 * * * * /usr/bin/php ~/msu-watcher/watch.php >/dev/null 2>&1
```

`config.json`, `state.json` and `registry.json` stay on the server only. The two public files' random names are their passwords.
