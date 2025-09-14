<?php
declare(strict_types=1);

namespace App;

use PDO;
use Throwable;
use InvalidArgumentException;

final class DataService
{
    private PDO $db;

    private const TABLES = ['brands', 'phrases', 'regions', 'stats'];

    public function __construct(PDO $db) { $this->db = $db; }

    public function ensureSeed(): void
    {
        $brands  = ['Chery','Tank','Omoda','Haval'];
        $phrases = ['[бренд] купить','[бренд] где купить','[бренд] цена'];
        $regions = ['Архангельск','Северодвинск','Вологда','Череповец'];

        $this->withTransaction(function () use ($brands, $phrases, $regions): void {
            if (!$this->hasRows('brands')) {
                $st = $this->db->prepare('INSERT INTO brands(name) VALUES (:n)');
                foreach ($brands as $b) { $st->execute([':n' => $b]); }
            }
            if (!$this->hasRows('phrases')) {
                $st = $this->db->prepare('INSERT INTO phrases(template) VALUES (:t)');
                foreach ($phrases as $p) { $st->execute([':t' => $p]); }
            }
            if (!$this->hasRows('regions')) {
                $st = $this->db->prepare('INSERT INTO regions(name) VALUES (:n)');
                foreach ($regions as $r) { $st->execute([':n' => $r]); }
            }
        });
    }

    private function hasRows(string $table): bool
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException('Unknown table: ' . $table);
        }
        $sql = "SELECT 1 FROM {$table} LIMIT 1";
        $stmt = $this->db->query($sql);
        return (bool)$stmt->fetchColumn();
    }

    private function withTransaction(callable $fn): void
    {
        if ($this->db->inTransaction()) { 
            $fn();
            return;
        }
        $this->db->beginTransaction();
        try {
            $fn();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getRegions(): array
    {
        return $this->db
            ->query('SELECT id, name, yandex_id FROM regions ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getBrands(): array
    {
        return $this->db
            ->query('SELECT id, name FROM brands ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPhraseTemplates(): array
    {
        return $this->db
            ->query('SELECT id, template FROM phrases ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveStat(string $region, string $brand, string $query, int $count): void
    {
        $count = max(0, $count); 
        $sql = 'INSERT INTO stats (region, brand, query, query_count, created_at)
                VALUES (:region, :brand, :query, :cnt, NOW())';
        $st = $this->db->prepare($sql);
        $st->execute([
            ':region' => $region,
            ':brand'  => $brand,
            ':query'  => $query,
            ':cnt'    => $count,
        ]);

    }

    
    public function clearStats(bool $fast = false): void
    {
        if ($fast) {
            $this->db->exec('TRUNCATE TABLE stats');
        } else {
            $this->db->exec('DELETE FROM stats');
        }
    }
}
