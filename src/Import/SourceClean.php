<?php

declare(strict_types=1);

/** Deletes rows from one named source so a phase can be repeated without duplicates. */
final class SourceClean
{
    public static function entries(PDO $pdo, string $source): void
    {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM relations WHERE source = ?')->execute([$source]);
        $pdo->prepare('DELETE FROM pronunciations WHERE source = ?')->execute([$source]);
        $pdo->prepare(
            'DELETE FROM senses WHERE entry_id IN (SELECT id FROM entries WHERE source = ?)'
        )->execute([$source]);
        $pdo->prepare('DELETE FROM entries WHERE source = ?')->execute([$source]);
        $pdo->exec('DELETE FROM words WHERE NOT EXISTS (SELECT 1 FROM entries e WHERE e.word_id = words.id)');
        $pdo->commit();
    }

    public static function relations(PDO $pdo, string $source): void
    {
        $pdo->prepare('DELETE FROM relations WHERE source = ?')->execute([$source]);
    }
}
