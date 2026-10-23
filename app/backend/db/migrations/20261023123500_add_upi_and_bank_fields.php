<?php

use Phinx\Migration\AbstractMigration;

class AddUpiAndBankFields extends AbstractMigration
{
    public function change()
    {
        // 1. Update bank_accounts table
        $bankAccounts = $this->table('bank_accounts');
        $bankAccounts
            ->addColumn('ifsc_code', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => null,
                'after' => 'account_number'
            ])
            ->addColumn('branch', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'ifsc_code'
            ])
            ->update();

        // 2. Update pending_withdrawals table
        $pendingWithdrawals = $this->table('pending_withdrawals');
        $pendingWithdrawals
            ->addColumn('ifsc_code', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => null,
                'after' => 'account_number'
            ])
            ->addColumn('branch', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'ifsc_code'
            ])
            ->addColumn('upi_id', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'branch'
            ])
            ->update();

        // 3. Update payments table
        $payments = $this->table('payments');
        $payments
            ->addColumn('ifsc_code', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => null,
                'after' => 'account_number'
            ])
            ->addColumn('branch', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'ifsc_code'
            ])
            ->addColumn('upi_id', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'branch'
            ])
            ->update();
    }
}
