<?php
declare(strict_types=1);
namespace EnOceanGatewayManager\Safety;

interface WriteJournal
{
    public function append(array $record): void;
    public function records(): array;
}
final class JournalPersistenceException extends \RuntimeException {}

/** Linux local-filesystem WAL: append, fflush, fsync(file), fsync(directory). */
final class DurableWriteJournal implements WriteJournal
{
    public function __construct(private readonly string $directory)
    {
        if (!function_exists('fsync')) { throw new JournalPersistenceException('Durable fsync unavailable.'); }
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true))) {
            throw new JournalPersistenceException('Unsafe journal directory.');
        }
        if (is_link($this->path())) { throw new JournalPersistenceException('Journal symlink rejected.'); }
        $parent = fopen(dirname($directory), 'r');
        if ($parent === false) { throw new JournalPersistenceException('Journal parent fsync unavailable.'); }
        try { if (!fsync($parent)) { throw new JournalPersistenceException('Journal directory creation not durable.'); } } finally { fclose($parent); }
    }
    private function path(): string { return $this->directory . '/transactions.ndjson'; }
    public function records(): array
    {
        if (!file_exists($this->path())) { return []; }
        $raw = file_get_contents($this->path());
        if ($raw === false || ($raw !== '' && !str_ends_with($raw, "\n"))) { throw new JournalPersistenceException('Torn journal: fail closed.'); }
        $records = []; $previous = str_repeat('0', 64);
        foreach (explode("\n", rtrim($raw, "\n")) as $line) {
            if ($line === '') { continue; }
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $hash = $record['hash'] ?? ''; unset($record['hash']);
            if (($record['previousHash'] ?? '') !== $previous || ($record['sequence'] ?? 0) !== count($records) + 1
                || !hash_equals(hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)), $hash)) {
                throw new JournalPersistenceException('Journal chain invalid: fail closed.');
            }
            $previous = $hash; $records[] = $record + ['hash' => $hash];
        }
        return $records;
    }
    public function append(array $record): void
    {
        $handle = fopen($this->path(), 'a+b');
        if ($handle === false || !flock($handle, LOCK_EX)) { throw new JournalPersistenceException('Journal lock failed.'); }
        try {
            chmod($this->path(), 0600);
            $all = $this->records();
            $record += ['sequence' => count($all) + 1, 'previousHash' => $all === [] ? str_repeat('0', 64) : $all[array_key_last($all)]['hash']];
            $record['hash'] = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
            $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle) || !fsync($handle)) { throw new JournalPersistenceException('Journal durability failed.'); }
            $dir = fopen($this->directory, 'r');
            if ($dir === false) { throw new JournalPersistenceException('Directory durability unavailable.'); }
            try { if (!fsync($dir)) { throw new JournalPersistenceException('Directory fsync failed.'); } } finally { fclose($dir); }
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
