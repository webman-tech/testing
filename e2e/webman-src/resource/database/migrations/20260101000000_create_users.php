<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class CreateUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('email', 'string', ['limit' => 128])
            ->addColumn('name', 'string', ['limit' => 64])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->create();
    }
}
