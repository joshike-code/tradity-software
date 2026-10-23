<?php

use Phinx\Migration\AbstractMigration;

class UpdatePaymentsMethodToString extends AbstractMigration
{
    public function up()
    {
        $this->execute("ALTER TABLE payments MODIFY COLUMN method VARCHAR(100) NOT NULL");
    }

    public function down()
    {
        $this->execute("ALTER TABLE payments MODIFY COLUMN method ENUM('crypto','bank','referral','coinpayments','faucetpay','withdrawal','bot_trade') DEFAULT NULL");
    }
}
