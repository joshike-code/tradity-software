<?php

use Phinx\Migration\AbstractMigration;

class AddInvestToTransactionsTypeEnum extends AbstractMigration
{
    public function change()
    {
        $this->table('transactions')
            ->changeColumn('trx', 'enum', [
                'values' => ['buy', 'sell', 'deposit', 'withdraw', 'invest-in', 'invest-out'],
            ])
            ->update();
    }
}