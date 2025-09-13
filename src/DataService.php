<?php
declare(strict_types=1);

namespace App;

use PDO;

final class DataService
{
    private PDO $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function ensureSeed(): void
    {
        $brands  = ['Chery','Tank','Omoda','Haval'];
        $phrases = ['[бренд] купить','[бренд] где купить','[бренд] цена'];
        $regions = ['Архангельск','Северодвинск','Вологда','Череповец'];

        if (!$this->hasRows('brands')) {
            $st = $this->db->prepare('INSERT INTO brands(name) VALUES (:n)');
            foreach ($brands as $b) $st->execute([':n'=>$b]);
        }
        if (!$this->hasRows('phrases')) {
            $st = $this->db->prepare('INSERT INTO phrases(template) VALUES (:t)');
            foreach ($phrases as $p) $st->execute([':t'=>$p]);
        }
        if (!$this->hasRows('regions')) {
            $st = $this->db->prepare('INSERT INTO regions(name) VALUES (:n)');
            foreach ($regions as $r) $st->execute([':n'=>$r]);
        }
    }

    private function hasRows(string $table): bool
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() > 0;
    }

    public function getRegions(): array
    {
        return $this->db->query('SELECT id, name, yandex_id FROM regions ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBrands(): array
    {
        return $this->db->query('SELECT id, name FROM brands ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPhraseTemplates(): array
    {
        return $this->db->query('SELECT id, template FROM phrases ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveStat(string $region, string $brand, string $query, int $count): void
    {
        $sql = 'INSERT INTO stats(region, brand, query, query_count, created_at)
                VALUES (:region, :brand, :query, :cnt, NOW())';
        $st = $this->db->prepare($sql);
        $st->execute([
            ':region' => $region,
            ':brand'  => $brand,
            ':query'  => $query,
            ':cnt'    => $count, 
        ]);
    }

    public function clearStats(): void
    {
        $this->db->exec('DELETE FROM stats');
    }
}
