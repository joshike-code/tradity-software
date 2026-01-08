<?php

use Phinx\Migration\AbstractMigration;

class AddDecimalFormattingToPairs extends AbstractMigration
{
    public function up()
    {
        $table = $this->table('pairs');
        
        // Add column if it doesn't exist
        if (!$table->hasColumn('volume_decimals')) {
            $table->addColumn('volume_decimals', 'integer', [
                'default' => 2,
                'comment' => 'Number of decimal places for volume/lot size',
                'after' => 'digits'
            ]);
        }
        
        $table->save();
    }

    public function down()
    {
        $table = $this->table('pairs');
        
        if ($table->hasColumn('volume_decimals')) {
            $table->removeColumn('volume_decimals');
        }
        
        $table->save();
    }
}
