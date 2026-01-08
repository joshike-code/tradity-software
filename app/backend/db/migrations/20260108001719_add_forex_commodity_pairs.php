<?php

use Phinx\Migration\AbstractMigration;

class AddForexCommodityPairs extends AbstractMigration
{
    public function up()
    {
        $allPairs = require __DIR__ . '/../../data/pairs_data.php';
        
        // Get existing pair names from database
        $existingPairs = $this->fetchAll("SELECT name FROM pairs");
        $existingPairNames = array_column($existingPairs, 'name');
        
        // Filter out pairs that already exist
        $newPairs = [];
        foreach ($allPairs as $pair) {
            if (!in_array($pair['name'], $existingPairNames)) {
                $newPairs[] = $pair;
            }
        }
        
        if (empty($newPairs)) {
            echo "No new pairs to add. All pairs from pairs_data.php already exist.\n";
            return;
        }
        
        echo "Adding " . count($newPairs) . " new pairs:\n";
        foreach ($newPairs as $pair) {
            echo "  - {$pair['name']} ({$pair['type']})\n";
        }
        
        // Insert only new pairs
        $this->table('pairs')->insert($newPairs)->saveData();
        
        echo "Successfully added " . count($newPairs) . " new pairs.\n";
    }

    public function down()
    {
        // Get all pairs from pairs_data.php
        $allPairs = require __DIR__ . '/../../data/pairs_data.php';
        
        // Get the original pairs (the first 3 crypto pairs that were in the initial migration)
        $originalPairNames = ['BTC/USD', 'ETH/USD'];
        
        // Delete only pairs that are NOT in the original set
        $newPairNames = [];
        foreach ($allPairs as $pair) {
            if (!in_array($pair['name'], $originalPairNames)) {
                $newPairNames[] = $pair['name'];
            }
        }
        
        if (!empty($newPairNames)) {
            $placeholders = implode(',', array_fill(0, count($newPairNames), '?'));
            $sql = "DELETE FROM pairs WHERE name IN ($placeholders)";
            $this->execute($sql, $newPairNames);
            
            echo "Removed " . count($newPairNames) . " forex/commodity pairs.\n";
        }
    }
}
