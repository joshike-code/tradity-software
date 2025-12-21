<?php

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

class CreateUsersTable extends AbstractMigration
{
    public function change()
    {
        $this->table('users')
            ->addColumn('role', 'enum', [
                'values' => ['user', 'admin', 'superadmin'],
                'default' => 'user'
            ])
            ->addColumn('permissions', 'json', ['null' => true])
            ->addColumn('avatar', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('fname', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('lname', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('email', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('phone', 'string', ['limit' => 25, 'null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 25, 'null' => true])
            ->addColumn('reg_country',  'string', [
                'limit' => 5,
                'default' => null
            ])
            ->addColumn('country',  'string', [
                'limit' => 5,
                'default' => null
            ])
            ->addColumn('account_currency', 'string', [
                'limit' => 5,
                'default' => 'USD'
            ])
            ->addColumn('dob', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('dob_place', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('gender', 'enum', [
                'values' => ['Male', 'Female', 'Other'],
                'default' => null
            ])
            ->addColumn('doc_identity_type', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('doc_identity', 'string', ['limit' => 250, 'null' => true])
            ->addColumn('doc_identity_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('doc_identity_country',  'string', [
                'limit' => 5,
                'default' => null
            ])
            ->addColumn('street', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('city', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('state', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('postal', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('doc_address_type', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('doc_address', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('doc_address_date', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('employer_name', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('business_name', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('doc_income_type', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('doc_income', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('promotional_email', 'enum', ['values' => ['true', 'false'], 'default' => 'false'])
            ->addColumn('trading_experience', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('trading_duration', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('trading_instrument', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('trading_frequency', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('trading_objective', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('trading_risk', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('tax_country', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('tax_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('employment_status', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('annual_income', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('income_source', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('net_worth', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('invest_amount', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('debt', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('pep', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('pep_relationship', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('pep_role', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('us_citizen', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('current_account', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('ref_code', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('referred_by', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('password', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('otp_2fa', 'enum', ['values' => ['yes', 'no'], 'default' => 'no'])
            ->addColumn('leverage', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_REGULAR, 'default' => 1000])
            ->addColumn('temp_email', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('status', 'enum', [
                'values' => ['active', 'suspended'],
                'default' => 'active'
            ])
            ->addColumn('date_registered', 'timestamp', [
                'default' => Literal::from('CURRENT_TIMESTAMP')
            ])
            ->addIndex(['email'], ['unique' => true])
            ->create();
    }
}
