<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

use PDO;

final class PlayProductCatalog
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<string,mixed>|null */
    public function find(string $appId, string $productId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM hekta_google_play_products WHERE app_id = ? AND product_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$appId, $productId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return string[] */
    public function packageNames(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT package_name FROM hekta_google_play_products WHERE is_active = 1');
        return $stmt ? array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)) : [];
    }
}
