<?php

use Phinx\Migration\AbstractMigration;

class CreatePairsTable extends AbstractMigration
{
    public function change()
    {
        $this->table('pairs')
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('type', 'enum', [
                'values' => ['crypto', 'forex', 'stock', 'commodity', 'index'],
                'default' => null
            ])
            ->addColumn('pair1', 'string', ['limit' => 100])
            ->addColumn('pair2', 'string', ['limit' => 100])
            ->addColumn('digits', 'integer', ['limit' => 100])
            ->addColumn('lot_size', 'string', ['limit' => 100])
            ->addColumn('pip_price', 'string', ['limit' => 100])
            ->addColumn('pip_value', 'string', ['limit' => 100])
            ->addColumn('spread', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('min_volume', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('max_volume', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('margin_percent', 'integer', ['limit' => 100])
            ->addColumn('status', 'enum', [
                'values' => ['active', 'inactive'],
                'default' => 'active'
            ])
            ->addColumn('date_updated', 'datetime', ['precision' => 6])
            ->addIndex(['name'], ['unique' => true])
            ->create();
    }
}
