# EgoWebDesign – Safe updates

WordPress plugin that runs WordPress, plugin and theme updates automatically, but only after a fresh UpdraftPlus backup. It holds big releases for 7 days, checks the key pages after each update, and puts back the previous version of a plugin or theme that breaks the site. Settings: **Settings > Safe updates**.

## Install

Download the zip of the [latest tag](../../tags), upload it in **Plugins > Add New > Upload**, activate.

## Release a new version

```sh
./release.sh 2.1.1
```

This sets the version in the plugin, commits, tags `v2.1.1` and pushes. Sites see it at their next update check (twice a day, or right away with "Check again" in Dashboard > Updates), and installs it after a fresh backup.
