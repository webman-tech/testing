<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('name', 'string', ['limit' => 64])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->create();
    }
}
