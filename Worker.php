<?php

	include 'ProcessControl.php';

	set_time_limit(0);
	if (false == gc_enabled())
		gc_enable();

	$options = getopt('dhP:u:');
	if (isset($options['h']))
	{
		echo <<<'HELP'
-d            run as a daemon
-h            print this help and exit
-u <username> assume identity of <username> (only when run as root)

HELP;
		exit(0);
	}
	
	try
	{
		$manager = new Fork\Manager(
			function()
			{
				$children = new Fork\Children();

				$gearman = new Fork\Worker\PeclGearman(
					function()
					{
						return (include 'GearmanWorker.php');
					});

				// Run 5 gearmans each restarting after an hour
				$children->createProcesses(new Fork\Worker\ExitAfterDuration($gearman, 3600), 5);

				return $children;
			}
		);

		// Daemonize?
		if (isset($options['d']))
		{
			$daemon = new ProcessControl\Daemon();
			
			if (isset($options['u']))
				$daemon->runAs($options['u']);
			
			$daemon->run($manager);		
		}
		else
		{
			$manager();
		}
	}
	catch (\Exception $exception)
	{
		echo 'Exception occurred: ', $exception, "\n";
		exit(1);
	}
