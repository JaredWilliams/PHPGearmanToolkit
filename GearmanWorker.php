<?php
	
	$worker = new \GearmanWorker();
	$worker->addServer();
	
	$worker->addFunction('time',
		function(\GearmanJob $job) 
		{
			return sprintf("%u: %s\n", posix_getpid(), gmdate('r'));
		});

	return $worker;
