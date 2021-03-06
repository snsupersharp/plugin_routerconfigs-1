# routerconfigs

The routerconfigs plugin is designed to act in conjuction with the Cacti servers tftp server to receive backups from your router
devices.  It also provides the ability to view and diff those router configurations as they change over time.  It is designed primarily for Cisco device types, but may work with other device types.

NOTE: This plugin is not actively maintained by the Cacti Group and is a community plugin that primarily receives contributions from the Cacti community.  The Cacti Group has gone as far as to make the plugin functional on Cacti 1.x, and to work with the community on smaller functionality changes, compatibility and the like, but expects the Cacti community to assist in it's development.

## Installation

Just like any other Cacti plugin, untar the package to the Cacti plugins directory, rename the directory to 'routerconfigs', and then from Cacti's Plugin Management interface, Install and Enable the pluign.

This plugin requires a TFTP server on the Cacti server

For CentOS 6, just run these commands:

`yum install tftp-server`

The edit the tftp startup script (/etc/xinetd.d/tftp) to change the server arguments, I used this line:

`server_args= -c -s /home/configs/backups`

You will need to create this folder (or whatever folder you specify) and give the apache server and the tftp server permissions to access it

I have provided a copy of this file for you.  Then we just need to turn on the tftp server so do this.

`chkconfig xinetd on`
`service xinetd start`

There are a few options in Cacti you will need to change to then get the plugin up and running.  They are located under Settings > Misc > Router Configs

* TFTP Server IP = The IP Address of the Cacti server given to the routers
* Backup Directory Path = The directory you put in the TFTP file above

Now you should be good to go, just add an authenication account, and a device and it will download the first backup after a few pollings.

On other operating systems, or for CentOS 7, you will have to find equivalent instructions.

## Bugs and Feature Enhancements
   
Bug and feature enhancements for the routerconfigs plugin are handled in GitHub.  If you find a first search the Cacti forums for a solution before creating an issue in GitHub.

## ChangeLog
--- 1.3 ---
* feature: Completely rejigged PHPSsh/PHPTelnet to use base PHPConnection class
* feature: Moved RouterConfig settings to their own tab as more have been added
* feature: Added debug flag to suppress log output of debug buffer (does not affect adding to debug buffer to db)
* issue: A return was being sent to early in Telnet mode
* issue: SSH and Telnet would not operate the same after initial connection
* issue: TFTP bytes copied would not be picked up as a transfer completion
* issue: Midnight full download would not trigger without --retry cause no full downloads
* issue: Default sort column was not a valid field

--- 1.2 ---
* issue: Correct most config comparision code
* issue: Added in missing Horde library functions and created common include file 
* issue: Fixed the poller spawn issue that was preventing automatic backups
* issue: Fixed comparison device/file selection code
* issue: Don't send email if nothing changed and checking for failures
* feature: Filtering - Added to both Devices and Backups tab
* feature: Backups - Now all use same code
* feature: Backups - Manual now work the same way as automatic ones
* feature: Backups - Manual can be done regardless of last attempt state
* feature: Backups - Success state should work with more devices
* feature: Backups - Devices can be specified on command line to run individual backups
* feature: Debug - Notifications are better formatted and consistent
* feature: Debug - Passwords are now hidden from debug for the most part.
* note: router-redownload.php no longer exists, any code relying on this should use router-download.php

--- 1.1 ---
* issue#9: Constructurs missing on the Horde class library
* issue#16: HTML style issues during enable/disable operations
* issue: Updating text domain for i18n

--- 0.3 ---
* compat: Remove PIA 1.x support
* compat: Register Realms
* compat: 0.8.7g

--- 0.2 ---
* feature: Allow the use of enable passwords
* feature: Add support for SSH Connections (thanks to Abdul Pallarés)

--- 0.1 ---
* Initial Release
* Nightly Backups
* View Config of any backup
* Compare Configs
* Automatically update Hostname from config file

