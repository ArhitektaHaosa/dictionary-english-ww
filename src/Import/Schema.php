<?php

declare(strict_types=1);

final class Schema
{
    public static function ensure(PDO $pdo): void
    {
        $statements = [
            'CREATE TABLE IF NOT EXISTS meta (
                k TEXT PRIMARY KEY,
                v TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS words (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                headword TEXT NOT NULL,
                is_common INTEGER NOT NULL DEFAULT 0,
                freq_rank INTEGER
            )',
            'CREATE TABLE IF NOT EXISTS entries (
                id INTEGER PRIMARY KEY,
                word_id INTEGER NOT NULL REFERENCES words(id),
                pos TEXT NOT NULL,
                etymology TEXT,
                source TEXT NOT NULL,
                etymology_number INTEGER NOT NULL DEFAULT 0,
                UNIQUE (word_id, pos, source, etymology_number)
            )',
            'CREATE TABLE IF NOT EXISTS senses (
                id INTEGER PRIMARY KEY,
                entry_id INTEGER NOT NULL REFERENCES entries(id),
                gloss TEXT NOT NULL,
                example TEXT,
                example_source TEXT,
                sense_order INTEGER NOT NULL,
                UNIQUE (entry_id, sense_order)
            )',
            'CREATE TABLE IF NOT EXISTS pronunciations (
                id INTEGER PRIMARY KEY,
                entry_id INTEGER NOT NULL REFERENCES entries(id),
                ipa TEXT,
                audio_url TEXT,
                source TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS relations (
                id INTEGER PRIMARY KEY,
                entry_id INTEGER NOT NULL REFERENCES entries(id),
                type TEXT NOT NULL CHECK (type IN (\'synonym\', \'antonym\', \'hypernym\', \'related\')),
                target TEXT NOT NULL,
                source TEXT NOT NULL,
                UNIQUE (entry_id, type, target)
            )',
            'CREATE INDEX IF NOT EXISTS idx_entries_word ON entries(word_id)',
            'CREATE INDEX IF NOT EXISTS idx_senses_entry ON senses(entry_id)',
            'CREATE INDEX IF NOT EXISTS idx_pron_entry ON pronunciations(entry_id)',
            'CREATE INDEX IF NOT EXISTS idx_rel_entry ON relations(entry_id, type)',
            "CREATE VIRTUAL TABLE IF NOT EXISTS glossary_fts USING fts5(
                headword,
                gloss,
                slug UNINDEXED,
                tokenize = 'unicode61 remove_diacritics 2'
            )",
        ];
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }
}

final class Meta
{
    private PDOStatement $getStmt;
    private PDOStatement $setStmt;

    public function __construct(private readonly PDO $pdo)
    {
        $this->getStmt = $pdo->prepare('SELECT v FROM meta WHERE k = ?');
        $this->setStmt = $pdo->prepare(
            'INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v'
        );
    }

    public function get(string $key): ?string
    {
        $this->getStmt->execute([$key]);
        $value = $this->getStmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $this->setStmt->execute([$key, $value]);
    }
}

final class Progress
{
    private float $started;

    public function __construct(
        private readonly string $label,
        private readonly int $total,
    ) {
        $this->started = microtime(true);
    }

    public function tick(int $done, string $extra = ''): void
    {
        $elapsed = max(0.001, microtime(true) - $this->started);
        $rate = $done / $elapsed;
        $left = ($this->total > $done && $rate > 0) ? (int) (($this->total - $done) / $rate) : 0;
        $pct = $this->total > 0 ? min(100, ($done / $this->total) * 100) : 0;
        $minutes = intdiv($left, 60);
        $seconds = $left % 60;
        fwrite(STDERR, sprintf(
            "\r%-12s %6.1f%%  %d  %.0f/s  ETA %02d:%02d  %s   ",
            $this->label,
            $pct,
            $done,
            $rate,
            $minutes,
            $seconds,
            $extra
        ));
    }

    public function finish(string $message): void
    {
        fwrite(STDERR, "\n");
        fwrite(STDOUT, $this->label . ': ' . $message . "\n");
    }
}
