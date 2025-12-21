<?php

use Phinx\Migration\AbstractMigration;

class CreateTradesTable extends AbstractMigration
{
    public function change()
    {
        $this->table('trades')
            ->addColumn('userid', 'integer', ['limit' => 100])
            ->addColumn('account', 'string', ['limit' => 100])
            ->addColumn('ref', 'string', ['limit' => 100])
            ->addColumn('pair', 'string', ['limit' => 100])
            ->addColumn('type', 'enum', [
                'values' => ['buy', 'sell'],
                'default' => null
            ])
            ->addColumn('trade_price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('margin', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('lot', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('leverage', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_REGULAR, 'default' => 1000])
            ->addColumn('stop_loss', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0'])
            ->addColumn('take_profit', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0'])
            ->addColumn('commission', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0'])
            ->addColumn('swap', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0'])
            ->addColumn('profit', 'string', ['limit' => 20], ['default' => null])
            ->addColumn('trade_acc', 'enum', [
                'values' => ['demo', 'real'],
                'default' => null
            ])
            ->addColumn('close_reason', 'enum', [
                'values' => ['stop_loss', 'take_profit', 'margin_call', 'stop_out', 'manual', 'expire'],
                'default' => null
            ])
            ->addColumn('date', 'datetime', ['precision' => 6])
            ->addColumn('close_date', 'datetime', ['precision' => 6])
            ->create();
    }
}
