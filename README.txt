Dictionary WW
An open English dictionary for Debian 13, CloudPanel, PHP 8.4, and SQLite FTS5.
No framework, no Composer, no Node build. The database is built on the server.

Replace SITEUSER with the CloudPanel site user and YOURDOMAIN with the real
domain (for example dictionary.example.com). Do not leave the placeholders in
config.php or in the sitemap.

STEP 1  Get the code from https://github.com/ArhitektaHaosa/dictionary-english-ww and check that tools/*.php, config.sample.php, deploy/nginx-snippet.conf exist.
        git clone https://github.com/ArhitektaHaosa/dictionary-english-ww.git
        cd dictionary-english-ww
        ls tools/check_env.php tools/download_sources.php tools/import.php tools/build_sitemap.php config.sample.php deploy/nginx-snippet.conf deploy/cloudpanel-vhost.conf

STEP 2  CloudPanel > Sites > Add Site > Create a PHP Site, PHP 8.4, domain, DNS A record to server IP; site path is /home/SITEUSER/htdocs/YOURDOMAIN/.

STEP 3  SSH as SITE USER (not root): clear placeholder files, git clone into the site directory, cp config.sample.php config.php, nano config.php.
        ssh SITEUSER@YOURDOMAIN
        cd /home/SITEUSER/htdocs/YOURDOMAIN
        rm -f index.php
        git clone https://github.com/ArhitektaHaosa/dictionary-english-ww.git .
        cp config.sample.php config.php
        nano config.php
        Set base_url to https://YOURDOMAIN and leave db_path outside public/ (the sample path data/dictionary.sqlite is correct).

STEP 4  CloudPanel > Settings > Root Directory = public.

STEP 5  php tools/check_env.php (all OK; about 10-15 GB free disk needed).
        php tools/check_env.php
        If PHP, pdo_sqlite, mbstring, zip, or bz2 fails, the web SAPI is missing
        an extension. As root (the site user cannot do this):
        apt-get install php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-zip php8.4-bz2
        systemctl restart php8.4-fpm
        Then run php tools/check_env.php again. CLI and PHP-FPM must both have pdo_sqlite.

STEP 6  php tools/download_sources.php
        php tools/download_sources.php
        Downloads are resumable. If a transfer dies, run the same command again.
        php tools/download_sources.php --refresh
        starts every file over.

STEP 7  tmux new -s import; php -d memory_limit=512M tools/import.php (30-90 min; detach Ctrl+B D; reattach tmux attach -t import; rerun to resume).
        tmux new -s import
        php -d memory_limit=512M tools/import.php
        Detach with Ctrl+B then D. Reattach with: tmux attach -t import
        If the import is killed, run the same command again. It continues from the last committed Kaikki batch. When it prints "Import already complete", it is done. Running it again does not duplicate rows.

STEP 8  Permissions: chmod 750 data; chmod 640 data/dictionary.sqlite; chmod 640 config.php; DB never inside public/.
        chmod 750 data data/sources
        chmod 640 data/dictionary.sqlite config.php
        The web user is the site user, so PHP-FPM can read the database and write data/ratelimit. The database must not sit inside public/.

STEP 9  CloudPanel > Vhost: paste deploy/nginx-snippet.conf (try_files $uri $uri/ /index.php?$args; deny /data/).
        A new CloudPanel PHP site already contains:
        try_files $uri $uri/ /index.php?$args;
        Do not add a second location / block. That duplicate makes nginx reject the vhost and the site returns 502.
        Open the vhost, confirm that try_files line is present, and paste only the two deny locations from deploy/nginx-snippet.conf immediately after the {{settings}} line. Keep CloudPanel's existing PHP location.
        If the try_files line is missing, replace the whole vhost with deploy/cloudpanel-vhost.conf. Leave every {{placeholder}} untouched.
        The deny rules are:
        location ^~ /data/ { deny all; return 404; }
        and a deny for /config.php, /src/, /tools/, and /deploy/.
        Pages only work after STEP 4 (Root Directory = public). With the document root set to public, /dictionary/english/run is routed to public/index.php. The database stays outside public/.

STEP 10 HTTPS: CloudPanel > SSL/TLS > Let's Encrypt.

STEP 11 Test URLs: /, /dictionary/english/kulak, /serendipity, /run, /api/suggest.php?q=kul, a nonexistent word (404 page).
        https://YOURDOMAIN/
        https://YOURDOMAIN/dictionary/english/kulak
        https://YOURDOMAIN/dictionary/english/serendipity
        https://YOURDOMAIN/dictionary/english/run
        https://YOURDOMAIN/api/suggest.php?q=kul
        https://YOURDOMAIN/dictionary/english/not-a-real-word-zz
        kulak, serendipity, and run must each render with definitions, IPA, and nearby words.
        An unknown word must return HTTP 404 with suggestions.

