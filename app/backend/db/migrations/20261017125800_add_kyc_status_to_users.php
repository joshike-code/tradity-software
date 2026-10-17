<?php

use Phinx\Migration\AbstractMigration;

class AddKycStatusToUsers extends AbstractMigration
{
    public function change()
    {
        $this->table('users')
            ->addColumn('kyc_status', 'enum', [
                'values' => ['unsubmitted', 'pending', 'approved', 'rejected'],
                'default' => 'unsubmitted'
            ])
            ->addColumn('kyc_reject_reason', 'text', [
                'null' => true,
                'default' => null
            ])
            ->update();
    }
}
