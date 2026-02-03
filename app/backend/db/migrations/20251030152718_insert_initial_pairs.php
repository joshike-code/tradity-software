<?php

use Phinx\Migration\AbstractMigration;

class InsertInitialPairs extends AbstractMigration
{
    public function up()
    {
        $pairs = require __DIR__ . '/../../data/pairs_data.php';
        $this->table('pairs')->insert($pairs)->saveData();
    }

    public function down()
    {
        $pairNames = array_column(require __DIR__ . '/../../data/pairs_data.php', 'name');
        $placeholders = implode(',', array_fill(0, count($pairNames), '?'));
        
        // Prepare delete query
        $sql = "DELETE FROM pairs WHERE name IN ($placeholders)";
        $this->execute($this->getAdapter()->quoteTableName($sql), $pairNames);
    }
}