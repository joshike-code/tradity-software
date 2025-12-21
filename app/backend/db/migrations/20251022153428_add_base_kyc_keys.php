<?php

use Phinx\Migration\AbstractMigration;

class AddBaseKycKeys extends AbstractMigration
{
    public function up()
    {
        $data = [
            ['key' => 'personal_details_isRequired', 'value' => 'true'],
            ['key' => 'trading_assessment_isRequired', 'value' => 'true'],
            ['key' => 'financial_assessment_isRequired', 'value' => 'true'],
            ['key' => 'identity_verification_isRequired', 'value' => 'true'],
            ['key' => 'income_verification_isRequired', 'value' => 'true'],
            ['key' => 'address_verification_isRequired', 'value' => 'true'],
            ['key' => 'trade_requiresKyc', 'value' => 'false'],
            ['key' => 'deposit_requiresKyc', 'value' => 'false'],
            ['key' => 'withdrawal_requiresKyc', 'value' => 'true'],
        ];

        foreach ($data as $entry) {
            $key = $entry['key'];
            $value = addslashes($entry['value']);

            $exists = $this->fetchRow("SELECT * FROM kyc_data WHERE `key` = '$key'");

            if (!$exists) {
                $this->execute("INSERT INTO kyc_data (`key`, `value`) VALUES ('$key', '$value')");
            }
        }
    }

    public function down($key)
    {
        $this->execute("DELETE FROM kyc_data WHERE `key` = '$key'");
    }
}