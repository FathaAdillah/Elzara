<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;

require __DIR__.'/../../apps/api/vendor/autoload.php';
$app = require __DIR__.'/../../apps/api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::table('users')->whereIn('email', ['isolation-operator@example.test', 'isolation-customer@example.test'])->delete();
$tester = new CommandTester(Artisan::all()['identity:admin:create']);
$tester->setInputs(['isolation-password', 'isolation-password']);
$status = $tester->execute(['email' => 'isolation-operator@example.test', 'name' => 'Isolation operator'], ['interactive' => true]);
if ($status !== 0) {
    throw new RuntimeException($tester->getDisplay());
}
echo "Operator fixture created via identity:admin:create.\n";