STEP 12 php tools/build_sitemap.php; CloudPanel > Cron Jobs monthly entry; submit sitemap.xml to Google Search Console.
        php tools/build_sitemap.php
        Cron, monthly, as the site user, from the site directory:
        php /home/SITEUSER/htdocs/YOURDOMAIN/tools/build_sitemap.php
        Then submit https://YOURDOMAIN/sitemap.xml to Google Search Console.
        Parts are sitemap-1.xml, sitemap-2.xml, and so on, with at most 50,000 URLs each. sitemap.xml is the index.

STEP 13 Updating later: download --refresh, import --output data/dictionary.new.sqlite, mv old to .old, mv new into place (instant swap).
        php tools/download_sources.php --refresh
        php -d memory_limit=512M tools/import.php --output data/dictionary.new.sqlite
        mv data/dictionary.sqlite data/dictionary.sqlite.old
        mv data/dictionary.new.sqlite data/dictionary.sqlite
        chmod 640 data/dictionary.sqlite
        The new file is a single database (the importer turns WAL off and runs VACUUM before it finishes), so the rename is the swap. Remove data/dictionary.sqlite.old after you have checked the site.

TROUBLESHOOTING

502 from nginx
  PHP-FPM is down, the vhost failed to reload, or the vhost points at the wrong socket. In CloudPanel, confirm the site uses PHP 8.4 and the Root Directory is public. A second "location /" (from an older copy of the nginx snippet) makes nginx refuse the config: remove the duplicate and keep the single try_files $uri $uri/ /index.php?$args; line. Read the site error log. A 502 is not a missing word.

could not find driver / pdo_sqlite FAIL
  The sqlite extension is not loaded. As root:
  apt-get install php8.4-sqlite3 php8.4-mbstring php8.4-zip php8.4-bz2
  systemctl restart php8.4-fpm
  Then php tools/check_env.php. The site message "unable to open database" is also what you see when the driver is missing, before any import error.

404 on every word, including /dictionary/english/run
  The root directory is not public/, so index.php is never reached, or the nginx snippet was not applied. You want try_files $uri $uri/ /index.php?$args; with the site root set to public. A custom 404 from nginx (no dictionary page) means PHP never ran.

unable to open database
  config.php db_path is wrong, the import has not finished, or the file mode is too tight. The message on the site is exactly "unable to open database". Check:
  ls -l data/dictionary.sqlite config.php
  The site user must be able to read the file. The path must be outside public/. SQLite also needs write permission on the data directory for its journal while PHP opens the file, even in query_only mode on some builds. chmod 750 data and chmod 640 data/dictionary.sqlite as the site user.

Import killed (tmux session gone, or "Killed" in the output)
  The machine ran out of RAM or the SSH session died. Do not delete the sqlite file. Reattach or start again:
  tmux new -s import
  php -d memory_limit=512M tools/import.php
  Kaikki resumes at the last committed byte offset. Later phases that did not finish start that phase again without duplicating finished work.

Slow search
  The full-text index was not analysed, or the import stopped before the FTS phase. Run, as the site user, against a copy if you need to:
  php -d memory_limit=512M tools/import.php
  If the import is already complete it will exit without rebuilding. To rebuild the index in place only when search is slow after a completed import, open the database with the sqlite3 CLI and run:
  ANALYZE;
  The importer already runs ANALYZE once at the end. If suggestions are slow, confirm glossary_fts exists:
  php -r ' $p=new PDO("sqlite:data/dictionary.sqlite"); foreach($p->query("SELECT name FROM sqlite_master WHERE name=\"glossary_fts\"") as $r){echo $r["name"], "\n";} '

LICENCES

Code: MIT. See LICENSE.
Data, with links, is listed in NOTICE. Short form:

Wiktionary via Kaikki.org — CC BY-SA 4.0 — https://kaikki.org/dictionary/English/index.html
Open English WordNet 2025 — CC BY 4.0 — https://en-word.net/
Moby Thesaurus II — public domain — https://www.gutenberg.org/ebooks/3202
Tatoeba English sentences — CC BY 2.0 FR — https://tatoeba.org/
CMU Pronouncing Dictionary — BSD-style — https://github.com/cmusphinx/cmudict
FrequencyWords English 50k (OpenSubtitles 2018) — CC BY-SA 4.0 — https://github.com/hermitdave/FrequencyWords

The site footer repeats these attributions. Definitions are not taken from Collins or any other proprietary dictionary.

ACCEPTANCE

After STEP 7 and STEP 11, these three URLs render a headword, at least one definition, an IPA pronunciation, and nearby words:

https://YOURDOMAIN/dictionary/english/kulak
https://YOURDOMAIN/dictionary/english/serendipity
https://YOURDOMAIN/dictionary/english/run

A URL for a word that is not in the database returns HTTP 404 and a list of suggestions from the full-text index.
