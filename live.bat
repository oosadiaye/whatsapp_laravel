@echo off
REM Runs everything the app needs to work "live" on this machine, each in its own window:
REM   - the web app           (http://127.0.0.1:8000)
REM   - Reverb websockets     (instant alerts and toasts in the browser)
REM   - the queue worker      (sends email, reads replies, runs the AI)
REM   - the scheduler         (pulls leads, drafts outreach, sends follow-ups every few minutes)
REM
REM Close a window to stop that part. On the server the same four are run by supervisor and cron
REM (see deploy/ and docs/DEPLOYMENT.md). Local only: the web app uses the OPcache-off flag for this
REM machine's XAMPP PHP (see serve.bat).
setlocal
cd /d "%~dp0"

start "BlastIQ web"       cmd /k php -d opcache.enable=0 -S 127.0.0.1:8000 -t public server.php
start "BlastIQ reverb"    cmd /k php artisan reverb:start
start "BlastIQ queue"     cmd /k php artisan queue:work --queue=default,messages,imports,mail-send,mail-sync --tries=3 --timeout=120 --sleep=3
start "BlastIQ scheduler" cmd /k php artisan schedule:work

echo Started web, reverb, queue worker and scheduler in separate windows.
