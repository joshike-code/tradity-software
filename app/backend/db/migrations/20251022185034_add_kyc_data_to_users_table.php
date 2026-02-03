<?php

use Phinx\Migration\AbstractMigration;

class AddKycDataToUsersTable extends AbstractMigration
{
    public function change()
    {
        $this->table('users')
            ->addColumn('personal_details_isRequired', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'true'
            ])
            ->addColumn('personal_details_isFilled', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'false'
            ])
            ->addColumn('trading_assessment_isRequired', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'true'
            ])
            ->addColumn('trading_assessment_isFilled', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'false'
            ])
            ->addColumn('financial_assessment_isRequired', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'true'
            ])
            ->addColumn('financial_assessment_isFilled', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'false'
            ])
            ->addColumn('identity_verification_isRequired', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'true'
            ])
            ->addColumn('identity_verification_isFilled', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'false'
            ])
            ->addColumn('income_verification_isRequired', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'true'
            ])
            ->addColumn('income_verification_isFilled', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'false'
            ])
            ->addColumn('address_verification_isRequired', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'true'
            ])
            ->addColumn('address_verification_isFilled', 'enum', [
                'values' => ['true', 'false'],
                'default' => 'false'
            ])
            ->update();
    }
}