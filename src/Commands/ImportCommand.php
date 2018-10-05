<?php
namespace Xpressengine\Plugins\Importer\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Xpressengine\Plugins\Importer\Handler;

class ImportCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'importer:import 
                        {path : the path for exported file}
                        {--batch : if path is batch file(containing the list of exported files), use this flag. }
                        {--limit=10 : import size }
                        {--direct : if path is exporter link, use this option}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'import migration data to XE3';

    /**
     * @var Handler
     */
    protected $handler;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Handler $handler)
    {
        parent::__construct();
        $this->handler = $handler;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        \Artisan::call('cache:clear');
        \Artisan::call('cache:clear-xe');

        $file = $this->argument('path');

        // init
        $this->handler->init();

        $direct = $this->option('direct');

        if ($this->option('batch')) {
            $fileList = $this->handler->batch($file);
            $this->batch($fileList, $direct);
        } else {
            // prompt to user
            if ($this->input->isInteractive() && $this->confirm("Do you want to execute it?") === false) {
                $this->warn('Process is canceled by you.');
                return null;
            }
            $this->import($file, $direct);
        }
    }

    protected function batch($fileList, $direct)
    {
        $count = count($fileList);
        $this->output->writeln("The $count batch job will be executed.".PHP_EOL);

        // prompt to user
        if ($this->input->isInteractive() && $this->confirm("Do you want to execute it?") === false) {
            $this->warn('Process is canceled by you.');
            return null;
        }

        $successed = 0;
        $failed = 0;

        foreach ($fileList as $i => $file) {
            $this->output->writeln("[".($i+1)."/$count] executing batch job - $file".PHP_EOL);
            $counts = $this->import($file, $direct, true);
            $successed += $counts['successed'];
            $failed += $counts['failed'];
        }

        $this->output->writeln("");

        if ($failed > 0) {
            $logPath = $this->handler->getLogger()->getPath();
            $this->output->warning("$failed items ware not imported. show log file($logPath)");
        }

        $now = Carbon::now();
        $this->output->success("[{$now->format('Y.m.d H:i:s')}] $successed items ware imported.");

    }

    protected function import($file, $direct, $quiet = false) {

        $limit = $this->option('limit');

        // check
        $quiet ?: $this->output->writeln("[checking..]");
        $check = $this->handler->check($file, $direct);
        extract($check);
        $quiet ?: $this->output->writeln("Import type: $type".PHP_EOL);
        $quiet ?: $this->output->writeln("Revision: $revision".PHP_EOL);

        // prepare(make cache data)
        $quiet ?: $this->output->writeln("[preparing..]");
        $cachePath = $this->handler->prepare($type, $revision, $file);
        $quiet ?: $this->output->writeln("Data files were ready and saved in [$cachePath]".PHP_EOL);

        // preprocessing
        $quiet ?: $this->output->writeln("[preprocessing..]");
        $message = $this->handler->preprocessing($cachePath);
        $quiet ?: $this->output->writeln($message.PHP_EOL);

        // importing
        $start = 0;
        $this->handler->resetCount();
        $total = $this->handler->getImportSize($cachePath);
        $quiet ?: $this->output->writeln("[importing..] total: $total");

        $this->output->progressStart($total);
        do {
            $remained = $this->handler->import($cachePath, $start, $limit);
            $this->output->progressAdvance($total - $remained - $start);
            $start += $limit;
        } while ($remained);
        $this->output->progressFinish();

        $failed = $this->handler->getFailedCount();
        $updated = $this->handler->getUpdatedCount();
        $alreadyUpdated = $this->handler->getAlreadyUpdatedCount();

        // output result
        $now = Carbon::now();

        if ($failed > 0) {
            $logPath = $this->handler->getLogger()->getPath();
            $quiet ?: $this->output->warning("$failed items ware not imported. show log file($logPath)");
        }

        $successed = $total - $failed;
        $quiet ?: $this->output->success("[{$now->format('Y.m.d H:i:s')}] $successed items ware imported.");

        !$quiet ?: $this->output->writeln(
            "<fg=green>Successed: $successed (Updated: $updated, Already updated: $alreadyUpdated)</>".PHP_EOL
            .($failed === 0 ? "" : "<fg=red>failed: $failed</>")
        );

        return compact('successed', 'failed', 'updated', 'alreadyUpdated');
    }

}
