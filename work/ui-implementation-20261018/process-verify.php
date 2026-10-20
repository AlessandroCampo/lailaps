<?php
require '/home/alessandro/projects/lailaps-2/vendor/autoload.php';
$app = require '/home/alessandro/projects/lailaps-2/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Process;
use App\Services\DockerService;
Process::fake(['*' => Process::describe()->iterations(3)->output('simulated output')]);
$ticks = 0;
$output = '';
$result = app(DockerService::class)->run('fake-image', [], [], output: function ($type, $value) use (&$output) { $output .= $value; }, tick: function () use (&$ticks) { $ticks++; });
if (!$result->successful() || $ticks < 2 || trim($output) !== 'simulated output') throw new RuntimeException('Process monitor failed: '.json_encode([$result->successful(), $ticks, $output]));
echo "PASS asynchronous process monitor retains output and captures checkpoints\n";
Process::fake(['*' => Process::describe()->iterations(3)]);
try {
    app(DockerService::class)->run('fake-image', [], [], tick: fn () => throw new RuntimeException('simulated checkpoint error'));
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'simulated checkpoint error') throw $error;
}
Process::assertRan(fn ($process) => is_array($process->command) && array_slice($process->command, 0, 3) === ['docker', 'rm', '--force']);
echo "PASS unexpected monitor failure still removes container\n";
