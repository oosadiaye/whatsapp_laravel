@echo off
REM Laravel dev server for this machine's XAMPP PHP (ZTS build).
REM
REM `php artisan serve` crashes here: OPcache segfaults the built-in web
REM server (cli-server SAPI) on the ZTS build (exit 0xC0000005). Disabling
REM OPcache for THIS process only avoids the crash and preserves Apache's
REM OPcache (CLI tools like queue:work / reverb:start are unaffected).
REM
REM Uses the project's own router (server.php) exactly as documented there:
REM   php -S 127.0.0.1:8000 -t public server.php
setlocal
cd /d "%~dp0"
php -d opcache.enable=0 -S 127.0.0.1:8000 -t public server.php
