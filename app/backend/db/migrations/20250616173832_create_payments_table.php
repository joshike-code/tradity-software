<?php

use Phinx\Migration\AbstractMigration;

class CreatePaymentsTable extends AbstractMigration
{
    public function change()
    {
        $this->table('payments')
            ->addColumn('user_id', 'integer', ['limit' => 100])
            ->addColumn('account', 'string', ['limit' => 100])
            ->addColumn('method', 'enum', ['values' => ['crypto', 'bank', 'referral', 'coinpayments', 'faucetpay', 'withdrawal', 'bot_trade']])
            ->addColumn('payment_id', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('tx_ref', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('order_ref', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('flw_ref', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('amount_settled', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addColumn('payment_type', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('address', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('coin', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('bank_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('account_number', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('type', 'enum', ['values' => ['credit', 'debit'], 'default' => 'credit'])
            ->addColumn('status', 'enum', ['values' => ['success', 'failed', 'pending', 'cancelled'], 'default' => 'pending'])
            ->addColumn('date', 'datetime', ['precision' => 6])
            ->create();
    }
}