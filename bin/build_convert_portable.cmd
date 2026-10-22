@echo off
rem Stellt den Konverter (bin\convert.php) als portables Paket für einen zweiten Rechner zusammen:
rem PHP-Minimum aus XAMPP, ffmpeg/ffprobe, convert.php, start.cmd und LIESMICH.txt.
rem
rem   bin\build_convert_portable.cmd D:\convert
rem
rem Nach Änderungen an bin\convert.php erneut ausführen (überschreibt die Dateien im Ziel).
setlocal
chcp 65001 >nul
if "%~1"=="" (
    echo Aufruf: %~nx0 ^<Zielordner^>   z. B. %~nx0 D:\convert
    exit /b 1
)
set "DEST=%~1"
set "DEST=%DEST:/=\%"
if "%DEST:~-1%"=="\" set "DEST=%DEST:~0,-1%"
set "PHPSRC=C:\xampp\php"
set "FFSRC=C:\Program Files\ffmpeg\bin"

if not exist "%DEST%\php\ext" mkdir "%DEST%\php\ext" || goto :error
if not exist "%DEST%\ffmpeg" mkdir "%DEST%\ffmpeg" || goto :error
copy /y "%PHPSRC%\php.exe" "%DEST%\php\" >nul || goto :error
copy /y "%PHPSRC%\php8ts.dll" "%DEST%\php\" >nul || goto :error
copy /y "%PHPSRC%\ext\php_mbstring.dll" "%DEST%\php\ext\" >nul || goto :error
copy /y "%FFSRC%\ffmpeg.exe" "%DEST%\ffmpeg\" >nul || goto :error
copy /y "%FFSRC%\ffprobe.exe" "%DEST%\ffmpeg\" >nul || goto :error
copy /y "%~dp0convert.php" "%DEST%\" >nul || goto :error

(
    echo ; Minimale Konfiguration nur für convert.php
    echo extension_dir = "ext"
    echo extension = mbstring
    echo memory_limit = 512M
    echo date.timezone = Europe/Berlin
) > "%DEST%\php\php.ini"

(
    echo @echo off
    echo rem Startet den Konverter mit PHP und ffmpeg aus diesem Ordner, mit niedriger Priorität.
    echo rem Beispiel: start.cmd --src=E:\videothek --dst=E:\videothek2 --only=n-z
    echo rem Ohne Angaben: D:\videothek nach D:\videothek2. Abbruch mit Strg+C, erneuter Aufruf setzt fort.
    echo setlocal
    echo set PATH=%%~dp0php;%%~dp0ffmpeg;%%SystemRoot%%\system32;%%SystemRoot%%
    echo start "" /low /b /wait "%%~dp0php\php.exe" -c "%%~dp0php\php.ini" "%%~dp0convert.php" %%*
    echo pause
) > "%DEST%\start.cmd"

(
    echo Portabler Videothek-Konverter
    echo =============================
    echo.
    echo Wandelt Filme in browsertaugliches MP4 um ^(Beschreibung: README.md des Projekts,
    echo Abschnitt "Videos für den Browser umwandeln"^). Braucht keine Installation von PHP
    echo oder ffmpeg; nur die "Microsoft Visual C++ Redistributable" ^(x64^) muss auf dem
    echo Rechner sein ^(fehlt sie, meldet Windows eine fehlende vcruntime140.dll^).
    echo.
    echo Aufruf ^(in einer Eingabeaufforderung in diesem Ordner^):
    echo   start.cmd --src=E:\videothek --dst=E:\videothek2 --only=n-z
    echo   start.cmd --only=n-z --dry-run          ^(nur anzeigen^)
    echo.
    echo Aufteilen auf zwei Rechner:
    echo   1. Rechner 1:  php bin\convert.php --only=0-m
    echo   2. Rechner 2:  Ordner n... bis z... nach E:\videothek kopieren, dann
    echo                  start.cmd --src=E:\videothek --dst=E:\videothek2 --only=n-z
    echo   3. Zusammenführen auf Rechner 1:
    echo                  robocopy E:\videothek2 D:\videothek2 /E /XF convert-log.jsonl
    echo                  type E:\videothek2\convert-log.jsonl ^>^> D:\videothek2\convert-log.jsonl
    echo      Danach erkennt ein Lauf auf Rechner 1 alles als erledigt ^(das Log speichert
    echo      relative Pfade sowie Größe und Änderungszeit der Quelle; robocopy und der
    echo      Explorer behalten die Änderungszeit bei^).
) > "%DEST%\LIESMICH.txt"

echo Fertig: %DEST%
exit /b 0

:error
echo Fehler beim Zusammenstellen nach %DEST%
exit /b 1
