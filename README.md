# EgoWebDesign – Safe updates

WordPress plugin that runs WordPress, plugin and theme updates automatically, right after saving a snapshot of the database (no backup plugin needed). It holds big releases for 7 days, checks the key pages after each update, and puts back the previous version of a plugin or theme that breaks the site. The last 3 database snapshots can be put back from the Dashboard > Safe updates box. It also watches for signs of intrusion (new administrators, WordPress core files that differ from the official ones, PHP files that appear or change outside updates, PHP files in uploads) and emails once per new finding. Emails: a Monday summary of what was installed, and right away for big releases, failed updates and problems (WordPress's own update emails use the same design; small updates that worked only appear in the summary). Settings: **Settings > Safe updates**.

## Install

Download the zip of the [latest tag](../../tags), upload it in **Plugins > Add New > Upload**, activate.

## Release a new version

```sh
./release.sh 2.1.1
```

This sets the version in the plugin, commits, tags `v2.1.1` and pushes. Sites see it at their next update check (twice a day, or right away with "Check again" in Dashboard > Updates), and installs it after a fresh backup.
