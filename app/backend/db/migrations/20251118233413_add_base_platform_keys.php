<?php

use Phinx\Migration\AbstractMigration;

class AddBasePlatformKeys extends AbstractMigration
{
    public function up()
    {
        $data = [
            ['key' => 'default_leverage', 'value' => '1000'],
            ['key' => 'increment_rate', 'value' => '0.1'],
            ['key' => 'demo_account_balance', 'value' => '10000'],
        ];

        foreach ($data as $entry) {
            $key = $entry['key'];
            $value = addslashes($entry['value']);

            $exists = $this->fetchRow("SELECT * FROM platform WHERE `key` = '$key'");

            if (!$exists) {
                $this->execute("INSERT INTO platform (`key`, `value`) VALUES ('$key', '$value')");
            }
        }
    }

    public function down($key)
    {
        $this->execute("DELETE FROM platform WHERE `key` = '$key'");
    }
}