<?php

use Phinx\Migration\AbstractMigration;

class CreateTransactionsTable extends AbstractMigration
{
    public function change()
    {
        $this->table('transactions')
            ->addColumn('userid', 'integer', ['limit' => 100])
            ->addColumn('account', 'string', ['limit' => 100])
            ->addColumn('type', 'string', ['limit' => 100])
            ->addColumn('trx', 'enum', [
                'values' => ['buy', 'sell', 'deposit', 'withdraw'],
                'default' => null
            ])
            ->addColumn('ref', 'string', ['limit' => 100])
            ->addColumn('amount', 'string', ['limit' => 20], ['default' => null])
            ->addColumn('balance', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0'])
            ->addColumn('date', 'datetime', ['precision' => 6])
            ->create();
    }
}
