@echo off
rem Stellt bin\convert.php als portables Paket für einen zweiten Rechner zusammen: PHP-Minimum aus XAMPP (mit
rem openssl für HTTPS), ffmpeg/ffprobe, bin\convert.php, public\api\lib (Regeln für Pfade usw.) und start.cmd
rem (mit der Beschreibung des Aufrufs). Die Einträge holt das Skript vom Server; das API-Token gibt man beim Aufruf an (--api-token=…).
rem
rem   bin\build_convert_portable.cmd E:\convert [--with-config]
rem
rem   --with-config: zusätzlich eine config.php nur mit API_TOKEN und USB_ROOT aus der eigenen, dann entfallen
rem                  --api-token und --to beim Aufruf (das Paket enthält dann das Token: nicht weitergeben)
rem
rem Nach Änderungen an bin\convert.php oder public\api\lib erneut ausführen (überschreibt alles im Ziel).
setlocal
chcp 65001 >nul
if "%~1"=="" (
    echo Aufruf: %~nx0 ^<Zielordner^> [--with-config]   z. B. %~nx0 E:\convert
    exit /b 1
)
set "WITHCONFIG="
if /i "%~2"=="--with-config" set "WITHCONFIG=1"
if not "%~2"=="" if not defined WITHCONFIG (
    echo Unbekannte Option: %~2
    exit /b 1
)
set "DEST=%~1"
set "DEST=%DEST:/=\%"
if "%DEST:~-1%"=="\" set "DEST=%DEST:~0,-1%"
set "SRC=%~dp0.."
set "PHPSRC=C:\xampp\php"
set "FFSRC=C:\Program Files\ffmpeg\bin"

for %%d in ("php\ext" "ffmpeg" "bin" "public\api\lib") do (
    if not exist "%DEST%\%%~d" mkdir "%DEST%\%%~d" || goto :error
)
copy /y "%PHPSRC%\php.exe" "%DEST%\php\" >nul || goto :error
copy /y "%PHPSRC%\php8ts.dll" "%DEST%\php\" >nul || goto :error
copy /y "%PHPSRC%\libcrypto-3-x64.dll" "%DEST%\php\" >nul || goto :error
copy /y "%PHPSRC%\libssl-3-x64.dll" "%DEST%\php\" >nul || goto :error
copy /y "%PHPSRC%\ext\php_mbstring.dll" "%DEST%\php\ext\" >nul || goto :error
copy /y "%PHPSRC%\ext\php_openssl.dll" "%DEST%\php\ext\" >nul || goto :error
copy /y "%FFSRC%\ffmpeg.exe" "%DEST%\ffmpeg\" >nul || goto :error
copy /y "%FFSRC%\ffprobe.exe" "%DEST%\ffmpeg\" >nul || goto :error
copy /y "%SRC%\bin\convert.php" "%DEST%\bin\" >nul || goto :error
copy /y "%SRC%\public\api\lib\*.php" "%DEST%\public\api\lib\" >nul || goto :error

rem config.php nur auf Wunsch, und dann nur mit API_TOKEN und USB_ROOT (kein TMDB-Schlüssel usw.); ohne: eine alte
rem aus einem früheren Lauf entfernen
if not defined WITHCONFIG (
    if exist "%DEST%\config.php" del "%DEST%\config.php" || goto :error
    goto :configdone
)
"%PHPSRC%\php.exe" -r "require $argv[1]; file_put_contents($argv[2], sprintf('<?php // nur für bin/convert.php (build_convert_portable.cmd)%%sconst API_TOKEN = %%s;%%sconst USB_ROOT = %%s;%%s', PHP_EOL, var_export(API_TOKEN, true), PHP_EOL, var_export(USB_ROOT, true), PHP_EOL));" "%SRC%\config.php" "%DEST%\config.php" || goto :error
:configdone

(
    echo ; Minimale Konfiguration nur für convert.php
    echo extension_dir = "ext"
    echo extension = mbstring
    echo extension = openssl
    echo memory_limit = 512M
    echo date.timezone = Europe/Berlin
) > "%DEST%\php\php.ini"

(
    echo @echo off
    echo rem Startet bin\convert.php mit PHP und ffmpeg aus diesem Ordner, mit niedriger Priorität.
    echo rem Ohne Angaben: Hilfe mit allen Optionen. Abbruch mit Strg+C, erneuter Aufruf setzt fort.
    echo rem
    echo rem   start.cmd --owner=rohlfing --src=E:\Filme --to=D:\Videos --password=... --api-token=... --dry-run
    echo rem   start.cmd --owner=rohlfing --src=E:\Filme --to=D:\Videos --password=... --api-token=...
    echo rem
    echo rem Weitere Optionen: --only=a,b ^(nur diese Slugs^), --crf=18, --preset=slow, --qsv, --yes,
    echo rem --user=frank, --remote=https://...
    echo rem Passwort und Token auch als Umgebungsvariablen statt in der Befehlszeile:
    echo rem   set VIDEOTHEK_PASSWORD=...
    echo rem   set VIDEOTHEK_API_TOKEN=...
    echo rem   start.cmd --owner=rohlfing --src=E:\Filme --to=D:\Videos --dry-run
    echo rem Mit config.php in diesem Ordner ^(Paket mit --with-config gebaut^) entfallen --to und --api-token.
    echo rem Braucht nur die "Microsoft Visual C++ Redistributable" ^(x64^); fehlt sie, meldet Windows vcruntime140.dll.
    echo setlocal
    echo set PATH=%%~dp0php;%%~dp0ffmpeg;%%SystemRoot%%\system32;%%SystemRoot%%
    echo start "" /low /b /wait "%%~dp0php\php.exe" -c "%%~dp0php\php.ini" "%%~dp0bin\convert.php" %%*
    echo pause
) > "%DEST%\start.cmd"

rem Älteres Paket: LIESMICH.txt gibt es nicht mehr, die Beschreibung steht in start.cmd
if exist "%DEST%\LIESMICH.txt" del "%DEST%\LIESMICH.txt"

echo Fertig: %DEST%
exit /b 0

:error
echo Fehler beim Zusammenstellen nach %DEST%
exit /b 1
