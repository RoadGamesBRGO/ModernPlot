<?php
declare(strict_types=1);

namespace rgbrgo\ModernPlot\database;

use SQLite3;

class PlotDatabase {
    private SQLite3 $db;

    public function __construct() {
        $this->db = new SQLite3(\rgbrgo\ModernPlot\Main::getInstance()->getDataFolder() . "plots.db");
        
        // Cria a tabela inicial se não existir
        $this->db->exec("CREATE TABLE IF NOT EXISTS plots (x INTEGER, z INTEGER, owner TEXT, world TEXT)");

        // --- CORREÇÃO DO ERRO ---
        // Tenta adicionar a coluna 'friends' caso ela não exista no banco antigo
        $this->checkAndMigrate();
    }

    /**
     * Verifica se a coluna friends existe, se não existir, ela é criada.
     * Isso impede o erro "no such column: friends".
     */
    private function checkAndMigrate(): void {
        $result = $this->db->query("PRAGMA table_info(plots)");
        $hasFriends = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'friends') {
                $hasFriends = true;
                break;
            }
        }
        
        if (!$hasFriends) {
            $this->db->exec("ALTER TABLE plots ADD COLUMN friends TEXT DEFAULT ''");
        }
    }

    public function getOwner(int $x, int $z, string $world): ?string {
        $stmt = $this->db->prepare("SELECT owner FROM plots WHERE x = :x AND z = :z AND world = :world");
        $stmt->bindValue(":x", $x);
        $stmt->bindValue(":z", $z);
        $stmt->bindValue(":world", $world);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result ? $result["owner"] : null;
    }

    public function claimPlot(int $x, int $z, string $owner, string $world): void {
        $stmt = $this->db->prepare("INSERT INTO plots (x, z, owner, world, friends) VALUES (:x, :z, :owner, :world, '')");
        $stmt->bindValue(":x", $x);
        $stmt->bindValue(":z", $z);
        $stmt->bindValue(":owner", $owner);
        $stmt->bindValue(":world", $world);
        $stmt->execute();
    }

    public function sellPlot(int $x, int $z, string $world): void {
        $stmt = $this->db->prepare("DELETE FROM plots WHERE x = :x AND z = :z AND world = :world");
        $stmt->bindValue(":x", $x);
        $stmt->bindValue(":z", $z);
        $stmt->bindValue(":world", $world);
        $stmt->execute();
    }

    public function getFriends(int $x, int $z, string $world): array {
        $stmt = $this->db->prepare("SELECT friends FROM plots WHERE x = :x AND z = :z AND world = :world");
        $stmt->bindValue(":x", $x);
        $stmt->bindValue(":z", $z);
        $stmt->bindValue(":world", $world);
        $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if ($res && !empty($res["friends"])) {
            return explode(",", $res["friends"]);
        }
        return [];
    }

    public function setFriends(int $x, int $z, string $world, array $friends): void {
        $stmt = $this->db->prepare("UPDATE plots SET friends = :friends WHERE x = :x AND z = :z AND world = :world");
        $stmt->bindValue(":friends", implode(",", $friends));
        $stmt->bindValue(":x", $x);
        $stmt->bindValue(":z", $z);
        $stmt->bindValue(":world", $world);
        $stmt->execute();
    }
}